import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  retainLearnerDraftFiles,
  removeLearnerDraftFile,
} from '../src/services/learnerDraftFiles';

const mockPost = jest.fn();
const mockGet = jest.fn();
const mockReportClientError = jest.fn();
let mockActiveBoundary = {epoch: 1, scope: 'user-a'};

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    post: (...args: unknown[]) => mockPost(...args),
    get: (...args: unknown[]) => mockGet(...args),
  },
}));

jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    ...mockActiveBoundary,
  })),
  assertAccountSessionBoundary: (boundary: {epoch: number; scope: string}) => {
    if (
      boundary.epoch !== mockActiveBoundary.epoch ||
      boundary.scope !== mockActiveBoundary.scope
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));

jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: jest.fn(async () => undefined),
}));

jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: (...args: unknown[]) => mockReportClientError(...args),
}));

jest.mock('../src/config/projects', () => ({
  PROJECT_SUBMISSION_MAX_BYTES: 25 * 1024 * 1024,
  validateProjectFile: jest.fn(async (file: {size?: number}) => file.size || 1),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: jest.fn(async (_kind, file) => file),
  learnerDraftFileIsManaged: jest.fn(() => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));

import {
  quiesceProjectSubmissionRuntime,
  retryPendingProjectSubmissions,
  subscribeProjectSubmissionRecovery,
  submitProjectAttempt,
} from '../src/components/VideoPlayer/courseLearning/projectSubmissionOutbox';

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};

const settleMicrotasks = async (turns = 30) => {
  for (let index = 0; index < turns; index += 1) {
    await Promise.resolve();
  }
};

const passedResponse = {
  data: {
    data: {
      submission_status: 'passed',
      can_continue: true,
    },
  },
};

const lookupResponse = (
  clientSubmissionId: string,
  fields: Record<string, unknown> = {},
) => ({
  data: {
    data: {
      id: '33333333-3333-4333-8333-333333333333',
      project_id: 42,
      client_submission_id: clientSubmissionId,
      submission_status: 'passed',
      can_continue: true,
      ...fields,
    },
  },
});

describe('project submission outbox ownership', () => {
  beforeEach(async () => {
    jest.useRealTimers();
    jest.clearAllMocks();
    mockPost.mockReset();
    mockGet.mockReset();
    mockReportClientError.mockResolvedValue(undefined);
    await AsyncStorage.clear();
    mockActiveBoundary = {epoch: 1, scope: 'user-a'};
    quiesceProjectSubmissionRuntime();
  });

  afterEach(() => {
    jest.useRealTimers();
    quiesceProjectSubmissionRuntime();
  });

  it('coalesces two learner taps into one durable submission', async () => {
    const request = deferred<unknown>();
    mockPost.mockReturnValue(request.promise);

    const first = submitProjectAttempt('42', null, 'المشروع');
    const second = submitProjectAttempt('42', null, 'المشروع');
    await settleMicrotasks();

    expect(mockPost).toHaveBeenCalledTimes(1);
    request.resolve(passedResponse);
    await expect(Promise.all([first, second])).resolves.toEqual([
      {submissionStatus: 'passed', accepted: true, canContinue: true},
      {submissionStatus: 'passed', accepted: true, canContinue: true},
    ]);
  });

  it('keeps the server review reason when an attempt needs changes', async () => {
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          submission_status: 'needs_changes',
          can_continue: false,
          feedback: 'الصورة لا توضح النتيجة المطلوبة',
        },
      },
    });

    await expect(
      submitProjectAttempt('42', null, 'هذه محاولة واضحة'),
    ).resolves.toEqual({
      submissionStatus: 'needs_changes',
      accepted: true,
      canContinue: false,
      reviewFeedback: 'الصورة لا توضح النتيجة المطلوبة',
    });
    expect(
      (await AsyncStorage.getAllKeys()).some(key => key.includes(':user-a:42')),
    ).toBe(false);
  });

  it.each(['initial', 'status'])(
    'keeps review unavailable distinct from a rejected upload or project after %s response',
    async source => {
      const id = '11111111-1111-4111-8111-111111111111';
      const submission = {
        id,
        submission_status: 'review_unavailable',
        can_continue: false,
        can_retry_review: true,
        review_retry_endpoint: `/api/v1/project-submissions/${id}/review/retry`,
        review_failure_category: 'provider_unavailable',
      };
      mockPost.mockResolvedValueOnce({
        data: {
          success: true,
          data:
            source === 'initial'
              ? submission
              : {...submission, submission_status: 'evaluating'},
        },
      });
      if (source === 'status')
        mockGet.mockResolvedValueOnce({
          data: {success: true, data: submission},
        });
      await expect(
        submitProjectAttempt('42', null, 'هذه محاولة واضحة'),
      ).resolves.toEqual({
        submissionStatus: 'review_unavailable',
        accepted: true,
        canContinue: false,
        canRetryReview: true,
        reviewRetryEndpoint: submission.review_retry_endpoint,
        reviewFailureCategory: 'provider_unavailable',
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockGet).toHaveBeenCalledTimes(source === 'initial' ? 0 : 1);
      expect(await retryPendingProjectSubmissions()).toEqual([]);
      expect(mockPost).toHaveBeenCalledTimes(1);
    },
  );

  it.each([false, true])(
    'returns the first rejection reason even when terminal cleanup fails (diagnostics failure: %s)',
    async diagnosticsFail => {
      const reason = 'الصورة لا توضح النتيجة المطلوبة';
      mockPost.mockResolvedValue({
        data: {
          data: {
            submission_status: 'needs_changes',
            can_continue: false,
            feedback: reason,
          },
        },
      });
      jest
        .mocked(AsyncStorage.removeItem)
        .mockRejectedValueOnce(new Error('disk I/O failure'));
      if (diagnosticsFail)
        mockReportClientError.mockRejectedValueOnce(
          new Error('diagnostics unavailable'),
        );

      await expect(
        submitProjectAttempt('42', null, 'هذه محاولة واضحة'),
      ).resolves.toEqual({
        submissionStatus: 'needs_changes',
        accepted: true,
        canContinue: false,
        reviewFeedback: reason,
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      await settleMicrotasks();
      expect(mockReportClientError).toHaveBeenCalledTimes(1);
      const [diagnostic, context] = mockReportClientError.mock.calls[0];
      expect(diagnostic.message).toBe('PROJECT_SUBMISSION_TERMINAL_CLEANUP');
      expect(context).toEqual({source: 'project_submission_terminal_cleanup'});
      const firstKey = mockPost.mock.calls[0][2].headers['Idempotency-Key'];
      mockGet.mockResolvedValueOnce(
        lookupResponse(firstKey, {
          submission_status: 'needs_changes',
          can_continue: false,
          feedback: reason,
        }),
      );

      await expect(retryPendingProjectSubmissions()).resolves.toEqual([
        {
          projectId: '42',
          submissionStatus: 'needs_changes',
          accepted: true,
          canContinue: false,
          reviewFeedback: reason,
        },
      ]);
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(await retryPendingProjectSubmissions()).toEqual([]);
    },
  );

  it('still rejects an old-account result when ownership changes during failed cleanup', async () => {
    mockPost.mockResolvedValueOnce(passedResponse);
    jest.mocked(AsyncStorage.removeItem).mockImplementationOnce(async () => {
      mockActiveBoundary = {epoch: 2, scope: 'user-b'};
      throw new Error('disk I/O failure');
    });

    await expect(
      submitProjectAttempt('42', null, 'هذه محاولة واضحة'),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockReportClientError).not.toHaveBeenCalled();
  });

  it('keeps the UI attached to the real request and shares it with resume recovery', async () => {
    jest.useFakeTimers();
    const request = deferred<unknown>();
    mockPost.mockReturnValue(request.promise);

    const initial = submitProjectAttempt('42', null, 'المشروع');
    let initialSettled = false;
    void initial.finally(() => {
      initialSettled = true;
    });
    await settleMicrotasks();
    expect(mockPost).toHaveBeenCalledTimes(1);

    jest.advanceTimersByTime(15_000);
    await settleMicrotasks();
    expect(initialSettled).toBe(false);

    const recovery = retryPendingProjectSubmissions();
    const repeatedTap = submitProjectAttempt('42', null, 'المشروع');
    await settleMicrotasks();
    expect(mockPost).toHaveBeenCalledTimes(1);

    request.resolve(passedResponse);
    await settleMicrotasks();
    await expect(initial).resolves.toEqual({
      submissionStatus: 'passed',
      accepted: true,
      canContinue: true,
    });
    await expect(repeatedTap).resolves.toEqual({
      submissionStatus: 'passed',
      accepted: true,
      canContinue: true,
    });
    await expect(recovery).resolves.toEqual([
      {
        projectId: '42',
        submissionStatus: 'passed',
        accepted: true,
        canContinue: true,
      },
    ]);
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it('never applies an old account response to the next account', async () => {
    const oldRequest = deferred<unknown>();
    mockPost.mockReturnValueOnce(oldRequest.promise);

    const oldSubmission = submitProjectAttempt('42', null, 'قديم');
    await settleMicrotasks();
    mockActiveBoundary = {epoch: 2, scope: 'user-b'};
    oldRequest.resolve(passedResponse);
    await expect(oldSubmission).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );

    mockPost.mockResolvedValueOnce(passedResponse);
    await expect(submitProjectAttempt('42', null, 'جديد')).resolves.toEqual({
      submissionStatus: 'passed',
      accepted: true,
      canContinue: true,
    });
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(
      (await AsyncStorage.getAllKeys()).some(key => key.includes(':user-a:42')),
    ).toBe(true);
  });

  it('keeps a durable old-account outbox if the account changes during its write', async () => {
    const storageSet = AsyncStorage.setItem as jest.MockedFunction<
      typeof AsyncStorage.setItem
    >;
    const write = storageSet.getMockImplementation();
    expect(write).toBeDefined();
    storageSet.mockImplementationOnce(async (key, value) => {
      await write!(key, value);
      mockActiveBoundary = {epoch: 2, scope: 'user-b'};
    });

    await expect(
      submitProjectAttempt('42', null, 'يُستكمل عند العودة'),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');

    expect(mockPost).not.toHaveBeenCalled();
    const oldAccountKey = (await AsyncStorage.getAllKeys()).find(key =>
      key.includes(':user-a:42'),
    );
    expect(oldAccountKey).toBeDefined();
    expect(await AsyncStorage.getItem(oldAccountKey!)).toContain(
      'يُستكمل عند العودة',
    );
  });

  it('hands a recovered submission to the course owner even when it mounts after recovery', async () => {
    mockPost.mockRejectedValueOnce(new Error('offline'));
    await expect(
      submitProjectAttempt('42', null, 'محاولة محفوظة للعودة'),
    ).resolves.toEqual({
      submissionStatus: 'draft',
      accepted: false,
      canContinue: false,
    });

    mockGet.mockResolvedValueOnce(
      lookupResponse(mockPost.mock.calls[0][2].headers['Idempotency-Key']),
    );
    const ownerBoundary = {...mockActiveBoundary};
    const liveOwner = jest.fn();
    const stopLiveOwner = subscribeProjectSubmissionRecovery(
      liveOwner,
      ownerBoundary,
    );
    await expect(retryPendingProjectSubmissions()).resolves.toEqual([
      {
        projectId: '42',
        submissionStatus: 'passed',
        accepted: true,
        canContinue: true,
      },
    ]);
    expect(liveOwner).toHaveBeenLastCalledWith([
      expect.objectContaining({projectId: '42', submissionStatus: 'passed'}),
    ]);
    stopLiveOwner();

    const lateCourseOwner = jest.fn();
    const stopLateOwner = subscribeProjectSubmissionRecovery(
      lateCourseOwner,
      ownerBoundary,
    );
    expect(lateCourseOwner).toHaveBeenCalledTimes(1);
    expect(lateCourseOwner).toHaveBeenCalledWith([
      expect.objectContaining({projectId: '42', submissionStatus: 'passed'}),
    ]);
    stopLateOwner();

    const afterReplayWindow = Date.now() + 60_001;
    jest.spyOn(Date, 'now').mockReturnValue(afterReplayWindow);
    const staleOwner = jest.fn();
    subscribeProjectSubmissionRecovery(staleOwner, ownerBoundary)();
    expect(staleOwner).not.toHaveBeenCalled();
    jest.restoreAllMocks();

    const anotherAccount = jest.fn();
    subscribeProjectSubmissionRecovery(anotherAccount, {
      epoch: ownerBoundary.epoch + 1,
      scope: 'user-b',
    })();
    expect(anotherAccount).not.toHaveBeenCalled();
  });

  it('resumes the same durable submission when the upload response is lost', async () => {
    mockPost.mockRejectedValueOnce(new Error('connection closed'));
    await expect(
      submitProjectAttempt('42', null, 'محاولة وصلت ولم يصل ردها'),
    ).resolves.toEqual({
      submissionStatus: 'draft',
      accepted: false,
      canContinue: false,
    });

    const pendingKey = (await AsyncStorage.getAllKeys()).find(key =>
      key.includes(':user-a:42'),
    );
    expect(pendingKey).toBeDefined();
    const pending = JSON.parse((await AsyncStorage.getItem(pendingKey!))!);
    const submissionId = '33333333-3333-4333-8333-333333333333';
    mockGet.mockResolvedValueOnce(
      lookupResponse(pending.clientSubmissionId, {
        id: submissionId,
      }),
    );

    await expect(retryPendingProjectSubmissions()).resolves.toEqual([
      {
        projectId: '42',
        submissionStatus: 'passed',
        accepted: true,
        canContinue: true,
      },
    ]);
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockPost.mock.calls[0][2].headers['Idempotency-Key']).toBe(
      pending.clientSubmissionId,
    );
    expect(mockGet).toHaveBeenCalledWith(
      'projects/42/submissions/lookup',
      expect.objectContaining({
        params: {client_submission_id: pending.clientSubmissionId},
      }),
    );
    expect(
      mockGet.mock.calls.some(call =>
        call[0].startsWith('project-submissions/'),
      ),
    ).toBe(false);
    expect(await AsyncStorage.getItem(pendingKey!)).toBeNull();
  });

  it.each(['write', 'cleanup'])(
    'keeps polling an accepted upload when local acknowledgement %s fails',
    async failure => {
      const id = '33333333-3333-4333-8333-333333333333';
      const accepted = {
        data: {
          data: {id, submission_status: 'evaluating', can_continue: false},
        },
      };
      const file = {
        uri: 'file:///draft/work.jpg',
        name: 'work.jpg',
        type: 'image/jpeg',
        size: 100,
      };
      mockPost.mockImplementationOnce(async () => {
        if (failure === 'write') {
          jest
            .mocked(AsyncStorage.setItem)
            .mockRejectedValueOnce(new Error('disk full'));
        } else {
          // Releasing references after the committed write is already guarded;
          // the following file-cleanup release was not.
          jest
            .mocked(retainLearnerDraftFiles)
            .mockRejectedValueOnce(new Error('registry unavailable'))
            .mockRejectedValueOnce(new Error('registry unavailable'));
        }
        return accepted;
      });
      mockGet.mockRejectedValueOnce(new Error('temporary read outage'));

      await expect(submitProjectAttempt('42', file)).resolves.toEqual({
        submissionStatus: 'evaluating',
        accepted: true,
        canContinue: false,
      });
      expect(mockGet).toHaveBeenCalledWith(`project-submissions/${id}`, {
        timeout: 12000,
      });
      expect(removeLearnerDraftFile).not.toHaveBeenCalled();
      await settleMicrotasks();
      expect(mockReportClientError).toHaveBeenCalledWith(
        expect.objectContaining({
          message: 'PROJECT_SUBMISSION_ACKNOWLEDGEMENT',
        }),
        {source: 'project_submission_acknowledgement'},
      );
      const key = (await AsyncStorage.getAllKeys()).find(value =>
        value.includes(':user-a:42'),
      )!;
      const pending = JSON.parse((await AsyncStorage.getItem(key))!);
      expect(pending.clientSubmissionId).toBe(
        mockPost.mock.calls[0][2].headers['Idempotency-Key'],
      );
      if (failure === 'write') expect(pending.selectedFiles).toEqual([file]);
      else expect(pending.publicId).toBe(id);

      mockGet.mockResolvedValueOnce(
        failure === 'write'
          ? lookupResponse(pending.clientSubmissionId)
          : passedResponse,
      );
      await expect(retryPendingProjectSubmissions()).resolves.toEqual([
        {
          projectId: '42',
          submissionStatus: 'passed',
          accepted: true,
          canContinue: true,
        },
      ]);
      expect(mockPost).toHaveBeenCalledTimes(1);
      for (const call of mockPost.mock.calls) {
        expect(call[2].headers['Idempotency-Key']).toBe(
          pending.clientSubmissionId,
        );
      }
    },
  );

  it('never discards an uncertain submission merely because its outbox read fails', async () => {
    mockPost.mockRejectedValueOnce(new Error('response lost'));
    await submitProjectAttempt('42', null, 'محاولة محفوظة');
    const key = (await AsyncStorage.getAllKeys()).find(value =>
      value.includes(':user-a:42'),
    )!;
    const saved = await AsyncStorage.getItem(key);
    jest
      .mocked(AsyncStorage.getItem)
      .mockRejectedValueOnce(new Error('temporary disk failure'));

    await expect(
      submitProjectAttempt('42', null, 'محاولة محفوظة'),
    ).rejects.toThrow('temporary disk failure');
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(await AsyncStorage.getItem(key)).toBe(saved);
    expect(removeLearnerDraftFile).not.toHaveBeenCalled();

    mockPost.mockResolvedValueOnce(passedResponse);
    mockGet.mockRejectedValueOnce({status: 404});
    await submitProjectAttempt('42', null, 'محاولة محفوظة');
    expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).toBe(
      mockPost.mock.calls[0][2].headers['Idempotency-Key'],
    );
  });

  it('does not poll or adopt an accepted upload after an account change during acknowledgement', async () => {
    const write = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
    mockPost.mockImplementationOnce(async () => {
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementationOnce(async (key, value) => {
          await write(key, value);
          mockActiveBoundary = {epoch: 2, scope: 'user-b'};
        });
      return {
        data: {
          data: {
            id: '33333333-3333-4333-8333-333333333333',
            submission_status: 'evaluating',
            can_continue: false,
          },
        },
      };
    });

    await expect(
      submitProjectAttempt('42', null, 'محاولة محفوظة'),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockGet).not.toHaveBeenCalled();
    expect(mockReportClientError).not.toHaveBeenCalled();
    const key = (await AsyncStorage.getAllKeys()).find(value =>
      value.includes(':user-a:42'),
    )!;
    expect(JSON.parse((await AsyncStorage.getItem(key))!).publicId).toBe(
      '33333333-3333-4333-8333-333333333333',
    );
  });

  it.each(['response lost', 'server error', 'invalid acknowledgement'])(
    'reads the exact committed image attempt after %s without uploading again',
    async failure => {
      const file = {
        uri: 'file:///draft/private-work.jpg',
        name: 'private-work.jpg',
        type: 'image/jpeg',
        size: 149394,
      };
      if (failure === 'invalid acknowledgement') {
        mockPost.mockResolvedValueOnce({data: {data: {}}});
      } else {
        mockPost.mockRejectedValueOnce(
          failure === 'server error'
            ? {status: 500}
            : new Error('connection closed'),
        );
      }
      mockGet.mockImplementationOnce(async (route, config) => {
        expect(route).toBe('projects/42/submissions/lookup');
        return lookupResponse(config.params.client_submission_id, {
          submission_status: 'needs_changes',
          can_continue: false,
          feedback: 'أضف صورة قبل التنفيذ وبعده',
        });
      });

      await expect(submitProjectAttempt('42', file)).resolves.toMatchObject({
        accepted: true,
        submissionStatus: 'needs_changes',
        reviewFeedback: 'أضف صورة قبل التنفيذ وبعده',
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockGet).toHaveBeenCalledTimes(1);
      expect(await AsyncStorage.getAllKeys()).toEqual([]);
      await settleMicrotasks();
      const source =
        failure === 'invalid acknowledgement'
          ? 'invalid_ack'
          : 'unknown_upload';
      expect(mockReportClientError).toHaveBeenCalledWith(
        expect.objectContaining({
          message: `PROJECT_SUBMISSION_${source.toUpperCase()}`,
        }),
        {source: `project_submission_${source}`},
      );
      expect(JSON.stringify(mockReportClientError.mock.calls)).not.toContain(
        file.name,
      );
      expect(JSON.stringify(mockReportClientError.mock.calls)).not.toContain(
        file.uri,
      );
    },
  );

  it.each([
    {client_submission_id: 'older-attempt'},
    {project_id: 99},
    {id: 'not-a-public-id'},
  ])(
    'never adopts unrelated history or an invalid lookup identity: %j',
    async fields => {
      mockPost.mockRejectedValueOnce(new Error('response lost'));
      mockGet.mockImplementation(async (_route, config) =>
        lookupResponse(config.params.client_submission_id, fields),
      );
      await expect(
        submitProjectAttempt('42', null, 'محاولة جديدة'),
      ).resolves.toMatchObject({
        accepted: false,
        submissionStatus: 'draft',
      });
      await expect(retryPendingProjectSubmissions()).resolves.toEqual([
        expect.objectContaining({accepted: false, submissionStatus: 'draft'}),
      ]);
      expect(mockPost).toHaveBeenCalledTimes(1);
      const key = (await AsyncStorage.getAllKeys())[0];
      expect(
        JSON.parse((await AsyncStorage.getItem(key))!).publicId,
      ).toBeUndefined();
      expect(
        mockGet.mock.calls.every(
          call => call[0] === 'projects/42/submissions/lookup',
        ),
      ).toBe(true);
    },
  );

  it('does not replay an uncertain upload while identity lookup is unavailable', async () => {
    mockPost.mockRejectedValueOnce(new Error('response lost'));
    mockGet.mockRejectedValue(new Error('lookup unavailable'));
    await submitProjectAttempt('42', null, 'محاولة محفوظة');
    await submitProjectAttempt('42', null, 'محاولة محفوظة');
    await retryPendingProjectSubmissions();
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockGet).toHaveBeenCalledTimes(3);
    expect(await AsyncStorage.getAllKeys()).toHaveLength(1);

    // Only a confirmed absence permits another multipart dispatch, and it
    // retains the original idempotency key rather than creating a new review.
    mockGet.mockRejectedValueOnce({status: 404});
    mockPost.mockResolvedValueOnce(passedResponse);
    await expect(retryPendingProjectSubmissions()).resolves.toEqual([
      expect.objectContaining({accepted: true, submissionStatus: 'passed'}),
    ]);
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).toBe(
      mockPost.mock.calls[0][2].headers['Idempotency-Key'],
    );
  });

  it('recovers a legacy outbox by identity before any upload', async () => {
    mockPost.mockRejectedValueOnce(new Error('response lost'));
    await submitProjectAttempt('42', null, 'محاولة من النسخة السابقة');
    const key = (await AsyncStorage.getAllKeys())[0];
    const pending = JSON.parse((await AsyncStorage.getItem(key))!);
    delete pending.uploadAttempted;
    await AsyncStorage.setItem(key, JSON.stringify(pending));
    mockGet.mockResolvedValueOnce(lookupResponse(pending.clientSubmissionId));
    await expect(retryPendingProjectSubmissions()).resolves.toEqual([
      expect.objectContaining({accepted: true, submissionStatus: 'passed'}),
    ]);
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['found', false],
    ['unavailable', false],
    ['missing', false],
    ['account-changed', false],
    ['found', true],
    ['unavailable', true],
    ['missing', true],
  ] as const)(
    'reconciles an uncertain original receipt across publication (%s; admission closed: %s)',
    async (recovery, admissionClosed) => {
      jest.useFakeTimers();
      const file = {
        uri: 'file:///project.png',
        name: 'project.png',
        type: 'image/png',
        size: 10,
      };
      mockPost.mockRejectedValueOnce(
        new Error('original acknowledgement lost'),
      );
      mockGet.mockRejectedValueOnce(new Error('lookup unavailable'));
      await expect(
        submitProjectAttempt('42', file, 'المحاولة الأصلية'),
      ).resolves.toMatchObject({accepted: false});
      const storageKey = (await AsyncStorage.getAllKeys())[0];
      const original = JSON.parse((await AsyncStorage.getItem(storageKey))!);
      const revisionChanged = {
        status: 409,
        data: {
          code: 'course_revision_changed',
          data: {
            course_id: 7,
            ...(admissionClosed ? {submission_admission_closed: true} : {}),
          },
        },
      };
      // The read preceded the first POST's commit. Publication refuses the
      // replay, but does not prove that this earlier request was rejected.
      mockGet.mockRejectedValueOnce({status: 404});
      mockPost.mockRejectedValueOnce(revisionChanged);
      if (recovery === 'found') {
        mockGet.mockResolvedValueOnce(
          lookupResponse(original.clientSubmissionId),
        );
      } else if (recovery === 'account-changed') {
        mockGet.mockImplementationOnce(async () => {
          mockActiveBoundary = {scope: 'user-b', epoch: 2};
          return lookupResponse(original.clientSubmissionId);
        });
      } else {
        mockGet.mockRejectedValue(
          recovery === 'missing'
            ? {status: 404}
            : new Error('lookup unavailable'),
        );
      }
      const retried = submitProjectAttempt('42', file, 'المحاولة الأصلية');
      const result = retried.then(
        value => ({value}),
        error => ({error}),
      );
      await settleMicrotasks(100);
      await jest.advanceTimersByTimeAsync(2500);
      if (recovery === 'found') {
        expect(await result).toEqual({
          value: expect.objectContaining({
            accepted: true,
            submissionStatus: 'passed',
          }),
        });
        expect(await AsyncStorage.getItem(storageKey)).toBeNull();
      } else if (recovery === 'missing' && admissionClosed) {
        // Only the marked server rejection closes the admission window. Let
        // the editor handle its original revision target, never auto-send it.
        expect(await result).toEqual({error: revisionChanged});
        expect(await AsyncStorage.getItem(storageKey)).toBeNull();
        expect(await retryPendingProjectSubmissions()).toEqual([]);
      } else {
        expect(await result).toEqual({
          error: expect.objectContaining({
            message:
              recovery === 'account-changed'
                ? 'ACCOUNT_CHANGED_DURING_REQUEST'
                : 'PROJECT_SUBMISSION_PREVIOUS_ATTEMPT_PENDING',
          }),
        });
        expect(
          JSON.parse((await AsyncStorage.getItem(storageKey))!),
        ).toMatchObject({
          clientSubmissionId: original.clientSubmissionId,
          selectedFiles: [file],
        });
        expect(removeLearnerDraftFile).not.toHaveBeenCalled();
        // A later read can still find the original receipt, without creating
        // a new submission or transferring its key to the replacement project.
        if (recovery !== 'account-changed') {
          mockGet.mockResolvedValueOnce(
            lookupResponse(original.clientSubmissionId),
          );
          await expect(retryPendingProjectSubmissions()).resolves.toEqual([
            expect.objectContaining({
              projectId: '42',
              accepted: true,
              submissionStatus: 'passed',
            }),
          ]);
        }
      }
      expect(mockPost).toHaveBeenCalledTimes(2);
      expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).toBe(
        original.clientSubmissionId,
      );
      expect(
        mockGet.mock.calls.every(
          call => call[0] === 'projects/42/submissions/lookup',
        ),
      ).toBe(true);
      expect(
        mockGet.mock.calls.every(
          call =>
            call[1].params.client_submission_id === original.clientSubmissionId,
        ),
      ).toBe(true);
    },
  );

  it('keeps a first-upload revision rejection definitive when no earlier receipt is uncertain', async () => {
    const rejection = {status: 409, data: {code: 'course_revision_changed'}};
    mockPost.mockRejectedValueOnce(rejection);
    await expect(
      submitProjectAttempt('42', null, 'لم تُرسل من قبل'),
    ).rejects.toBe(rejection);
    expect(mockGet).not.toHaveBeenCalled();
    expect(await AsyncStorage.getAllKeys()).toHaveLength(0);
  });

  it('does not adopt or clean up the old account after an identity lookup changes owner', async () => {
    mockPost.mockRejectedValueOnce(new Error('response lost'));
    mockGet.mockImplementationOnce(async (_route, config) => {
      mockActiveBoundary = {epoch: 2, scope: 'user-b'};
      return lookupResponse(config.params.client_submission_id);
    });
    await expect(submitProjectAttempt('42', null, 'عمل خاص')).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(mockPost).toHaveBeenCalledTimes(1);
    const key = (await AsyncStorage.getAllKeys())[0];
    expect(key).toContain(':user-a:42');
    expect(
      JSON.parse((await AsyncStorage.getItem(key))!).publicId,
    ).toBeUndefined();
    expect(removeLearnerDraftFile).not.toHaveBeenCalled();
  });

  it('waits briefly for a committed identity after the first lookup returns 404', async () => {
    jest.useFakeTimers();
    mockPost.mockRejectedValueOnce(new Error('response lost before commit'));
    mockGet.mockRejectedValueOnce({status: 404});
    mockGet.mockImplementationOnce(async (_route, config) =>
      lookupResponse(config.params.client_submission_id),
    );
    const flight = submitProjectAttempt('42', null, 'محاولة قيد الحفظ');
    await settleMicrotasks(100);
    expect(mockGet).toHaveBeenCalledTimes(1);
    await jest.advanceTimersByTimeAsync(1000);
    await expect(flight).resolves.toMatchObject({
      accepted: true,
      submissionStatus: 'passed',
    });
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(mockGet.mock.calls[1][1].timeout).toBeLessThanOrEqual(11000);
  });

  it.each(['unavailable', 'evaluating'])(
    'does not replace an uncertain attempt with edited input while the old identity is %s',
    async state => {
      mockPost.mockRejectedValueOnce(new Error('lost acknowledgement'));
      await submitProjectAttempt('42', null, 'المحاولة الأولى');
      const key = (await AsyncStorage.getAllKeys())[0];
      const saved = JSON.parse((await AsyncStorage.getItem(key))!);
      if (state === 'unavailable')
        mockGet.mockRejectedValueOnce(new Error('read unavailable'));
      else
        mockGet.mockResolvedValueOnce(
          lookupResponse(saved.clientSubmissionId, {
            submission_status: 'evaluating',
            can_continue: false,
          }),
        );
      await expect(
        submitProjectAttempt('42', null, 'تعديلات جديدة يجب ألا تضيع'),
      ).rejects.toThrow('PROJECT_SUBMISSION_PREVIOUS_ATTEMPT_PENDING');
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(
        JSON.parse((await AsyncStorage.getItem(key))!).clientSubmissionId,
      ).toBe(saved.clientSubmissionId);
    },
  );

  it.each(['missing', 'needs_changes'])(
    'allows edited input only after the previous identity is confirmed %s',
    async state => {
      mockPost.mockRejectedValueOnce(new Error('lost acknowledgement'));
      await submitProjectAttempt('42', null, 'المحاولة الأولى');
      const oldKey = mockPost.mock.calls[0][2].headers['Idempotency-Key'];
      if (state === 'missing') mockGet.mockRejectedValueOnce({status: 404});
      else
        mockGet.mockResolvedValueOnce(
          lookupResponse(oldKey, {
            submission_status: 'needs_changes',
            can_continue: false,
          }),
        );
      mockPost.mockResolvedValueOnce(passedResponse);
      await expect(
        submitProjectAttempt('42', null, 'تعديلات جديدة'),
      ).resolves.toMatchObject({accepted: true});
      expect(mockPost).toHaveBeenCalledTimes(2);
      expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).not.toBe(
        oldKey,
      );
      expect(mockGet.mock.calls[1][1].params.client_submission_id).toBe(oldKey);
    },
  );

  it('adopts a previous pass without uploading or claiming the edited draft', async () => {
    mockPost.mockRejectedValueOnce(new Error('lost acknowledgement'));
    await submitProjectAttempt('42', null, 'المحاولة الأولى');
    const oldKey = mockPost.mock.calls[0][2].headers['Idempotency-Key'];
    mockGet.mockResolvedValueOnce(lookupResponse(oldKey));
    const file = {
      uri: 'file:///new-draft.jpg',
      name: 'new.jpg',
      type: 'image/jpeg',
      size: 100,
    };
    await expect(
      submitProjectAttempt('42', file, 'تعديلات جديدة'),
    ).resolves.toMatchObject({
      accepted: true,
      submissionStatus: 'passed',
      preserveDraft: true,
    });
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(file);
  });

  it.each([false, true])(
    'honors the 429 Retry-After cooldown across taps and restart (date header: %s)',
    async dateHeader => {
      jest.useFakeTimers();
      const retryAfter = dateHeader
        ? new Date(Date.now() + 60000).toUTCString()
        : '60';
      mockPost.mockRejectedValueOnce({
        status: 429,
        headers: {'retry-after': retryAfter},
      });
      await expect(
        submitProjectAttempt('42', null, 'المشروع'),
      ).rejects.toMatchObject({
        message: 'PROJECT_SUBMISSION_RATE_LIMITED',
        status: 429,
        retryAfterSeconds: 60,
      });
      expect(mockGet).not.toHaveBeenCalled();
      expect(mockReportClientError).not.toHaveBeenCalled();
      quiesceProjectSubmissionRuntime();
      await expect(
        submitProjectAttempt('42', null, 'تعديل لا يتجاوز الحظر'),
      ).rejects.toMatchObject({status: 429});
      await retryPendingProjectSubmissions();
      await jest.advanceTimersByTimeAsync(59000);
      await expect(
        submitProjectAttempt('42', null, 'المشروع'),
      ).rejects.toMatchObject({status: 429, retryAfterSeconds: 1});
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockGet).not.toHaveBeenCalled();
      await jest.advanceTimersByTimeAsync(1000);
      mockGet.mockRejectedValueOnce({status: 404});
      mockPost.mockResolvedValueOnce(passedResponse);
      await expect(
        submitProjectAttempt('42', null, 'المشروع'),
      ).resolves.toMatchObject({accepted: true});
      expect(mockPost).toHaveBeenCalledTimes(2);
      expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).toBe(
        mockPost.mock.calls[0][2].headers['Idempotency-Key'],
      );
    },
  );
});
