import {useCallback, useEffect, useRef, useState} from 'react';
import type {MutableRefObject} from 'react';

import {
  askCourseAssistant,
  cancelCourseAssistantTurn,
  pollCourseAssistantTurn,
  uploadCourseAssistantAttachment,
} from '../courseLearningApi';
import type {
  ChatAttachmentDraft,
  ChatMessage,
  CourseLearningData,
  CourseReel,
} from '../types';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';
import {reportClientError} from '../../../services/operationalTelemetry';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import {
  courseChatFailureCanStartFreshTurn,
  courseChatTurnIsUnresolved,
} from './policy';
import {saveCourseChatHistory} from './persistence';
import {pollAcceptedCourseChatTurn} from './turnPolling';
import {
  applyTurnPartial,
  classifyCourseChatResponse,
  failCourseChatTurn,
  markCourseChatTurnStopping,
  markTurnPolling,
  markTurnUploadComplete,
  queueCourseChatTurn,
  replaceTurnClientRequestId,
  settleCourseChatCancellation,
  settleCourseChatTurn,
} from './turnState';

type Params = {
  activeAccountScope: MutableRefObject<string | null>;
  activeConversation: MutableRefObject<string>;
  assistantIncluded: boolean;
  attachmentsRef: MutableRefObject<ChatAttachmentDraft[]>;
  commitAttachments: (
    update:
      | ChatAttachmentDraft[]
      | ((current: ChatAttachmentDraft[]) => ChatAttachmentDraft[]),
  ) => void;
  commitMessages: (
    update: ChatMessage[] | ((current: ChatMessage[]) => ChatMessage[]),
  ) => void;
  conversationGeneration: MutableRefObject<number>;
  conversationScope: string;
  course: CourseLearningData;
  hydratedConversation: MutableRefObject<string | null>;
  hydrationRecoveryRevision: number;
  inFlightAttachmentIds: MutableRefObject<Set<string>>;
  input: string;
  interactive: boolean;
  lessonId?: string;
  messagesRef: MutableRefObject<ChatMessage[]>;
  recordServerBlock: (code?: string) => void;
  reel?: CourseReel;
  scheduleScrollToEnd: (animated: boolean, delay: number) => void;
  setInput: (value: string) => void;
  upgraded: boolean;
};

/** Owns exactly one paid turn from local outbox through terminal recovery. */
export const useCourseChatTurn = ({
  activeAccountScope,
  activeConversation,
  assistantIncluded,
  attachmentsRef,
  commitAttachments,
  commitMessages,
  conversationGeneration,
  conversationScope,
  course,
  hydratedConversation,
  hydrationRecoveryRevision,
  inFlightAttachmentIds,
  input,
  interactive,
  lessonId,
  messagesRef,
  recordServerBlock,
  reel,
  scheduleScrollToEnd,
  setInput,
  upgraded,
}: Params) => {
  const courseId = course.id;
  const [sending, setSending] = useState(false);
  const [recoverySignal, setRecoverySignal] = useState(0);
  const sendFlightRef = useRef<symbol | null>(null);
  const sendGenerationRef = useRef(0);
  const stopFlightRef = useRef<{
    conversation: string;
    flight: symbol;
  } | null>(null);
  const resumeInterruptedTurnRef = useRef(false);
  const interactiveRef = useRef(interactive);
  const reportedTerminalTurnsRef = useRef(new Set<string>());
  const runTurnRef = useRef<
    (
      clientRequestId?: string,
      message?: string,
      files?: ChatAttachmentDraft[],
    ) => Promise<void>
  >(async () => undefined);
  interactiveRef.current = interactive;

  useEffect(() => {
    sendGenerationRef.current += 1;
    sendFlightRef.current = null;
    stopFlightRef.current = null;
    resumeInterruptedTurnRef.current = false;
    reportedTerminalTurnsRef.current.clear();
    setSending(false);
  }, [conversationScope]);

  const reportTerminalTurn = useCallback((requestId: string, code: string) => {
    const normalizedRequestId = String(requestId || '').trim();
    if (
      !normalizedRequestId ||
      reportedTerminalTurnsRef.current.has(normalizedRequestId)
    ) {
      return;
    }
    reportedTerminalTurnsRef.current.add(normalizedRequestId);
    if (reportedTerminalTurnsRef.current.size > 64) {
      const oldest = reportedTerminalTurnsRef.current.values().next().value;
      if (oldest) reportedTerminalTurnsRef.current.delete(oldest);
    }
    const normalizedCode = String(code || '')
      .trim()
      .toUpperCase();
    const safeCode = /^[A-Z0-9][A-Z0-9._-]{0,63}$/.test(normalizedCode)
      ? normalizedCode
      : 'CHAT_TERMINAL_FAILURE';
    void reportClientError(new Error(safeCode), {
      source: 'course_chat',
      endpoint: 'course-chat/turns',
      requestId: normalizedRequestId,
    });
  }, []);

  const runTurn = useCallback(
    async (
      retryClientRequestId?: string,
      retryMessage?: string,
      retryAttachments?: ChatAttachmentDraft[],
    ) => {
      const cleanMessage = cleanUnicodeText(retryMessage ?? input);
      const selectedAttachments = retryAttachments ?? attachmentsRef.current;
      const existingAssistant = retryClientRequestId
        ? messagesRef.current.find(
            item =>
              item.role === 'assistant' &&
              item.clientRequestId === retryClientRequestId,
          )
        : undefined;
      const existingUser = retryClientRequestId
        ? messagesRef.current.find(
            item =>
              item.role === 'user' &&
              item.clientRequestId === retryClientRequestId,
          )
        : undefined;
      const recoveryOnly = Boolean(
        retryClientRequestId && existingAssistant && !existingUser,
      );
      if (
        (!retryClientRequestId &&
          !cleanMessage &&
          selectedAttachments.length === 0) ||
        (retryClientRequestId && !existingAssistant) ||
        sendFlightRef.current ||
        !assistantIncluded ||
        (!retryClientRequestId &&
          messagesRef.current.some(
            item =>
              item.role === 'assistant' &&
              courseChatTurnIsUnresolved(item.deliveryStatus),
          )) ||
        hydratedConversation.current !== conversationScope
      ) {
        return;
      }

      const flight = Symbol('course-chat-send');
      const sendGeneration = ++sendGenerationRef.current;
      let clientRequestId = retryClientRequestId || secureRandomUuid();
      sendFlightRef.current = flight;
      selectedAttachments.forEach(file =>
        inFlightAttachmentIds.current.add(file.uploadId),
      );
      const ownedConversationGeneration = conversationGeneration.current;
      const queuedTurn = recoveryOnly
        ? {
            messages: messagesRef.current.map(item =>
              item.id === existingAssistant?.id
                ? {
                    ...item,
                    deliveryStatus: 'checking' as const,
                    errorCode: undefined,
                  }
                : item,
            ),
            pendingId: existingAssistant!.id,
            userMessage: undefined,
          }
        : queueCourseChatTurn({
            attachments: selectedAttachments,
            clientRequestId,
            messages: messagesRef.current,
            retrying: Boolean(retryClientRequestId),
            text: cleanMessage,
          });
      const {pendingId, userMessage} = queuedTurn;
      let queuedMessages = queuedTurn.messages;
      commitMessages(queuedMessages);
      if (!retryMessage && !retryClientRequestId) {
        setInput('');
        commitAttachments([]);
      }
      setSending(true);
      scheduleScrollToEnd(true, 80);

      const ownsTurn = () =>
        ownedConversationGeneration === conversationGeneration.current &&
        sendGeneration === sendGenerationRef.current &&
        activeConversation.current === conversationScope;

      try {
        const turnBoundary = await captureAccountSessionBoundary();
        if (activeAccountScope.current !== turnBoundary.scope) {
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        }
        await saveCourseChatHistory(
          courseId,
          queuedMessages,
          lessonId,
          turnBoundary,
        );

        let uploadedAttachments = selectedAttachments;
        if (!recoveryOnly) {
          const uploadedWithLocalFiles = await Promise.all(
            selectedAttachments.map(async file => ({
              ...file,
              serverId:
                file.serverId ||
                (await uploadCourseAssistantAttachment({courseId, file})),
            })),
          );
          uploadedAttachments = uploadedWithLocalFiles.map(file => ({
            ...file,
            uri: '',
          }));
          queuedMessages = markTurnUploadComplete(
            queuedMessages,
            userMessage!.id,
            uploadedAttachments,
          );
          assertAccountSessionBoundary(turnBoundary);
          await saveCourseChatHistory(
            courseId,
            queuedMessages,
            lessonId,
            turnBoundary,
          );
          assertAccountSessionBoundary(turnBoundary);
          await Promise.all(
            selectedAttachments
              .filter(file => file.uri && !file.serverId)
              .map(removeLearnerDraftFile),
          );
          if (!ownsTurn()) return;
          commitMessages(queuedMessages);
        }

        const attachmentIds = uploadedAttachments
          .map(file => file.serverId)
          .filter((id): id is string => Boolean(id));
        const requestCourse = upgraded
          ? {...course, accessType: 'paid', chatAvailable: true}
          : course;
        let attemptStartedAt = Date.now();
        let response = retryClientRequestId
          ? await pollCourseAssistantTurn(retryClientRequestId)
          : await askCourseAssistant({
              course: requestCourse,
              reel,
              message: cleanMessage,
              clientRequestId,
              attachmentIds,
            });
        if (!ownsTurn()) return;

        if (
          retryClientRequestId &&
          !recoveryOnly &&
          response.turnStatus === 'failed' &&
          courseChatFailureCanStartFreshTurn(response.canRetry)
        ) {
          clientRequestId = secureRandomUuid();
          queuedMessages = replaceTurnClientRequestId(
            queuedMessages,
            userMessage!.id,
            pendingId,
            clientRequestId,
          );
          commitMessages(queuedMessages);
          await saveCourseChatHistory(
            courseId,
            queuedMessages,
            lessonId,
            turnBoundary,
          );
          assertAccountSessionBoundary(turnBoundary);
          attemptStartedAt = Date.now();
          response = await askCourseAssistant({
            course: requestCourse,
            reel,
            message: cleanMessage,
            clientRequestId,
            attachmentIds,
          });
        }

        const polling = await pollAcceptedCourseChatTurn({
          clientRequestId,
          initialResponse: response,
          attemptStartedAt,
          isActive: () => interactiveRef.current && ownsTurn(),
          onStatus: turn => {
            commitMessages(rendered =>
              markTurnPolling(rendered, pendingId, turn.turnStatus),
            );
          },
          onPartial: text => {
            commitMessages(rendered =>
              applyTurnPartial(rendered, pendingId, text),
            );
          },
        });
        response = polling.response;
        const {foregroundWaitExpired} = polling;
        if (!ownsTurn()) return;
        if (response.blocked) recordServerBlock(response.code);
        const {acceptedPending} = classifyCourseChatResponse(
          response,
          foregroundWaitExpired,
        );
        resumeInterruptedTurnRef.current = acceptedPending
          ? !foregroundWaitExpired
          : false;
        if (response.turnStatus === 'failed' && !response.blocked) {
          reportTerminalTurn(
            response.clientRequestId || clientRequestId,
            response.code || 'chat_terminal_failure',
          );
        }
        const settledResponse =
          recoveryOnly && response.turnStatus === 'failed'
            ? {...response, canRetry: false}
            : response;
        commitMessages(rendered =>
          settleCourseChatTurn({
            assistantMessageId: pendingId,
            clientRequestId,
            foregroundWaitExpired,
            messages: rendered,
            response: settledResponse,
            userMessageId: userMessage?.id || '',
          }),
        );
      } catch (error: unknown) {
        if (
          !ownsTurn() ||
          (error instanceof Error &&
            error.message === 'ACCOUNT_CHANGED_DURING_REQUEST')
        ) {
          return;
        }
        reportTerminalTurn(
          clientRequestId,
          error instanceof Error ? error.message : 'network_unavailable',
        );
        commitMessages(rendered =>
          failCourseChatTurn(rendered, userMessage?.id || '', pendingId),
        );
      } finally {
        selectedAttachments.forEach(file =>
          inFlightAttachmentIds.current.delete(file.uploadId),
        );
        if (sendFlightRef.current === flight) {
          sendFlightRef.current = null;
          if (ownedConversationGeneration === conversationGeneration.current) {
            setSending(false);
            scheduleScrollToEnd(true, 80);
            setRecoverySignal(value => value + 1);
          }
        }
      }
    },
    [
      activeAccountScope,
      activeConversation,
      assistantIncluded,
      attachmentsRef,
      commitAttachments,
      commitMessages,
      conversationGeneration,
      conversationScope,
      course,
      courseId,
      hydratedConversation,
      inFlightAttachmentIds,
      input,
      lessonId,
      messagesRef,
      recordServerBlock,
      reel,
      reportTerminalTurn,
      scheduleScrollToEnd,
      setInput,
      upgraded,
    ],
  );

  const send = useCallback(() => void runTurn(), [runTurn]);
  const isSendInFlight = useCallback(() => Boolean(sendFlightRef.current), []);
  const retry = useCallback(
    (clientRequestId: string) => {
      const userMessage = messagesRef.current.find(
        item =>
          item.role === 'user' && item.clientRequestId === clientRequestId,
      );
      void runTurn(
        clientRequestId,
        userMessage?.text || '',
        userMessage?.attachments || [],
      );
    },
    [messagesRef, runTurn],
  );

  const stop = useCallback(async () => {
    if (stopFlightRef.current?.conversation === conversationScope) return;
    const pending = [...messagesRef.current]
      .reverse()
      .find(
        item =>
          item.role === 'assistant' &&
          item.clientRequestId &&
          courseChatTurnIsUnresolved(item.deliveryStatus),
      );
    if (!pending?.clientRequestId) return;
    const stopFlight = Symbol('course-chat-stop');
    stopFlightRef.current = {
      conversation: conversationScope,
      flight: stopFlight,
    };
    const stoppedRequestId = pending.clientRequestId;
    const stopConversationGeneration = conversationGeneration.current;
    sendGenerationRef.current += 1;
    setSending(false);
    commitMessages(current =>
      markCourseChatTurnStopping(current, stoppedRequestId),
    );
    try {
      const cancelledAtServer = await cancelCourseAssistantTurn(
        stoppedRequestId,
      );
      resumeInterruptedTurnRef.current = !cancelledAtServer;
      if (
        stopConversationGeneration !== conversationGeneration.current ||
        activeConversation.current !== conversationScope
      ) {
        return;
      }
      commitMessages(current =>
        settleCourseChatCancellation(
          current,
          stoppedRequestId,
          cancelledAtServer,
        ),
      );
      if (!cancelledAtServer) {
        setRecoverySignal(value => value + 1);
      }
    } finally {
      if (stopFlightRef.current?.flight === stopFlight) {
        stopFlightRef.current = null;
      }
    }
  }, [
    activeConversation,
    commitMessages,
    conversationGeneration,
    conversationScope,
    messagesRef,
  ]);

  runTurnRef.current = runTurn;

  useEffect(() => {
    if (hydrationRecoveryRevision > 0) {
      resumeInterruptedTurnRef.current = true;
    }
  }, [hydrationRecoveryRevision]);

  useEffect(() => {
    if (!interactive) {
      if (
        sendFlightRef.current ||
        messagesRef.current.some(
          message =>
            message.role === 'assistant' &&
            courseChatTurnIsUnresolved(message.deliveryStatus),
        )
      ) {
        resumeInterruptedTurnRef.current = true;
      }
      return;
    }
    if (
      !resumeInterruptedTurnRef.current ||
      sending ||
      sendFlightRef.current ||
      hydratedConversation.current !== conversationScope
    ) {
      return;
    }
    resumeInterruptedTurnRef.current = false;
    const assistant = [...messagesRef.current]
      .reverse()
      .find(
        message =>
          message.role === 'assistant' &&
          Boolean(message.clientRequestId) &&
          courseChatTurnIsUnresolved(message.deliveryStatus),
      );
    if (!assistant?.clientRequestId) return;
    const user = messagesRef.current.find(
      message =>
        message.role === 'user' &&
        message.clientRequestId === assistant.clientRequestId,
    );
    void runTurnRef.current(
      assistant.clientRequestId,
      user?.text || '',
      user?.attachments || [],
    );
  }, [
    conversationScope,
    hydratedConversation,
    hydrationRecoveryRevision,
    interactive,
    messagesRef,
    recoverySignal,
    sending,
  ]);

  return {isSendInFlight, retry, send, sending, stop};
};
