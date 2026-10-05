import {useCallback, useEffect, useRef, useState} from 'react';
import type {MutableRefObject} from 'react';

import {loadCourseAssistantHistory} from '../courseLearningApi';
import type {ChatAttachmentDraft, ChatMessage} from '../types';
import {
  assertAccountSessionBoundary,
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
  inFlightRequestId: MutableRefObject<() => string | undefined>;
  active: boolean;
  remoteEnabled: boolean;
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
  inFlightRequestId,
  active,
  remoteEnabled,
}: Params) => {
  const [messages, setMessages] = useState<ChatMessage[]>(() => [
    welcomeMessage(courseId),
  ]);
  const [input, setInput] = useState('');
  const [attachments, setAttachments] = useState<ChatAttachmentDraft[]>([]);
  const [hydrated, setHydrated] = useState(false);
  const [hydrationError, setHydrationError] = useState('');
  const [hydrationAttempt, setHydrationAttempt] = useState(0);
  const [recoveryRevision, setRecoveryRevision] = useState(0);
  const messagesRef = useRef(messages);
  const attachmentsRef = useRef(attachments);
  const conversationGenerationRef = useRef(0);
  const activeConversationRef = useRef(conversationScope);
  const hydratedConversationRef = useRef<string | null>(null);
  const activeAccountScopeRef = useRef<string | null>(null);
  const historyVisitRef = useRef({conversationScope, active, remoteEnabled});
  if (
    historyVisitRef.current.conversationScope !== conversationScope ||
    historyVisitRef.current.active !== active ||
    historyVisitRef.current.remoteEnabled !== remoteEnabled
  ) {
    historyVisitRef.current = {conversationScope, active, remoteEnabled};
  }
  const historyVisit = historyVisitRef.current;

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
    setHydrationError('');
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
    })().catch(() => {
      if (
        generation === conversationGenerationRef.current &&
        activeConversationRef.current === conversationScope
      ) {
        setHydrationError('تعذّر استعادة المحادثة المحفوظة\nحاول مرة أخرى');
      }
    });

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
    hydrationAttempt,
  ]);

  // The transcript/composer belongs to the conversation, not its open visit.
  // Each open/foreground visit refreshes canonical history independently of
  // local hydration. Retiring a read must never reset the draft or paid outbox.
  useEffect(() => {
    if (
      !active ||
      !remoteEnabled ||
      !hydrated ||
      hydratedConversationRef.current !== conversationScope
    ) {
      return;
    }
    const generation = conversationGenerationRef.current;
    let ownsRead = true;
    const isCurrentRead = () =>
      ownsRead &&
      historyVisitRef.current === historyVisit &&
      conversationGenerationRef.current === generation &&
      activeConversationRef.current === conversationScope &&
      hydratedConversationRef.current === conversationScope;

    void (async () => {
      const boundary = await captureAccountSessionBoundary();
      if (
        !isCurrentRead() ||
        activeAccountScopeRef.current !== boundary.scope
      ) return;
      assertAccountSessionBoundary(boundary);
      const remoteHistory = await loadCourseAssistantHistory(courseId, lessonId);
      assertAccountSessionBoundary(boundary);
      if (!isCurrentRead()) return;
      // Only the live turn owner may checkpoint its own bubbles. The GET can
      // still restore other turns without swapping the live owner's ids or
      // regressing its state with a snapshot from before Retry/Stop.
      const ownedRequestId = inFlightRequestId.current();
      const reconciled = [
        welcomeMessage(courseId),
        ...trimConversation(
          mergeCourseChatHistories(
            remoteHistory.filter(
              message =>
                !ownedRequestId || message.clientRequestId !== ownedRequestId,
            ),
            messagesRef.current.filter(
              message => !message.id.startsWith('welcome-'),
            ),
          ),
        ),
      ];
      commitMessages(reconciled);
      if (hasRecoverableTurn(reconciled)) {
        setRecoveryRevision(value => value + 1);
      }
    })().catch(() => {
      // Local history stays usable during an outage. A new open/foreground
      // visit retries this read, not hydration and not question delivery.
    });
    return () => {
      ownsRead = false;
    };
  }, [
    active,
    commitMessages,
    conversationScope,
    courseId,
    historyVisit,
    hydrated,
    inFlightRequestId,
    lessonId,
    remoteEnabled,
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

  const hydrationOwner = conversationGenerationRef.current;
  return {
    activeAccountScopeRef,
    activeConversationRef,
    attachments,
    attachmentsRef,
    commitAttachments,
    commitMessages,
    conversationGenerationRef,
    hydrated,
    hydrationError,
    retryHydration: () => {
      if (
        hydrationError &&
        !hydrated &&
        hydrationOwner === conversationGenerationRef.current &&
        activeConversationRef.current === conversationScope
      )
        setHydrationAttempt(value => value + 1);
    },
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
