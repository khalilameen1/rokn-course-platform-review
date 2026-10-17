import AsyncStorage from '@react-native-async-storage/async-storage';

const mockPost = jest.fn();
const mockGet = jest.fn();
const mockActiveBoundary = {epoch: 1, scope: 'user-a'};

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
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: jest.fn(async () => undefined),
}));
jest.mock('../src/services/aiConsent', () => ({
  requireAiConsent: jest.fn(async () => undefined),
  isAiConsentRequired: () => false,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(async () => undefined),
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
  submitProjectAttempt,
} from '../src/components/VideoPlayer/courseLearning/projectSubmissionOutbox';
import {
  projectUploadStatus,
  readProjectUploadProgress,
} from '../src/components/VideoPlayer/courseLearning/projectSubmissionProgress';

const passed = {data: {data: {submission_status: 'passed', can_continue: true}}};

describe('project submission upload: stall timeout and progress', () => {
  beforeEach(async () => {
    jest.useRealTimers();
    jest.clearAllMocks();
    await AsyncStorage.clear();
    quiesceProjectSubmissionRuntime();
  });
  afterEach(() => {
    jest.useRealTimers();
    quiesceProjectSubmissionRuntime();
  });

  it('sends one multipart request without a total deadline and publishes byte progress', async () => {
    const seen: Array<ReturnType<typeof readProjectUploadProgress>> = [];
    mockPost.mockImplementation(async (_url, _form, config) => {
      seen.push(readProjectUploadProgress('42'));
      config.onUploadProgress({loaded: 4_000_000, total: 16_000_000});
      seen.push(readProjectUploadProgress('42'));
      config.onUploadProgress({loaded: 16_000_000, total: 16_000_000});
      seen.push(readProjectUploadProgress('42'));
      return passed;
    });

    await expect(
      submitProjectAttempt('42', null, 'نفذت المشروع كاملا'),
    ).resolves.toMatchObject({submissionStatus: 'passed', accepted: true});

    const config = mockPost.mock.calls[0][2];
    expect(config.timeout).toBe(0);
    expect(config.signal).toBeDefined();
    expect(config.headers['Idempotency-Key']).toEqual(expect.any(String));
    expect(seen).toEqual([
      {loaded: 0, total: null},
      {loaded: 4_000_000, total: 16_000_000},
      {loaded: 16_000_000, total: 16_000_000},
    ]);
    expect(readProjectUploadProgress('42')).toBeNull();
  });

  it('aborts only a stalled upload and recovers it through the idempotent lookup', async () => {
    jest.useFakeTimers();
    let key = '';
    mockPost.mockImplementation(
      (_url, _form, config) =>
        new Promise((_resolve, reject) => {
          key = config.headers['Idempotency-Key'];
          config.onUploadProgress({loaded: 1, total: 10});
          config.signal.addEventListener('abort', () =>
            reject(Object.assign(new Error('canceled'), {code: 'ERR_CANCELED'})),
          );
        }),
    );
    mockGet.mockImplementation(async () => ({
      data: {
        data: {
          id: '33333333-3333-4333-8333-333333333333',
          project_id: 42,
          client_submission_id: key,
          submission_status: 'passed',
          can_continue: true,
        },
      },
    }));

    const attempt = submitProjectAttempt('42', null, 'نفذت المشروع كاملا');
    await jest.advanceTimersByTimeAsync(29_000);
    expect(mockPost.mock.calls[0][2].signal.aborted).toBe(false);
    await jest.advanceTimersByTimeAsync(2_000);
    expect(mockPost.mock.calls[0][2].signal.aborted).toBe(true);
    await expect(attempt).resolves.toMatchObject({submissionStatus: 'passed'});
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(readProjectUploadProgress('42')).toBeNull();
  });

  it('maps project bytes to the shared upload status presentation', () => {
    expect(projectUploadStatus({loaded: 1, total: 4})).toMatchObject({
      percentage: 25,
      phase: 'uploading',
    });
    expect(projectUploadStatus({loaded: 4, total: 4})).toMatchObject({
      phase: 'saving',
    });
    expect(projectUploadStatus({loaded: 0, total: null})).toMatchObject({
      percentage: null,
    });
  });
});
