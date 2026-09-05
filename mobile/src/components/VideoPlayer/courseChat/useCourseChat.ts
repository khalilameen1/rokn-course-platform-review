import {useRef} from 'react';
import {useSelector} from 'react-redux';

import {courseIncludesAssistant} from '../courseLearningApi';
import {isGrantCourseAccess} from '../courseEntitlements';
import type {CourseLearningData, CourseReel} from '../types';
import {useAppActiveState} from '../../../hooks/useAppActiveState';
import {sessionIdentityKey} from '../../../constants/helpers';
import type {RootState} from '../../../store/store';
import {assistantPresenceFor} from './conversation';
export type {AssistantPresence} from './conversation';
import {courseChatTurnIsUnresolved} from './policy';
import {useCourseChatConversation} from './useCourseChatConversation';
import {useCourseChatScroll} from './useCourseChatScroll';
import {useCourseChatTurn} from './useCourseChatTurn';
import {useCourseChatUpgrade} from './useCourseChatUpgrade';

export const useCourseChat = ({
  visible,
  course,
  reel,
  onOpenWallet,
}: {
  visible: boolean;
  course: CourseLearningData;
  reel?: CourseReel;
  onOpenWallet: () => void;
}) => {
  const courseId = course.id;
  const lessonId = reel?.lessonId;
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const accountKey = sessionIdentityKey(storedUser);
  const conversationScope = `${accountKey}:${courseId}:${lessonId || 'course'}`;
  const appIsActive = useAppActiveState();
  const interactive = visible && appIsActive;
  const inFlightAttachmentIdsRef = useRef(new Set<string>());
  const {scheduleScrollToEnd, scrollRef} = useCourseChatScroll(visible);
  const upgrade = useCourseChatUpgrade({
    accountKey,
    accessType: course.accessType,
    chatAvailable: course.chatAvailable,
    courseId,
    onOpenWallet,
  });
  const assistantEntitled = courseIncludesAssistant(course) || upgrade.upgraded;
  const assistantIncluded = assistantEntitled && !upgrade.serverBlockCode;
  const conversation = useCourseChatConversation({
    courseId,
    lessonId,
    conversationScope,
    inFlightAttachmentIds: inFlightAttachmentIdsRef,
    remoteEnabled: assistantEntitled,
  });
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
    upgraded: upgrade.upgraded,
  });
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
    attachments: conversation.attachments,
    chatAccessUnavailable: [
      'course_not_available',
      'course_access_required',
      'chat_disabled_for_course',
    ].includes(upgrade.serverBlockCode),
    confirmUpgrade: upgrade.confirmUpgrade,
    input: conversation.input,
    isSendInFlight: turn.isSendInFlight,
    loadUpgradeQuote: upgrade.loadUpgradeQuote,
    messages: conversation.messages,
    planLimitReached: upgrade.serverBlockCode === 'chat_plan_limit_reached',
    retry: turn.retry,
    scholarshipAccess: isGrantCourseAccess(course.accessType),
    scrollRef,
    send: turn.send,
    sending: turn.sending,
    setAttachments: conversation.commitAttachments,
    setInput: conversation.setInput,
    stop: turn.stop,
    upgradeError: upgrade.upgradeError,
    upgradeLoading: upgrade.upgradeLoading,
    upgradeQuote: upgrade.upgradeQuote,
  };
};
