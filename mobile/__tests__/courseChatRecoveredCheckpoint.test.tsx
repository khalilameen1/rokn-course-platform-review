import React, {useState} from 'react';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockPoll = jest.fn();
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  askCourseAssistant: jest.fn(),
  cancelCourseAssistantTurn: jest.fn(),
  pollCourseAssistantTurn: (...args: unknown[]) => mockPoll(...args),
  uploadCourseAssistantAttachment: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/assistant', () => ({
  pollCourseAssistantTurn: (...args: unknown[]) => mockPoll(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'user-1', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseChat/persistence', () => ({
  saveCourseChatHistory: jest.fn(async () => undefined),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
import {useCourseChatTurn} from '../src/components/VideoPlayer/courseChat/useCourseChatTurn';
import {
  askCourseAssistant,
  uploadCourseAssistantAttachment,
} from '../src/components/VideoPlayer/courseLearningApi';
import type {
  ChatMessage,
  CourseLearningData,
} from '../src/components/VideoPlayer/types';

const requestId = '11111111-1111-4111-8111-111111111111';
const scope = 'user-1:3:course';
const checkpoint = {
  text: 'ابدأ بتحديد مصدر الضوء\nثم ارسم الظل',
  clientRequestId: requestId,
  turnStatus: 'streaming' as const,
  code: 'chat_answer_in_progress',
  partial: true,
  offline: false,
  retryAfterSeconds: 1,
  pollWindowSeconds: 95,
};

describe('recovered course chat checkpoint arrival', () => {
  afterEach(() => {
    jest.useRealTimers();
  });
  it.each(['foreground', 'hydration'] as const)(
    'renders the first status checkpoint during %s recovery before another poll or paid request',
    async mode => {
      jest.useFakeTimers();
      jest.clearAllMocks();
      mockPoll.mockResolvedValue(checkpoint);
      const messagesRef: {current: ChatMessage[]} = {
        current: [
          {
            id: 'question',
            role: 'user',
            text: 'أبدأ منين؟',
            createdAt: 1,
            clientRequestId: requestId,
            deliveryStatus: 'sent',
          },
          {
            id: 'answer',
            role: 'assistant',
            text: '',
            createdAt: 2,
            clientRequestId: requestId,
            deliveryStatus: 'queued',
          },
        ],
      };
      const shared = {
        activeAccountScope: {current: 'user-1'},
        activeConversation: {current: scope},
        assistantIncluded: true,
        attachmentsRef: {current: []},
        commitAttachments: jest.fn(),
        conversationGeneration: {current: 1},
        conversationScope: scope,
        course: {
          id: '3',
          accessType: 'paid',
          chatAvailable: true,
        } as CourseLearningData,
        hydratedConversation: {current: scope},
        hydrationRecoveryRevision: mode === 'hydration' ? 1 : 0,
        inFlightAttachmentIds: {current: new Set<string>()},
        input: '',
        messagesRef,
        recordServerBlock: jest.fn(),
        scheduleScrollToEnd: jest.fn(),
        setInput: jest.fn(),
        upgraded: false,
      };
      const Harness = ({interactive}: {interactive: boolean}) => {
        const [messages, setMessages] = useState(messagesRef.current);
        useCourseChatTurn({
          ...shared,
          interactive,
          commitMessages: update => {
            messagesRef.current =
              typeof update === 'function'
                ? update(messagesRef.current)
                : update;
            setMessages(messagesRef.current);
          },
        });
        return (
          <Text>{messages.find(message => message.id === 'answer')?.text}</Text>
        );
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(
            <Harness interactive={mode === 'hydration'} />,
          );
        });
        if (mode === 'foreground')
          await act(async () => renderer.update(<Harness interactive />));
        expect(mockPoll).toHaveBeenCalledTimes(1);
        expect(renderer.root.findByType(Text).props.children).toBe(
          checkpoint.text,
        );
        expect(messagesRef.current[1].deliveryStatus).toBe('streaming');
        await act(async () => jest.advanceTimersByTimeAsync(4000));
        expect(renderer.root.findByType(Text).props.children).toBe(
          checkpoint.text,
        );
        mockPoll.mockResolvedValue({
          ...checkpoint,
          code: undefined,
          partial: false,
          turnStatus: 'completed',
          text: 'الإجابة النهائية',
        });
        await act(async () => jest.advanceTimersByTimeAsync(4000));
        expect(renderer.root.findByType(Text).props.children).toBe(
          'الإجابة النهائية',
        );
        expect(messagesRef.current[1].deliveryStatus).toBe('completed');
        expect(askCourseAssistant).not.toHaveBeenCalled();
        expect(uploadCourseAssistantAttachment).not.toHaveBeenCalled();
      } finally {
        await act(async () => {
          shared.conversationGeneration.current += 1;
          renderer.unmount();
          await jest.runAllTimersAsync();
        });
      }
    },
  );
});
