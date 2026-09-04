import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  askCourseAssistant: jest.fn(),
  cancelCourseAssistantTurn: jest.fn(),
  pollCourseAssistantTurn: jest.fn(),
  uploadCourseAssistantAttachment: jest.fn(),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-a',
  })),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: jest.fn(() => 'fresh-request-id'),
}));
jest.mock('../src/components/VideoPlayer/courseChat/persistence', () => ({
  saveCourseChatHistory: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseChat/turnPolling', () => ({
  pollAcceptedCourseChatTurn: jest.fn(async ({initialResponse}) => ({
    foregroundWaitExpired: false,
    response: initialResponse,
  })),
}));

import {
  askCourseAssistant,
  cancelCourseAssistantTurn,
  pollCourseAssistantTurn,
} from '../src/components/VideoPlayer/courseLearningApi';
import type {
  ChatAttachmentDraft,
  ChatMessage,
  CourseLearningData,
} from '../src/components/VideoPlayer/types';
import {useCourseChatTurn} from '../src/components/VideoPlayer/courseChat/useCourseChatTurn';

const requestId = 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388';

describe('course chat turn owner', () => {
  it('recovers a server-owned assistant turn even when history lacks its user row', async () => {
    jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
      clientRequestId: requestId,
      text: 'الإجابة المستعادة',
      offline: false,
      turnStatus: 'completed',
    });
    const messagesRef: {current: ChatMessage[]} = {
      current: [
        {
          id: `assistant-${requestId}`,
          role: 'assistant',
          text: 'الرد محفوظ',
          createdAt: 1,
          clientRequestId: requestId,
          deliveryStatus: 'interrupted',
          errorCode: 'interrupted_turn',
          canRetry: true,
        },
      ],
    };
    const attachmentsRef: {current: ChatAttachmentDraft[]} = {current: []};
    const activeAccountScope = {current: 'user-a'};
    const activeConversation = {current: 'user-a:course-1:course'};
    const conversationGeneration = {current: 1};
    const hydratedConversation = {current: activeConversation.current};
    let turn: ReturnType<typeof useCourseChatTurn> | undefined;

    const Harness = () => {
      turn = useCourseChatTurn({
        activeAccountScope,
        activeConversation,
        assistantIncluded: true,
        attachmentsRef,
        commitAttachments: update => {
          attachmentsRef.current =
            typeof update === 'function'
              ? update(attachmentsRef.current)
              : update;
        },
        commitMessages: update => {
          messagesRef.current =
            typeof update === 'function' ? update(messagesRef.current) : update;
        },
        conversationGeneration,
        conversationScope: activeConversation.current,
        course: {
          id: 'course-1',
          title: 'الكورس',
          totalReels: 1,
          attachments: [],
          modules: [],
          accessType: 'paid',
          chatAvailable: true,
        } as CourseLearningData,
        hydratedConversation,
        hydrationRecoveryRevision: 0,
        inFlightAttachmentIds: {current: new Set()},
        input: '',
        interactive: true,
        messagesRef,
        recordServerBlock: jest.fn(),
        scheduleScrollToEnd: jest.fn(),
        setInput: jest.fn(),
        upgraded: false,
      });
      return null;
    };

    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(async () => {
      renderer = ReactTestRenderer.create(<Harness />);
    });
    await ReactTestRenderer.act(async () => {
      turn!.retry(requestId);
      turn!.retry(requestId);
      for (let index = 0; index < 8; index += 1) await Promise.resolve();
    });

    expect(pollCourseAssistantTurn).toHaveBeenCalledTimes(1);
    expect(pollCourseAssistantTurn).toHaveBeenCalledWith(requestId);
    expect(askCourseAssistant).not.toHaveBeenCalled();
    expect(messagesRef.current).toEqual([
      expect.objectContaining({
        id: `assistant-${requestId}`,
        text: 'الإجابة المستعادة',
        deliveryStatus: 'completed',
      }),
    ]);

    await ReactTestRenderer.act(async () => renderer!.unmount());
  });

  it('reconciles the accepted turn after cancellation has an unknown outcome', async () => {
    jest.mocked(cancelCourseAssistantTurn).mockResolvedValue(false);
    jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
      clientRequestId: requestId,
      text: 'الإجابة وصلت',
      offline: false,
      turnStatus: 'completed',
    });
    const messagesRef: {current: ChatMessage[]} = {
      current: [
        {
          id: `assistant-${requestId}`,
          role: 'assistant',
          text: '',
          createdAt: 1,
          clientRequestId: requestId,
          deliveryStatus: 'queued',
        },
      ],
    };
    const activeConversation = {current: 'user-a:course-1:course'};
    let turn: ReturnType<typeof useCourseChatTurn> | undefined;
    const Harness = () => {
      turn = useCourseChatTurn({
        activeAccountScope: {current: 'user-a'},
        activeConversation,
        assistantIncluded: true,
        attachmentsRef: {current: []},
        commitAttachments: jest.fn(),
        commitMessages: update => {
          messagesRef.current =
            typeof update === 'function' ? update(messagesRef.current) : update;
        },
        conversationGeneration: {current: 1},
        conversationScope: activeConversation.current,
        course: {
          id: 'course-1',
          title: 'الكورس',
          totalReels: 1,
          attachments: [],
          modules: [],
          accessType: 'paid',
          chatAvailable: true,
        } as CourseLearningData,
        hydratedConversation: {current: activeConversation.current},
        hydrationRecoveryRevision: 0,
        inFlightAttachmentIds: {current: new Set()},
        input: '',
        interactive: true,
        messagesRef,
        recordServerBlock: jest.fn(),
        scheduleScrollToEnd: jest.fn(),
        setInput: jest.fn(),
        upgraded: false,
      });
      return null;
    };

    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(async () => {
      renderer = ReactTestRenderer.create(<Harness />);
    });
    await ReactTestRenderer.act(async () => {
      await turn!.stop();
      for (let index = 0; index < 12; index += 1) await Promise.resolve();
    });

    expect(cancelCourseAssistantTurn).toHaveBeenCalledWith(requestId);
    expect(pollCourseAssistantTurn).toHaveBeenCalledWith(requestId);
    expect(messagesRef.current).toEqual([
      expect.objectContaining({
        text: 'الإجابة وصلت',
        deliveryStatus: 'completed',
      }),
    ]);
    await ReactTestRenderer.act(async () => renderer!.unmount());
  });
});
