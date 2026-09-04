import type {ChatMessage} from '../types';
import {
  courseChatTurnIsActuallyStreaming,
  courseChatTurnIsUnresolved,
} from './policy';

export type AssistantPresence =
  | 'ready'
  | 'submitting'
  | 'checking'
  | 'connected'
  | 'working'
  | 'recoverable';

const MAX_IN_MEMORY_MESSAGES = 37;

export const welcomeMessage = (courseId: string): ChatMessage => ({
  id: `welcome-${courseId}`,
  role: 'assistant',
  text: 'اسألني عن أي جزء في المقطع\nاطلب شرحًا أو مثالًا أو تلخيصًا',
  createdAt: Date.now(),
  deliveryStatus: 'completed',
  contextEligible: false,
});

export const trimConversation = (messages: ChatMessage[]): ChatMessage[] => {
  if (messages.length <= MAX_IN_MEMORY_MESSAGES) return messages;
  const welcome = messages.find(message => message.id.startsWith('welcome-'));
  const recent = messages
    .filter(message => !message.id.startsWith('welcome-'))
    .slice(-(MAX_IN_MEMORY_MESSAGES - (welcome ? 1 : 0)));
  return welcome ? [welcome, ...recent] : recent;
};

export const conversationNeedsTrimming = (messages: ChatMessage[]) =>
  messages.length > MAX_IN_MEMORY_MESSAGES;

export const assistantPresenceFor = (
  messages: ChatMessage[],
): AssistantPresence => {
  const current = [...messages]
    .reverse()
    .find(
      message =>
        message.role === 'assistant' &&
        courseChatTurnIsUnresolved(message.deliveryStatus),
    );
  if (!current) return 'ready';
  if (courseChatTurnIsActuallyStreaming(current.deliveryStatus)) {
    return 'working';
  }
  switch (current.deliveryStatus) {
    case 'interrupted':
      return 'recoverable';
    case 'submitting':
      return 'submitting';
    case 'checking':
      return 'checking';
    default:
      return 'connected';
  }
};
