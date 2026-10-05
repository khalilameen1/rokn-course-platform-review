import {useCallback, useState} from 'react';
import {
  useCourseUpgradeOffer,
  type CourseUpgradeOfferStatus,
} from '../../../hooks/useCourseUpgradeOffer';
import {courseAssistantEntryMode} from '../courseEntitlements';

export type CourseChatUpgradeStatus = CourseUpgradeOfferStatus;

/** Offer discovery only. CourseSubscriptionSheet owns all purchase/recovery. */
export const useCourseChatUpgrade = ({
  accountKey,
  accessType,
  chatAvailable,
  chatEntitlementRevision,
  courseId,
  active,
}: {
  accountKey: string;
  accessType?: string;
  chatAvailable?: boolean;
  chatEntitlementRevision?: string;
  courseId: string;
  active: boolean;
}) => {
  const scope = JSON.stringify([
    accountKey,
    courseId,
    accessType,
    chatAvailable,
    chatEntitlementRevision,
  ]);
  const [block, setBlock] = useState({scope, code: ''});
  const serverBlockCode = block.scope === scope ? block.code : '';
  const entryMode = courseAssistantEntryMode({accessType, chatAvailable});
  const needsOffer =
    entryMode === 'upgrade' ||
    (entryMode === 'included' &&
      ['chat_plan_limit_reached', 'chat_upgrade_required'].includes(
        serverBlockCode,
      ));
  const blockedAccess = [
    'course_not_available',
    'course_access_required',
    'chat_disabled_for_course',
  ].includes(serverBlockCode);

  const offer = useCourseUpgradeOffer({
    ownerKey: JSON.stringify([scope, serverBlockCode]),
    courseId,
    requiredFeature: 'chat',
    active: active && needsOffer && !blockedAccess,
  });

  const recordServerBlock = useCallback(
    (code?: string) => {
      setBlock({scope, code: code || 'chat_upgrade_required'});
    },
    [scope],
  );
  return {
    recordServerBlock,
    serverBlockCode,
    upgradeStatus: offer.status,
    retryUpgradeQuote: offer.retry,
  };
};
