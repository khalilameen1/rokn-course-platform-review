jest.mock('../src/components/VideoPlayer/courseLearning/assistant', () => ({
  pollCourseAssistantTurn: jest.fn(),
}));

import {pollCourseAssistantTurn} from '../src/components/VideoPlayer/courseLearning/assistant';
import {
  COURSE_CHAT_DEFAULT_POLL_WINDOW_MS,
  COURSE_CHAT_MAX_POLL_WINDOW_MS,
  pollAcceptedCourseChatTurn,
} from '../src/components/VideoPlayer/courseChat/turnPolling';

const requestId = 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388';

describe('course chat foreground polling', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('keeps one accepted turn alive beyond the former ten-probe cutoff', async () => {
    jest
      .mocked(pollCourseAssistantTurn)
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'الإجابة وصلت',
        offline: false,
        turnStatus: 'completed',
      });

    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      initialResponse: {
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
        pollWindowSeconds: 95,
      },
      isActive: () => true,
      onPartial: jest.fn(),
      onStatus: jest.fn(),
    });

    await jest.runAllTimersAsync();

    await expect(result).resolves.toEqual({
      foregroundWaitExpired: false,
      response: expect.objectContaining({
        text: 'الإجابة وصلت',
        turnStatus: 'completed',
      }),
    });
    expect(pollCourseAssistantTurn).toHaveBeenCalledTimes(11);
  });

  it('suspends without manufacturing a terminal answer when the app leaves foreground', async () => {
    let active = true;
    jest.mocked(pollCourseAssistantTurn).mockImplementation(async () => {
      active = false;
      return {
        text: 'جزء من الرد',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'streaming',
        partial: true,
      };
    });

    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      initialResponse: {
        text: 'نجهز إجابتك الآن',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 1,
      },
      isActive: () => active,
      onPartial: jest.fn(),
      onStatus: jest.fn(),
    });
    await jest.runAllTimersAsync();

    await expect(result).resolves.toEqual({
      foregroundWaitExpired: false,
      response: expect.objectContaining({
        code: 'chat_answer_in_progress',
        turnStatus: 'streaming',
      }),
    });
  });

  it('bounds a server-requested foreground wait without abandoning the accepted id', async () => {
    jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
      text: '',
      offline: false,
      code: 'chat_answer_in_progress',
      turnStatus: 'queued',
      retryAfterSeconds: 5,
    });
    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      initialResponse: {
        text: '',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 5,
        pollWindowSeconds: 120,
      },
      isActive: () => true,
      onPartial: jest.fn(),
      onStatus: jest.fn(),
    });

    await jest.advanceTimersByTimeAsync(COURSE_CHAT_MAX_POLL_WINDOW_MS + 1000);

    await expect(result).resolves.toEqual({
      foregroundWaitExpired: true,
      response: expect.objectContaining({
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
      }),
    });
    expect(pollCourseAssistantTurn).toHaveBeenCalled();
    expect(
      jest.mocked(pollCourseAssistantTurn).mock.calls.length,
    ).toBeLessThanOrEqual(36);
  });

  it('renders only forward partial progress so a stale probe cannot shrink the answer', async () => {
    jest
      .mocked(pollCourseAssistantTurn)
      .mockResolvedValueOnce({
        text: 'مرحبا',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'streaming',
        partial: true,
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'مرحبا بك اليوم',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'streaming',
        partial: true,
        retryAfterSeconds: 1,
      })
      .mockResolvedValueOnce({
        text: 'مرحبا بك اليوم',
        offline: false,
        turnStatus: 'completed',
      });
    const onPartial = jest.fn();
    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      initialResponse: {
        text: 'مرحبا بك',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'streaming',
        partial: true,
        retryAfterSeconds: 1,
      },
      isActive: () => true,
      onPartial,
      onStatus: jest.fn(),
    });

    await jest.runAllTimersAsync();
    await expect(result).resolves.toEqual({
      foregroundWaitExpired: false,
      response: expect.objectContaining({turnStatus: 'completed'}),
    });
    expect(onPartial).toHaveBeenCalledTimes(1);
    expect(onPartial).toHaveBeenCalledWith('مرحبا بك اليوم');
  });

  it('receives a healthy answer after 45 seconds within the server window', async () => {
    const startedAt = Date.now();
    jest.mocked(pollCourseAssistantTurn).mockImplementation(async () => {
      const elapsed = Date.now() - startedAt;
      return elapsed >= 55_000
        ? {text: 'الإجابة مكتملة', offline: false, turnStatus: 'completed'}
        : {
            text: `جزء متزايد من الإجابة ${'أ'.repeat(Math.floor(elapsed / 1000))}`,
            offline: false,
            code: 'chat_answer_in_progress',
            turnStatus: 'streaming',
            partial: true,
            retryAfterSeconds: 1,
          };
    });
    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      initialResponse: {
        text: '',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        pollWindowSeconds: 95,
        retryAfterSeconds: 1,
      },
      isActive: () => true,
      onPartial: jest.fn(),
      onStatus: jest.fn(),
    });
    await jest.runAllTimersAsync();
    await expect(result).resolves.toEqual({
      foregroundWaitExpired: false,
      response: expect.objectContaining({
        turnStatus: 'completed',
        text: 'الإجابة مكتملة',
      }),
    });
  });

  it('never probes sooner than the server retry interval', async () => {
    jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
      text: 'الإجابة مكتملة',
      offline: false,
      turnStatus: 'completed',
    });
    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      initialResponse: {
        text: '',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 5,
      },
      isActive: () => true,
      onPartial: jest.fn(),
      onStatus: jest.fn(),
    });
    await jest.advanceTimersByTimeAsync(4999);
    expect(pollCourseAssistantTurn).not.toHaveBeenCalled();
    await jest.runAllTimersAsync();
    await expect(result).resolves.toEqual({
      foregroundWaitExpired: false,
      response: expect.objectContaining({turnStatus: 'completed'}),
    });
  });

  it('adopts the server window after the send response was lost', async () => {
    const startedAt = Date.now();
    await jest.advanceTimersByTimeAsync(15_000);
    jest.mocked(pollCourseAssistantTurn).mockImplementation(async () =>
      Date.now() - startedAt >= 55_000
        ? {text: 'الإجابة المستعادة', offline: false, turnStatus: 'completed'}
        : {
            text: '',
            offline: false,
            code: 'chat_answer_in_progress',
            turnStatus: 'queued',
            pollWindowSeconds: 95,
            retryAfterSeconds: 3,
          },
    );
    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      attemptStartedAt: startedAt,
      initialResponse: {
        text: '',
        offline: true,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        retryAfterSeconds: 2,
      },
      isActive: () => true,
      onPartial: jest.fn(),
      onStatus: jest.fn(),
    });
    await jest.runAllTimersAsync();
    await expect(result).resolves.toEqual({
      foregroundWaitExpired: false,
      response: expect.objectContaining({turnStatus: 'completed'}),
    });
    expect(
      jest.mocked(pollCourseAssistantTurn).mock.calls.every(
        ([id]) => id === requestId,
      ),
    ).toBe(true);
  });

  it.each([95, 110])(
    'counts the send timeout inside the %s-second foreground window',
    async pollWindowSeconds => {
      const attemptStartedAt = Date.now();
      await jest.advanceTimersByTimeAsync(15_000);
      jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
        text: '',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        pollWindowSeconds,
        retryAfterSeconds: 3,
      });
      const result = pollAcceptedCourseChatTurn({
        clientRequestId: requestId,
        attemptStartedAt,
        initialResponse: {
          text: '',
          offline: true,
          code: 'chat_answer_in_progress',
          turnStatus: 'queued',
          retryAfterSeconds: 2,
        },
        isActive: () => true,
        onPartial: jest.fn(),
        onStatus: jest.fn(),
      });
      await jest.runAllTimersAsync();
      expect(Date.now() - attemptStartedAt).toBe(pollWindowSeconds * 1000);
      await expect(result).resolves.toEqual({
        foregroundWaitExpired: true,
        response: expect.objectContaining({code: 'chat_answer_in_progress'}),
      });
    },
  );

  it('keeps an unreachable origin bounded even with a longer provider window', async () => {
    jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
      text: '',
      offline: true,
      code: 'chat_answer_in_progress',
      turnStatus: 'queued',
      retryAfterSeconds: 5,
    });
    const result = pollAcceptedCourseChatTurn({
      clientRequestId: requestId,
      initialResponse: {
        text: '',
        offline: false,
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
        pollWindowSeconds: 95,
        retryAfterSeconds: 1,
      },
      isActive: () => true,
      onPartial: jest.fn(),
      onStatus: jest.fn(),
    });
    await jest.advanceTimersByTimeAsync(COURSE_CHAT_DEFAULT_POLL_WINDOW_MS + 1);
    await expect(result).resolves.toEqual({
      foregroundWaitExpired: true,
      response: expect.objectContaining({offline: true}),
    });
  });
});
