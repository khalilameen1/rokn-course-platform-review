import {useCallback, useEffect, useRef, useState} from 'react';
import type {MutableRefObject} from 'react';

import {loadCourseAssistantHistory} from '../courseLearningApi';
import type {ChatAttachmentDraft, ChatMessage} from '../types';
import {
  captureAccountSessionBoundary,
  getCurrentAccountStorageScope,
} from '../../../constants/helpers';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';
import {
  conversationNeedsTrimming,
  trimConversation,
  welcomeMessage,
} from './conversation';
import {
  loadCourseChatHistory,
  mergeCourseChatHistories,
  saveCourseChatHistory,
} from './persistence';
import {courseChatTurnIsUnresolved} from './policy';

type Params = {
  courseId: string;
  lessonId?: string;
  conversationScope: string;
  inFlightAttachmentIds: MutableRefObject<Set<string>>;
};

const hasRecoverableTurn = (messages: ChatMessage[]) =>
  messages.some(
    message =>
      message.role === 'assistant' &&
      Boolean(message.clientRequestId) &&
      courseChatTurnIsUnresolved(message.deliveryStatus),
  );

/**
 * Owns the account/course-scoped transcript and composer draft. Server history
 * is reconciled into the same state; it never replaces a newer local outbox.
 */
export const useCourseChatConversation = ({
  courseId,
  lessonId,
  conversationScope,
  inFlightAttachmentIds,
}: Params) => {
  const [messages, setMessages] = useState<ChatMessage[]>(() => [
    welcomeMessage(courseId),
  ]);
  const [input, setInput] = useState('');
  const [attachments, setAttachments] = useState<ChatAttachmentDraft[]>([]);
  const [hydrated, setHydrated] = useState(false);
  const [recoveryRevision, setRecoveryRevision] = useState(0);
  const messagesRef = useRef(messages);
  const attachmentsRef = useRef(attachments);
  const conversationGenerationRef = useRef(0);
  const activeConversationRef = useRef(conversationScope);
  const hydratedConversationRef = useRef<string | null>(null);
  const activeAccountScopeRef = useRef<string | null>(null);

  activeConversationRef.current = conversationScope;
  messagesRef.current = messages;
  attachmentsRef.current = attachments;

  const commitMessages = useCallback(
    (
      update:
        | ChatMessage[]
        | ((currentMessages: ChatMessage[]) => ChatMessage[]),
    ) => {
      const nextMessages =
        typeof update === 'function' ? update(messagesRef.current) : update;
      messagesRef.current = nextMessages;
      setMessages(nextMessages);
    },
    [],
  );

  const commitAttachments = useCallback(
    (
      update:
        | ChatAttachmentDraft[]
        | ((currentFiles: ChatAttachmentDraft[]) => ChatAttachmentDraft[]),
    ) => {
      const nextFiles =
        typeof update === 'function' ? update(attachmentsRef.current) : update;
      attachmentsRef.current = nextFiles;
      setAttachments(nextFiles);
    },
    [],
  );

  useEffect(() => {
    conversationGenerationRef.current += 1;
    const generation = conversationGenerationRef.current;
    hydratedConversationRef.current = null;
    activeAccountScopeRef.current = null;
    setHydrated(false);
    commitMessages([welcomeMessage(courseId)]);
    setInput('');
    const abandonedDrafts = attachmentsRef.current.filter(
      file => !inFlightAttachmentIds.current.has(file.uploadId),
    );
    commitAttachments([]);
    void Promise.all(abandonedDrafts.map(removeLearnerDraftFile));

    const ownsConversation = async (accountScope: string) =>
      generation === conversationGenerationRef.current &&
      activeConversationRef.current === conversationScope &&
      (await getCurrentAccountStorageScope()) === accountScope;

    void (async () => {
      const boundary = await captureAccountSessionBoundary();
      const accountScope = boundary.scope;
      const localHistory = await loadCourseChatHistory(
        courseId,
        lessonId,
        boundary,
      );
      if (!(await ownsConversation(accountScope))) return;

      activeAccountScopeRef.current = accountScope;
      hydratedConversationRef.current = conversationScope;
      const initialMessages = [
        welcomeMessage(courseId),
        ...trimConversation(localHistory),
      ];
      commitMessages(initialMessages);
      setHydrated(true);
      if (hasRecoverableTurn(initialMessages)) {
        setRecoveryRevision(value => value + 1);
      }

      try {
        const remoteHistory = await loadCourseAssistantHistory(
          courseId,
          lessonId,
        );
        if (!(await ownsConversation(accountScope))) return;
        const currentLocalHistory = messagesRef.current.filter(
          message => !message.id.startsWith('welcome-'),
        );
        const reconciled = [
          welcomeMessage(courseId),
          ...trimConversation(
            mergeCourseChatHistories(remoteHistory, currentLocalHistory),
          ),
        ];
        commitMessages(reconciled);
        if (hasRecoverableTurn(reconciled)) {
          setRecoveryRevision(value => value + 1);
        }
      } catch {
        // Local history is already usable. Reopening the chat retries server
        // reconciliation without discarding the account-scoped outbox.
      }
    })();

    return () => {
      conversationGenerationRef.current += 1;
      hydratedConversationRef.current = null;
      activeAccountScopeRef.current = null;
    };
  }, [
    commitAttachments,
    commitMessages,
    conversationScope,
    courseId,
    inFlightAttachmentIds,
    lessonId,
  ]);

  useEffect(() => {
    if (
      !hydrated ||
      hydratedConversationRef.current !== conversationScope ||
      !activeAccountScopeRef.current
    ) {
      return;
    }
    const generation = conversationGenerationRef.current;
    void (async () => {
      const boundary = await captureAccountSessionBoundary();
      if (
        generation !== conversationGenerationRef.current ||
        activeConversationRef.current !== conversationScope ||
        activeAccountScopeRef.current !== boundary.scope
      ) {
        return;
      }
      await saveCourseChatHistory(courseId, messages, lessonId, boundary);
    })().catch(() => undefined);
  }, [conversationScope, courseId, hydrated, lessonId, messages]);

  useEffect(() => {
    if (!conversationNeedsTrimming(messages)) return;
    commitMessages(current => {
      const trimmed = trimConversation(current);
      const retained = new Set(trimmed.map(message => message.id));
      const discardedFiles = current
        .filter(message => !retained.has(message.id))
        .flatMap(message => message.attachments || [])
        .filter(file => !file.serverId);
      void Promise.all(discardedFiles.map(removeLearnerDraftFile));
      return trimmed;
    });
  }, [commitMessages, messages]);

  return {
    activeAccountScopeRef,
    activeConversationRef,
    attachments,
    attachmentsRef,
    commitAttachments,
    commitMessages,
    conversationGenerationRef,
    hydrated,
    hydratedConversationRef,
    input,
    messages,
    messagesRef,
    recoveryRevision,
    setInput,
  };
};

export type CourseChatConversationOwner = ReturnType<
  typeof useCourseChatConversation
>;
