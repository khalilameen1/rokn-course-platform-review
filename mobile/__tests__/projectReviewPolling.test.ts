const mockGet = jest.fn();
const mockPost = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'user-a',
    epoch: 1,
  })),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
}));
import {
  loadProjectResolution,
  watchProjectResolution,
} from '../src/components/VideoPlayer/courseLearning/projectRemote';

describe('accepted project review polling', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
  });
  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('receives a late server decision after thirty failed status reads without resubmitting', async () => {
    let reads = 0;
    mockGet.mockImplementation(async () => {
      reads += 1;
      if (reads <= 30) throw new Error('offline');
      return {
        data: {
          status: 200,
          success: true,
          data: {
            latest_submission: {
              id: 'submission-1',
              submission_status: 'needs_changes',
              can_submit: true,
              can_continue: false,
              feedback_level: 'pass_only',
              report_enabled: false,
              report_status: 'not_included',
              feedback: 'أضف صورة توضح ما نفذته',
            },
          },
        },
      };
    });
    const onResolution = jest.fn();
    const stop = watchProjectResolution({
      projectId: '7',
      resolve: loadProjectResolution,
      onResolution,
    });
    await jest.advanceTimersByTimeAsync(10 * 60_000);
    expect(mockGet).toHaveBeenCalledTimes(31);
    expect(onResolution).toHaveBeenCalledWith(
      expect.objectContaining({
        status: 'needs_changes',
        reviewFeedback: 'أضف صورة توضح ما نفذته',
      }),
    );
    expect(mockPost).not.toHaveBeenCalled();
    const count = mockGet.mock.calls.length;
    await jest.advanceTimersByTimeAsync(60_000);
    expect(mockGet).toHaveBeenCalledTimes(count);
    stop();
  });

  it('keeps malformed success payloads invalid and does not poll them endlessly', async () => {
    mockGet.mockResolvedValue({
      status: 200,
      data: {
        status: 200,
        success: true,
        data: {latest_submission: {id: 'submission-1'}},
      },
    });
    const onExhausted = jest.fn();
    const onResolution = jest.fn();
    const stop = watchProjectResolution({
      projectId: '7',
      resolve: loadProjectResolution,
      onResolution,
      onExhausted,
    });
    await jest.advanceTimersByTimeAsync(600_000);
    expect(mockGet).toHaveBeenCalledTimes(1);
    expect(onResolution).not.toHaveBeenCalled();
    expect(onExhausted).toHaveBeenCalledTimes(1);
    expect(mockPost).not.toHaveBeenCalled();
    stop();
  });

  it('stops reading when a saved submission cannot be reviewed without calling it rejected', async () => {
    mockGet.mockResolvedValue({
      data: {
        success: true,
        data: {
          latest_submission: {
            id: 'submission-1',
            submission_status: 'review_unavailable',
            can_continue: false,
            can_submit: false,
            report_status: 'not_included',
            can_retry_review: true,
            review_retry_endpoint:
              '/api/v1/project-submissions/11111111-1111-4111-8111-111111111111/review/retry',
            review_failure_category: 'provider_unavailable',
          },
        },
      },
    });
    const onResolution = jest.fn();
    const stop = watchProjectResolution({
      projectId: '7',
      resolve: loadProjectResolution,
      onResolution,
    });
    await jest.advanceTimersByTimeAsync(600_000);
    expect(mockGet).toHaveBeenCalledTimes(1);
    expect(onResolution).toHaveBeenCalledWith(
      expect.objectContaining({
        status: 'review_unavailable',
        canContinue: false,
        canSubmit: false,
        canRetryReview: true,
      }),
    );
    expect(mockPost).not.toHaveBeenCalled();
    stop();
  });

  it('does not run exhaustion or state callbacks when a pending read fails after leaving', async () => {
    let reject!: (error: Error) => void;
    const request = new Promise<null>((_resolve, rejectRequest) => {
      reject = rejectRequest;
    });
    const onExhausted = jest.fn();
    const onResolution = jest.fn();
    const stop = watchProjectResolution({
      projectId: '7',
      resolve: () => request,
      onResolution,
      onExhausted,
      maxAttempts: 1,
    });
    stop();
    reject(new Error('offline'));
    await jest.advanceTimersByTimeAsync(1);
    expect(onExhausted).not.toHaveBeenCalled();
    expect(onResolution).not.toHaveBeenCalled();
  });
});
