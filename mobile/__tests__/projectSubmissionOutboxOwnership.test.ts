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

      await expect(retryPendingProjectSubmissions()).resolves.toEqual([
        {
          projectId: '42',
          submissionStatus: 'needs_changes',
          accepted: true,
          canContinue: false,
          reviewFeedback: reason,
        },
      ]);
      expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).toBe(
        firstKey,
      );
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

    mockPost.mockResolvedValueOnce(passedResponse);
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
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          id: submissionId,
          submission_status: 'evaluating',
          can_continue: false,
          poll_after_seconds: 1,
        },
      },
    });
    mockGet.mockResolvedValueOnce(passedResponse);

    await expect(retryPendingProjectSubmissions()).resolves.toEqual([
      {
        projectId: '42',
        submissionStatus: 'passed',
        accepted: true,
        canContinue: true,
      },
    ]);
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(mockPost.mock.calls[0][2].headers['Idempotency-Key']).toBe(
      pending.clientSubmissionId,
    );
    expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).toBe(
      pending.clientSubmissionId,
    );
    expect(mockGet).toHaveBeenCalledWith(
      `project-submissions/${submissionId}`,
      {timeout: 12000},
    );
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

      mockPost.mockResolvedValueOnce(accepted);
      mockGet.mockResolvedValueOnce(passedResponse);
      await expect(retryPendingProjectSubmissions()).resolves.toEqual([
        {
          projectId: '42',
          submissionStatus: 'passed',
          accepted: true,
          canContinue: true,
        },
      ]);
      expect(mockPost).toHaveBeenCalledTimes(failure === 'write' ? 2 : 1);
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
});
