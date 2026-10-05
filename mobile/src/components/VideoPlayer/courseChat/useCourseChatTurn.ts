import {useCallback, useEffect, useRef, useState} from 'react';
import type {MutableRefObject} from 'react';
import {Alert} from 'react-native';
import {subscriptionMessages} from '../../../constants/subscriptionMessages';

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
  type AccountSessionBoundary,
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
}: Params) => {
  const courseId = course.id;
  const [sending, setSending] = useState(false);
  const [recoverySignal, setRecoverySignal] = useState(0);
  const sendFlightRef = useRef<{
    requestId: string;
    conversationScope: string;
    conversationGeneration: number;
  } | null>(null);
  const sendGenerationRef = useRef(0);
  const stopFlightRef = useRef<{
    conversation: string;
    generation: number;
    requestId: string;
    phase: 'preparing' | 'dispatched';
    visit: {conversationScope: string; interactive: boolean};
  } | null>(null);
  const resumeInterruptedTurnRef = useRef(false);
  const interactiveRef = useRef(interactive);
  const noticeVisitRef = useRef({conversationScope, interactive});
  if (
    noticeVisitRef.current.conversationScope !== conversationScope ||
    noticeVisitRef.current.interactive !== interactive
  ) noticeVisitRef.current = {conversationScope, interactive};
  const controlVisit = noticeVisitRef.current;
  const noticeMountedRef = useRef(false);
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
    noticeMountedRef.current = true;
    return () => {noticeMountedRef.current = false;};
  }, []);

  useEffect(() => {
    sendGenerationRef.current += 1;
    sendFlightRef.current = null;
    stopFlightRef.current = null;
    resumeInterruptedTurnRef.current = false;
    reportedTerminalTurnsRef.current.clear();
    setSending(false);
  }, [conversationScope]);

  useEffect(() => {
    const stopping = stopFlightRef.current;
    if (stopping?.phase === 'preparing' && stopping.visit !== controlVisit) {
      stopFlightRef.current = null;
    }
  }, [controlVisit]);

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
      retryIntent: 'recover' | 'retry' = 'recover',
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
        stopFlightRef.current ||
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

      const sendGeneration = ++sendGenerationRef.current;
      let clientRequestId = retryClientRequestId || secureRandomUuid();
      const ownedConversationGeneration = conversationGeneration.current;
      const flight = {
        requestId: clientRequestId,
        conversationScope,
        conversationGeneration: ownedConversationGeneration,
      };
      sendFlightRef.current = flight;
      selectedAttachments.forEach(file =>
        inFlightAttachmentIds.current.add(file.uploadId),
      );
      const noticeVisit = noticeVisitRef.current;
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

        let attemptStartedAt = Date.now();
        let response = retryClientRequestId
          ? await pollCourseAssistantTurn(retryClientRequestId)
          : undefined;
        if (!ownsTurn()) return;
        const retrySendAllowed = Boolean(
          retryClientRequestId &&
            retryIntent === 'retry' &&
            !recoveryOnly &&
            response?.turnStatus === 'failed' &&
            courseChatFailureCanStartFreshTurn(response.canRetry),
        );
        // A missing status is not a terminal receipt: the original POST may
        // still be admitted after its acknowledgement timed out. Re-send only
        // on explicit Retry and retain its idempotency identity in that case.
        const freshRetryAllowed =
          retrySendAllowed && response?.code !== 'chat_turn_not_found';
        let uploadedAttachments = selectedAttachments;
        if (!recoveryOnly && (!retryClientRequestId || retrySendAllowed)) {
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
          assertAccountSessionBoundary(turnBoundary);
          if (!ownsTurn()) return;
          commitMessages(rendered => {
            queuedMessages = markTurnUploadComplete(
              rendered,
              userMessage!.id,
              uploadedAttachments,
            );
            return queuedMessages;
          });
          await saveCourseChatHistory(
            courseId,
            queuedMessages,
            lessonId,
            turnBoundary,
          );
          assertAccountSessionBoundary(turnBoundary);
          // The server ids are durable now. Removing obsolete local copies
          // cannot own submission of the question that already references them.
          void Promise.all(
            selectedAttachments
              .filter(file => file.uri && !file.serverId)
              .map(removeLearnerDraftFile),
          ).catch(() => undefined);
          if (!ownsTurn()) return;
        }

        const attachmentIds = uploadedAttachments
          .map(file => file.serverId)
          .filter((id): id is string => Boolean(id));
        const requestCourse = course;
        if (freshRetryAllowed) {
          clientRequestId = secureRandomUuid();
          flight.requestId = clientRequestId;
          commitMessages(rendered => {
            queuedMessages = replaceTurnClientRequestId(
              rendered,
              userMessage!.id,
              pendingId,
              clientRequestId,
            );
            return queuedMessages;
          });
          await saveCourseChatHistory(
            courseId,
            queuedMessages,
            lessonId,
            turnBoundary,
          );
          assertAccountSessionBoundary(turnBoundary);
        }
        if (!response || retrySendAllowed) {
          if (!ownsTurn()) return;
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
        assertAccountSessionBoundary(turnBoundary);
        if (response.blocked) recordServerBlock(response.code);
        // The receipt still settles into its conversation after closing, but
        // a native notice belongs to the visit that began this send/recovery.
        // Returning later must not revive the departed visit's dialog.
        if (
          response.code === 'chat_daily_limit_reached' &&
          noticeMountedRef.current &&
          noticeVisitRef.current === noticeVisit &&
          noticeVisit.interactive
        ) {
          const message = subscriptionMessages.chatDailyLimit;
          Alert.alert(message.title, message.body, [{text: message.action}]);
        }
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
    ],
  );

  const send = useCallback(() => void runTurn(), [runTurn]);
  const isSendInFlight = useCallback(
    () => Boolean(sendFlightRef.current || stopFlightRef.current),
    [],
  );
  const getInFlightRequestId = useCallback(
    () => {
      const stopping = stopFlightRef.current;
      if (
        stopping?.phase === 'dispatched' &&
        stopping.conversation === activeConversation.current &&
        stopping.generation === conversationGeneration.current
      ) return stopping.requestId;
      const flight = sendFlightRef.current;
      if (
        !flight ||
        flight.conversationScope !== activeConversation.current ||
        flight.conversationGeneration !== conversationGeneration.current
      ) return undefined;
      return flight.requestId;
    },
    [activeConversation, conversationGeneration],
  );
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
        'retry',
      );
    },
    [messagesRef, runTurn],
  );

  const stop = useCallback(async () => {
    if (
      !noticeMountedRef.current ||
      !controlVisit.interactive ||
      noticeVisitRef.current !== controlVisit ||
      activeConversation.current !== conversationScope ||
      hydratedConversation.current !== conversationScope ||
      stopFlightRef.current
    ) return;
    const pending = [...messagesRef.current]
      .reverse()
      .find(
        item =>
          item.role === 'assistant' &&
          item.clientRequestId &&
          courseChatTurnIsUnresolved(item.deliveryStatus),
      );
    if (!pending?.clientRequestId) return;
    const stopFlight = {
      conversation: conversationScope,
      generation: conversationGeneration.current,
      requestId: pending.clientRequestId,
      phase: 'preparing' as 'preparing' | 'dispatched',
      visit: controlVisit,
    };
    stopFlightRef.current = stopFlight;
    const stoppedRequestId = pending.clientRequestId;
    let boundary: AccountSessionBoundary | undefined;
    let recoverAfterStop = false;
    const ownsStop = () =>
      noticeMountedRef.current &&
      stopFlightRef.current === stopFlight &&
      stopFlight.generation === conversationGeneration.current &&
      activeConversation.current === conversationScope;
    try {
      boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      if (
        !ownsStop() ||
        noticeVisitRef.current !== controlVisit ||
        activeAccountScope.current !== boundary.scope ||
        !messagesRef.current.some(message =>
          message.role === 'assistant' &&
          message.clientRequestId === stoppedRequestId &&
          courseChatTurnIsUnresolved(message.deliveryStatus),
        )
      ) return;
      // From this point the same-account cancellation receipt belongs to the
      // durable turn, even if the learner closes the chat while DELETE runs.
      stopFlight.phase = 'dispatched';
      const stoppedSendFlight = sendFlightRef.current;
      sendGenerationRef.current += 1;
      setSending(false);
      commitMessages(current =>
        markCourseChatTurnStopping(current, stoppedRequestId),
      );
      const cancelledAtServer = await cancelCourseAssistantTurn(
        stoppedRequestId,
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      if (!ownsStop()) return;
      resumeInterruptedTurnRef.current = !cancelledAtServer;
      if (cancelledAtServer && sendFlightRef.current === stoppedSendFlight) {
        sendFlightRef.current = null;
      }
      commitMessages(current =>
        settleCourseChatCancellation(
          current,
          stoppedRequestId,
          cancelledAtServer,
        ),
      );
      recoverAfterStop = !cancelledAtServer;
    } catch {
      if (!ownsStop() || stopFlight.phase !== 'dispatched' || !boundary) return;
      try {assertAccountSessionBoundary(boundary);} catch {return;}
      resumeInterruptedTurnRef.current = true;
      commitMessages(current =>
        settleCourseChatCancellation(current, stoppedRequestId, false),
      );
      recoverAfterStop = true;
    } finally {
      if (stopFlightRef.current === stopFlight) {
        stopFlightRef.current = null;
        if (recoverAfterStop) setRecoverySignal(value => value + 1);
      }
    }
  }, [
    activeAccountScope,
    activeConversation,
    commitMessages,
    controlVisit,
    conversationGeneration,
    conversationScope,
    hydratedConversation,
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
      stopFlightRef.current ||
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

  return {getInFlightRequestId, isSendInFlight, retry, send, sending, stop};
};
