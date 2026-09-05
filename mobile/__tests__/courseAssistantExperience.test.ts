jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

jest.mock('../src/services/productFeatures', () => ({
  isProductFeatureEnabled: jest.fn(),
}));

import fs from 'fs';
import path from 'path';

import {publicRequest} from '../src/constants/api';
import {isProductFeatureEnabled} from '../src/services/productFeatures';
import {
  askCourseAssistant,
  COURSE_CHAT_STATUS_TIMEOUT_MS,
  loadCourseAssistantHistory,
  pollCourseAssistantTurn,
} from '../src/components/VideoPlayer/courseLearning/assistant';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';

const course = {
  id: '52',
  accessType: 'paid',
  chatAvailable: true,
} as CourseLearningData;

describe('course assistant waiting experience', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('signals actual provider work and allows a considered response window', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {data: {message: 'إجابة مرتبطة بالكورس'}},
    });
    const onRequestStart = jest.fn();

    await expect(
      askCourseAssistant({course, message: 'اشرح الخطوة', onRequestStart}),
    ).resolves.toEqual(
      expect.objectContaining({
        text: 'إجابة مرتبطة بالكورس',
        offline: false,
        turnStatus: 'completed',
      }),
    );

    expect(onRequestStart).toHaveBeenCalledTimes(1);
    expect(publicRequest.post).toHaveBeenCalledWith(
      'courses/52/chat',
      expect.objectContaining({message: 'اشرح الخطوة'}),
      {timeout: 15_000},
    );
    expect(onRequestStart.mock.invocationCallOrder[0]).toBeLessThan(
      jest.mocked(publicRequest.post).mock.invocationCallOrder[0],
    );
  });

  it('does not trust client history as conversation context', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {data: {message: 'متابعة مفهومة'}},
    });

    await askCourseAssistant({
      course,
      message: 'وماذا بعد؟',
    });

    expect(publicRequest.post).toHaveBeenCalledWith(
      'courses/52/chat',
      expect.not.objectContaining({history: expect.anything()}),
      {timeout: 15_000},
    );
  });

  it('never turns an empty successful response into a completed answer', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);
    jest.mocked(publicRequest.post).mockResolvedValue({data: {data: {}}});

    await expect(
      askCourseAssistant({course, message: 'اشرح الخطوة'}),
    ).resolves.toEqual(
      expect.objectContaining({
        code: 'chat_response_invalid',
        turnStatus: 'failed',
        unavailable: true,
      }),
    );
  });

  it('applies the same server state contract to the initial send and later polls', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {
        code: 'chat_plan_limit_reached',
        data: {
          message: 'استخدمت الرسائل المتاحة',
          unavailable: true,
          turn_status: 'failed',
          can_retry: false,
        },
      },
    });

    await expect(
      askCourseAssistant({course, message: 'اشرح الخطوة'}),
    ).resolves.toMatchObject({
      blocked: true,
      code: 'chat_plan_limit_reached',
      turnStatus: 'failed',
      canRetry: false,
    });
  });

  it('never turns an empty completed status into a blank answer', async () => {
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        data: {
          client_request_id: 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
          turn_status: 'completed',
          message: '',
        },
      },
    });

    await expect(
      pollCourseAssistantTurn('b1644f1f-21ff-4a52-bfc3-cf98fd87a388'),
    ).resolves.toMatchObject({
      code: 'chat_response_invalid',
      turnStatus: 'failed',
      unavailable: true,
    });
  });

  it('uses the server retry decision and worker-aligned polling window', async () => {
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        code: 'ai_temporarily_unavailable',
        data: {
          client_request_id: 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
          turn_status: 'failed',
          message: 'حاول مرة أخرى',
          can_retry: true,
          retry_after_seconds: 30,
          poll_window_seconds: 95,
          unavailable: true,
        },
      },
    });

    await expect(
      pollCourseAssistantTurn('b1644f1f-21ff-4a52-bfc3-cf98fd87a388'),
    ).resolves.toMatchObject({
      code: 'ai_temporarily_unavailable',
      turnStatus: 'failed',
      canRetry: true,
      retryAfterSeconds: 30,
      pollWindowSeconds: 95,
    });
  });

  it('keeps a durable partial visible without offering an impossible retry', async () => {
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        data: {
          messages: [
            {
              id: 'assistant-1',
              role: 'assistant',
              text: 'ابدأ بالخطوة الأولى\n\nتوقف الرد قبل أن يكتمل',
              delivery_status: 'failed',
              error_code: 'chat_provider_outcome_unknown',
              can_retry: false,
              created_at: '2026-09-04T12:00:00Z',
            },
          ],
        },
      },
    });

    await expect(loadCourseAssistantHistory('52')).resolves.toEqual([
      expect.objectContaining({
        text: 'ابدأ بالخطوة الأولى\n\nتوقف الرد قبل أن يكتمل',
        deliveryStatus: 'failed',
        canRetry: false,
      }),
    ]);
  });

  it('keeps a failed streamed checkpoint visible in the active turn', async () => {
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        code: 'chat_provider_outcome_unknown',
        data: {
          client_request_id: 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
          turn_status: 'failed',
          message: 'ابدأ بالخطوة الأولى\n\nتوقف الرد قبل أن يكتمل',
          partial: true,
          unavailable: true,
          can_retry: false,
        },
      },
    });

    await expect(
      pollCourseAssistantTurn('b1644f1f-21ff-4a52-bfc3-cf98fd87a388'),
    ).resolves.toMatchObject({
      text: 'ابدأ بالخطوة الأولى\n\nتوقف الرد قبل أن يكتمل',
      turnStatus: 'failed',
      partial: true,
      canRetry: false,
    });
  });

  it('does not attach a response carrying another turn identity', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {
        data: {
          client_request_id: 'cc05e7b6-fcc4-4f5e-8dfb-30743d9c8fd9',
          message: 'رد يخص طلبًا آخر',
          turn_status: 'completed',
        },
      },
    });

    await expect(
      askCourseAssistant({
        course,
        clientRequestId: 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
        message: 'اشرح الخطوة',
      }),
    ).resolves.toMatchObject({
      clientRequestId: 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
      code: 'chat_answer_in_progress',
      turnStatus: 'queued',
    });
  });

  it('does not attach another turn while restoring an accepted request', async () => {
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        data: {
          client_request_id: 'cc05e7b6-fcc4-4f5e-8dfb-30743d9c8fd9',
          message: 'رد يخص طلبًا آخر',
          turn_status: 'completed',
        },
      },
    });

    await expect(
      pollCourseAssistantTurn('b1644f1f-21ff-4a52-bfc3-cf98fd87a388'),
    ).resolves.toMatchObject({
      clientRequestId: 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
      code: 'chat_answer_in_progress',
      turnStatus: 'queued',
    });
  });

  it('rejects fixture course ids before contacting the paid provider', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);

    await expect(
      askCourseAssistant({
        course: {...course, id: 'fixture-course'},
        message: 'اشرح الخطوة',
      }),
    ).rejects.toThrow('COURSE_ID_INVALID');
    expect(publicRequest.post).not.toHaveBeenCalled();
  });

  it('does not claim the assistant is typing when the feature is unavailable', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(false);
    const onRequestStart = jest.fn();

    const result = await askCourseAssistant({
      course,
      message: 'اشرح الخطوة',
      onRequestStart,
    });

    expect(result.unavailable).toBe(true);
    expect(result.code).toBe('ai_feature_unavailable');
    expect(onRequestStart).not.toHaveBeenCalled();
    expect(publicRequest.post).not.toHaveBeenCalled();
  });

  it('labels only server-started work as typing', () => {
    const conversation = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/conversation.ts',
      ),
      'utf8',
    );

    expect(conversation).toContain(
      'courseChatTurnIsActuallyStreaming(current.deliveryStatus)',
    );
    expect(conversation).not.toMatch(
      /const assistantPresence[\s\S]{0,180}sending\s*\|\|/,
    );
    expect(conversation).toContain("case 'submitting'");
    expect(conversation).toContain("case 'submitting'");
    expect(conversation).toContain("return 'connected'");
  });

  it('does not split the visible turn between pending and delivery status', () => {
    const hook = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/useCourseChat.ts',
      ),
      'utf8',
    );
    const types = fs.readFileSync(
      path.resolve(__dirname, '../src/components/VideoPlayer/types.ts'),
      'utf8',
    );
    const polling = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/turnPolling.ts',
      ),
      'utf8',
    );
    const state = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/turnState.ts',
      ),
      'utf8',
    );

    expect(hook).not.toMatch(/\bpending:\s*(true|false|acceptedPending)/);
    expect(types).not.toContain('pending?: boolean');
    expect(state).toContain("? ('interrupted' as const)");
    expect(state).toContain("? 'interrupted_turn'");
    expect(polling).toMatch(
      /let latestPartialText\s*=\s*response\.partial && response\.text\s*\? response\.text\s*:\s*'';/,
    );
  });

  it('does not multiply every turn status probe through the shared GET retry ladder', async () => {
    jest.mocked(publicRequest.get).mockRejectedValue({status: 503});

    await expect(
      pollCourseAssistantTurn('b1644f1f-21ff-4a52-bfc3-cf98fd87a388'),
    ).resolves.toEqual(
      expect.objectContaining({
        code: 'chat_answer_in_progress',
        turnStatus: 'queued',
      }),
    );
    expect(publicRequest.get).toHaveBeenCalledWith(
      'course-chat/turns/b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
      expect.objectContaining({
        timeout: COURSE_CHAT_STATUS_TIMEOUT_MS,
        roknNetworkRetryCount: Number.MAX_SAFE_INTEGER,
      }),
    );
  });

  it.each([401, 403, 422])(
    'does not poll a permanently rejected turn status after HTTP %s',
    async status => {
      jest.mocked(publicRequest.get).mockRejectedValue({status});

      await expect(
        pollCourseAssistantTurn(
          'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
        ),
      ).resolves.toMatchObject({
        offline: false,
        unavailable: true,
        turnStatus: 'failed',
        canRetry: false,
        code:
          status === 401
            ? 'chat_auth_required'
            : 'chat_status_request_rejected',
      });
    },
  );

  it('copies through an explicit action and freezes the reels behind overlays', () => {
    const overlay = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/CourseChatOverlay.tsx',
      ),
      'utf8',
    );
    const reels = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/ReelsSurface.tsx'),
      'utf8',
    );
    const controller = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsController.tsx'),
      'utf8',
    );

    expect(overlay).toContain('Clipboard.setString(text)');
    expect(overlay).toContain("ToastAndroid.show('تم النسخ'");
    const conversation = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/CourseChatConversation.tsx',
      ),
      'utf8',
    );
    expect(conversation).toContain('accessibilityLabel="نسخ الرسالة"');
    expect(overlay).toContain(
      "hardwareAccelerated={Platform.OS === 'android'}",
    );
    expect(reels).toContain('scrollEnabled={controller.scrollEnabled}');
    expect(controller).toContain('scrollEnabled: !interactionLocked');
  });
});
