import {
  classifyCourseChatResponse,
  queueCourseChatTurn,
  settleCourseChatTurn,
} from '../src/components/VideoPlayer/courseChat/turnState';
import type {ChatMessage} from '../src/components/VideoPlayer/types';

const requestId = 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388';

describe('course chat turn state', () => {
  it('repairs a missing assistant bubble without duplicating the user question', () => {
    const user: ChatMessage = {
      id: `user-${requestId}`,
      role: 'user',
      text: 'اشرح هذا الجزء',
      createdAt: 1,
      clientRequestId: requestId,
      deliveryStatus: 'failed',
    };

    const queued = queueCourseChatTurn({
      attachments: [],
      clientRequestId: requestId,
      messages: [user],
      retrying: true,
      text: user.text,
    });

    expect(queued.messages.filter(item => item.role === 'user')).toHaveLength(
      1,
    );
    expect(
      queued.messages.filter(item => item.role === 'assistant'),
    ).toHaveLength(1);
    expect(queued.messages[0].deliveryStatus).toBe('sent');
    expect(queued.messages[1].deliveryStatus).toBe('checking');
  });

  it('keeps an accepted provider turn recoverable after foreground waiting ends', () => {
    const response = {
      text: 'الرد مستمر',
      offline: false,
      unavailable: true,
      code: 'chat_answer_in_progress',
      turnStatus: 'queued' as const,
    };
    expect(classifyCourseChatResponse(response, true)).toEqual({
      acceptedPending: true,
      completed: false,
      pollingInterrupted: true,
    });
  });

  it('settles both bubbles from one terminal response', () => {
    const queued = queueCourseChatTurn({
      attachments: [],
      clientRequestId: requestId,
      messages: [],
      retrying: false,
      text: 'اشرح',
    });
    const settled = settleCourseChatTurn({
      assistantMessageId: queued.pendingId,
      clientRequestId: requestId,
      foregroundWaitExpired: false,
      messages: queued.messages,
      response: {
        text: 'الإجابة',
        offline: false,
        turnStatus: 'completed',
      },
      userMessageId: queued.userMessage.id,
    });

    expect(settled).toEqual([
      expect.objectContaining({
        deliveryStatus: 'completed',
        contextEligible: true,
      }),
      expect.objectContaining({
        role: 'assistant',
        text: 'الإجابة',
        deliveryStatus: 'completed',
        contextEligible: true,
      }),
    ]);
  });
});
