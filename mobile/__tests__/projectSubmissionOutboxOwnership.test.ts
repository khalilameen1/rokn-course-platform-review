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
});
