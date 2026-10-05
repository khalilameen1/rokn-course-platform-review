import {useCallback, useEffect, useRef, useState} from 'react';
import {useSelector} from 'react-redux';

import {courseIncludesAssistant} from '../courseLearningApi';
import {isGrantCourseAccess} from '../courseEntitlements';
import type {CourseLearningData, CourseReel} from '../types';
import {useAppForegroundState} from '../../../hooks/useAppActiveState';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  sessionIdentityKey,
} from '../../../constants/helpers';
import {requestAiConsent} from '../../../services/aiConsent';
import type {RootState} from '../../../store/store';
import {assistantPresenceFor} from './conversation';
export type {AssistantPresence} from './conversation';
import {courseChatTurnIsUnresolved} from './policy';
import {useCourseChatConversation} from './useCourseChatConversation';
import {useCourseChatAttachments} from './useCourseChatAttachments';
import {useCourseChatScroll} from './useCourseChatScroll';
import {useCourseChatTurn} from './useCourseChatTurn';
import {useCourseChatUpgrade} from './useCourseChatUpgrade';

export const useCourseChat = ({
  visible,
  course,
  reel,
}: {
  visible: boolean;
  course: CourseLearningData;
  reel?: CourseReel;
}) => {
  const courseId = course.id;
  const lessonId = reel?.lessonId;
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const accountKey = sessionIdentityKey(storedUser);
  const conversationScope = `${accountKey}:${courseId}:${lessonId || 'course'}`;
  const appIsActive = useAppForegroundState();
  const interactive = visible && appIsActive;
  const inFlightAttachmentIdsRef = useRef(new Set<string>());
  const inFlightRequestIdRef = useRef<() => string | undefined>(
    () => undefined,
  );
  const {scheduleScrollToEnd, scrollRef} = useCourseChatScroll(visible);
  const upgrade = useCourseChatUpgrade({
    accountKey,
    accessType: course.accessType,
    chatAvailable: course.chatAvailable,
    chatEntitlementRevision: course.chatEntitlementRevision,
    courseId,
    active: interactive,
  });
  const assistantEntitled = courseIncludesAssistant(course);
  const assistantIncluded = assistantEntitled && !upgrade.serverBlockCode;
  const conversation = useCourseChatConversation({
    courseId,
    lessonId,
    conversationScope,
    inFlightAttachmentIds: inFlightAttachmentIdsRef,
    inFlightRequestId: inFlightRequestIdRef,
    active: interactive,
    remoteEnabled: assistantEntitled,
  });
  const {
    activeAccountScopeRef,
    conversationGenerationRef,
    commitAttachments,
    setInput: setConversationInput,
  } = conversation;
  const turn = useCourseChatTurn({
    activeAccountScope: conversation.activeAccountScopeRef,
    activeConversation: conversation.activeConversationRef,
    assistantIncluded,
    attachmentsRef: conversation.attachmentsRef,
    commitAttachments: conversation.commitAttachments,
    commitMessages: conversation.commitMessages,
    conversationGeneration: conversation.conversationGenerationRef,
    conversationScope,
    course,
    hydratedConversation: conversation.hydratedConversationRef,
    hydrationRecoveryRevision: conversation.recoveryRevision,
    inFlightAttachmentIds: inFlightAttachmentIdsRef,
    input: conversation.input,
    interactive,
    lessonId,
    messagesRef: conversation.messagesRef,
    recordServerBlock: upgrade.recordServerBlock,
    reel,
    scheduleScrollToEnd,
    setInput: conversation.setInput,
  });
  const {
    isSendInFlight: turnIsSendInFlight,
    send: sendTurn,
    retry: retryTurn,
  } = turn;
  inFlightRequestIdRef.current = turn.getInFlightRequestId;
  const available = interactive && assistantIncluded && conversation.hydrated;
  const consentVisitRef = useRef({scope: conversationScope, available});
  if (
    consentVisitRef.current.scope !== conversationScope ||
    consentVisitRef.current.available !== available
  ) {
    consentVisitRef.current = {scope: conversationScope, available};
  }
  const consentVisit = consentVisitRef.current;
  const consentMountedRef = useRef(false);
  const consentFlightRef = useRef<{visit: typeof consentVisit} | null>(null);
  const [consentPending, setConsentPending] = useState(false);
  useEffect(() => {
    consentMountedRef.current = true;
    setConsentPending(false);
    return () => {
      consentMountedRef.current = false;
      if (consentFlightRef.current?.visit === consentVisit)
        consentFlightRef.current = null;
    };
  }, [consentVisit]);

  const isSendInFlight = useCallback(
    () => Boolean(consentFlightRef.current) || turnIsSendInFlight(),
    [turnIsSendInFlight],
  );
  const setInput = useCallback(
    (value: Parameters<typeof setConversationInput>[0]) => {
      if (!consentFlightRef.current) setConversationInput(value);
    },
    [setConversationInput],
  );
  const setAttachments = useCallback(
    (value: Parameters<typeof commitAttachments>[0]) => {
      if (!consentFlightRef.current) commitAttachments(value);
    },
    [commitAttachments],
  );
  const {pickAttachments, pickerIsActive} = useCourseChatAttachments({
    appIsActive,
    attachmentsRef: conversation.attachmentsRef,
    conversationScope,
    enabled:
      conversation.hydrated &&
      assistantIncluded &&
      Boolean(course.chatAttachmentsEnabled),
    isSendInFlight,
    limit: Math.max(0, course.chatAttachmentMaxFiles || 0),
    sending: turn.sending,
    setAttachments,
    visible,
  });

  // Consent is preparation, not a paid turn. Own it by this conversation visit
  // so closing/reopening cannot dispatch the earlier tap into a later visit.
  const withConsent = useCallback(
    async (action: () => void) => {
      const visit = consentVisitRef.current;
      if (
        !consentMountedRef.current ||
        !visit.available ||
        consentFlightRef.current ||
        turnIsSendInFlight() ||
        pickerIsActive()
      )
        return;
      const flight = {visit};
      const generation = conversationGenerationRef.current;
      consentFlightRef.current = flight;
      setConsentPending(true);
      const ownsAction = () =>
        consentMountedRef.current &&
        consentVisitRef.current === visit &&
        consentFlightRef.current === flight &&
        conversationGenerationRef.current === generation;
      try {
        const boundary = await captureAccountSessionBoundary();
        if (!ownsAction() || activeAccountScopeRef.current !== boundary.scope)
          return;
        const ownsConsent = () => {
          if (!ownsAction()) return false;
          try {
            assertAccountSessionBoundary(boundary);
            return true;
          } catch {
            return false;
          }
        };
        if (!(await requestAiConsent(boundary, ownsConsent)) || !ownsConsent())
          return;
        // The turn owner now takes over delivery and recovery. Retiring this
        // consent visit afterward must not cancel an already dispatched question.
        action();
      } finally {
        if (consentFlightRef.current === flight) {
          consentFlightRef.current = null;
          setConsentPending(false);
        }
      }
    },
    [
      activeAccountScopeRef,
      conversationGenerationRef,
      pickerIsActive,
      turnIsSendInFlight,
    ],
  );
  const send = useCallback(() => {
    void withConsent(sendTurn).catch(() => undefined);
  }, [sendTurn, withConsent]);
  const retry = useCallback(
    (requestId: string) => {
      void withConsent(() => retryTurn(requestId)).catch(() => undefined);
    },
    [retryTurn, withConsent],
  );
  const answerPending = conversation.messages.some(
    message =>
      message.role === 'assistant' &&
      Boolean(message.clientRequestId) &&
      courseChatTurnIsUnresolved(message.deliveryStatus),
  );

  return {
    answerPending,
    assistantPresence: assistantPresenceFor(conversation.messages),
    assistantIncluded,
    consentPending,
    attachments: conversation.attachments,
    chatAccessUnavailable: [
      'course_not_available',
      'course_access_required',
      'chat_disabled_for_course',
    ].includes(upgrade.serverBlockCode),
    hydrated: conversation.hydrated,
    hydrationError: conversation.hydrationError,
    retryHydration: conversation.retryHydration,
    input: conversation.input,
    isSendInFlight,
    messages: conversation.messages,
    pickAttachments,
    planLimitReached: upgrade.serverBlockCode === 'chat_plan_limit_reached',
    retry,
    scholarshipAccess: isGrantCourseAccess(course.accessType),
    scrollRef,
    send,
    sending: turn.sending,
    setAttachments,
    setInput,
    stop: turn.stop,
    upgradeStatus: upgrade.upgradeStatus,
    retryUpgradeQuote: upgrade.retryUpgradeQuote,
  };
};
