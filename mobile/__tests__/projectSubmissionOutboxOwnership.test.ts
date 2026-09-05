import AsyncStorage from '@react-native-async-storage/async-storage';

const mockPost = jest.fn();
const mockGet = jest.fn();
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
});
