import type {CourseAssistantTurnResponse} from '../courseLearning/assistant';
import type {ChatAttachmentDraft, ChatMessage} from '../types';
import {courseChatTurnIsPolling} from './policy';

export const queueCourseChatTurn = ({
  attachments,
  clientRequestId,
  messages,
  retrying,
  text,
}: {
  attachments: ChatAttachmentDraft[];
  clientRequestId: string;
  messages: ChatMessage[];
  retrying: boolean;
  text: string;
}) => {
  const existingUser = retrying
    ? messages.find(
        item =>
          item.role === 'user' && item.clientRequestId === clientRequestId,
      )
    : undefined;
  const existingAssistant = retrying
    ? messages.find(
        item =>
          item.role === 'assistant' && item.clientRequestId === clientRequestId,
      )
    : undefined;
  const userMessage: ChatMessage = existingUser || {
    id: `user-${clientRequestId}`,
    role: 'user',
    text: text || 'راجع المرفق',
    createdAt: Date.now(),
    clientRequestId,
    deliveryStatus: 'submitting',
    contextEligible: false,
    attachments,
  };
  const pendingId = existingAssistant?.id || `assistant-${clientRequestId}`;
  const updated = messages.map(item => {
    if (item.id === existingUser?.id) {
      return {
        ...item,
        clientRequestId,
        deliveryStatus: 'sent' as const,
        contextEligible: false,
      };
    }
    if (item.id === existingAssistant?.id) {
      return {
        ...item,
        clientRequestId,
        deliveryStatus: 'checking' as const,
        errorCode: undefined,
        contextEligible: false,
      };
    }
    return item;
  });

  if (!existingUser) updated.push(userMessage);
  if (!existingAssistant) {
    updated.push({
      id: pendingId,
      role: 'assistant',
      text: '',
      createdAt: Date.now(),
      clientRequestId,
      deliveryStatus: retrying ? 'checking' : 'submitting',
      contextEligible: false,
    });
  }

  return {messages: updated, pendingId, userMessage};
};

export const replaceTurnClientRequestId = (
  messages: ChatMessage[],
  userMessageId: string,
  assistantMessageId: string,
  clientRequestId: string,
): ChatMessage[] =>
  messages.map(item =>
    item.id === userMessageId || item.id === assistantMessageId
      ? {...item, clientRequestId}
      : item,
  );

export const markTurnUploadComplete = (
  messages: ChatMessage[],
  userMessageId: string,
  attachments: ChatAttachmentDraft[],
): ChatMessage[] =>
  messages.map(item =>
    item.id === userMessageId ? {...item, attachments} : item,
  );

export const markTurnPolling = (
  messages: ChatMessage[],
  assistantMessageId: string,
  turnStatus: ChatMessage['deliveryStatus'],
): ChatMessage[] =>
  messages.map(item =>
    item.id === assistantMessageId
      ? {
          ...item,
          deliveryStatus: turnStatus === 'streaming' ? 'streaming' : 'queued',
        }
      : item,
  );

export const applyTurnPartial = (
  messages: ChatMessage[],
  assistantMessageId: string,
  text: string,
): ChatMessage[] =>
  messages.map(item =>
    item.id === assistantMessageId
      ? {...item, text, deliveryStatus: 'streaming'}
      : item,
  );

export const classifyCourseChatResponse = (
  response: CourseAssistantTurnResponse,
  foregroundWaitExpired: boolean,
) => {
  const completed =
    response.turnStatus === 'completed' &&
    !response.unavailable &&
    !response.blocked &&
    !response.offline;
  const acceptedPending =
    response.code === 'chat_answer_in_progress' &&
    courseChatTurnIsPolling(response.turnStatus);
  return {
    acceptedPending,
    completed,
    pollingInterrupted: acceptedPending && foregroundWaitExpired,
  };
};

export const settleCourseChatTurn = ({
  assistantMessageId,
  clientRequestId,
  foregroundWaitExpired,
  messages,
  response,
  userMessageId,
}: {
  assistantMessageId: string;
  clientRequestId: string;
  foregroundWaitExpired: boolean;
  messages: ChatMessage[];
  response: CourseAssistantTurnResponse;
  userMessageId: string;
}): ChatMessage[] => {
  const {acceptedPending, completed, pollingInterrupted} =
    classifyCourseChatResponse(response, foregroundWaitExpired);

  return messages.map(item => {
    if (item.id === userMessageId) {
      if (completed) {
        return {...item, deliveryStatus: 'completed', contextEligible: true};
      }
      if (acceptedPending) {
        return {...item, deliveryStatus: 'sent', contextEligible: false};
      }
      if (response.turnStatus === 'cancelled') {
        return {...item, deliveryStatus: 'cancelled', contextEligible: false};
      }
      return {...item, deliveryStatus: 'failed', contextEligible: false};
    }
    if (item.id !== assistantMessageId) return item;
    return {
      ...item,
      text: response.text,
      clientRequestId: response.clientRequestId || clientRequestId,
      deliveryStatus: completed
        ? ('completed' as const)
        : pollingInterrupted
        ? ('interrupted' as const)
        : acceptedPending
        ? response.turnStatus || 'queued'
        : response.turnStatus === 'cancelled'
        ? ('cancelled' as const)
        : ('failed' as const),
      errorCode: completed
        ? undefined
        : pollingInterrupted
        ? 'interrupted_turn'
        : response.code,
      canRetry: pollingInterrupted ? true : response.canRetry,
      retryAfterSeconds: response.retryAfterSeconds,
      contextEligible: completed,
    };
  });
};

export const failCourseChatTurn = (
  messages: ChatMessage[],
  userMessageId: string,
  assistantMessageId: string,
): ChatMessage[] =>
  messages.map(item =>
    item.id === userMessageId
      ? {...item, deliveryStatus: 'failed' as const, contextEligible: false}
      : item.id === assistantMessageId
      ? {
          ...item,
          text: 'تعذّر الرد\nحاول مرة أخرى',
          deliveryStatus: 'failed' as const,
          errorCode: 'network_unavailable',
          canRetry: true,
          contextEligible: false,
        }
      : item,
  );

export const markCourseChatTurnStopping = (
  messages: ChatMessage[],
  clientRequestId: string,
): ChatMessage[] =>
  messages.map(item =>
    item.clientRequestId === clientRequestId && item.role === 'assistant'
      ? {
          ...item,
          text: 'جارٍ إيقاف الرد',
          deliveryStatus: 'queued',
          errorCode: 'chat_answer_in_progress',
          canRetry: true,
          contextEligible: false,
        }
      : item,
  );

export const settleCourseChatCancellation = (
  messages: ChatMessage[],
  clientRequestId: string,
  cancelledAtServer: boolean,
): ChatMessage[] =>
  messages.map(item => {
    if (item.clientRequestId !== clientRequestId) return item;
    if (cancelledAtServer) {
      return {
        ...item,
        text: item.role === 'assistant' ? 'تم إيقاف الرد' : item.text,
        deliveryStatus: 'cancelled',
        errorCode: 'learner_cancelled',
        contextEligible: false,
      };
    }
    return item.role === 'assistant'
      ? {
          ...item,
          text: 'تعذّر إيقاف الرد\nنتحقق من حالته',
          deliveryStatus: 'interrupted',
          errorCode: 'interrupted_turn',
          canRetry: true,
        }
      : {...item, deliveryStatus: 'sent', contextEligible: false};
  });
