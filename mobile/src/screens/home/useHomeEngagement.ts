import {useCallback, useEffect, useRef, useState} from 'react';
import type {RootNavigation} from '../../navigation/types';
import type {Course} from '../../types/Course';
import {claimDailyReward, markNotificationRead} from '../../services/roknApi';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {getNotificationsPage} from '../../services/api/notifications';
import {trackProductEvent} from '../../services/productAnalytics';
import {
  type EngagementMessage,
  getEngagementMessage,
} from '../../services/api/engagement';
import {
  clearPendingWelcomeBonus,
  getPendingWelcomeBonus,
} from '../../services/pendingWelcomeBonus';
import {serverNowMs} from '../../utils/serverClock';
import {roknCalendarDay} from '../../constants/roknCalendar';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import {useStartupExperience} from '../appInitializer/StartupExperience';
import {
  createHomePresentationSession,
  WELCOME_PRESENTED_KEY,
} from '../appInitializer/homePresentationSession';
import type {HomeCampaign} from './HomeOverlays';

const receiptKey = (path: string, boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(`@rokn/home-receipt/${path}`, boundary);
type HomeEngagementInput = {
  active: boolean;
  identityKey: string;
  loading: boolean;
  navigation: RootNavigation;
  openCourse: (course: Pick<Course, 'id'>) => boolean;
  serverSession: boolean | null;
};

export const useHomeEngagement = ({
  active,
  identityKey,
  loading,
  navigation,
  openCourse,
  serverSession,
}: HomeEngagementInput) => {
  const startup = useStartupExperience();
  const fallbackSession = useRef(createHomePresentationSession()).current;
  const presentationSession = startup?.presentationSession || fallbackSession;
  const readyForPrompts = startup?.readyForPrompts ?? true;
  const [campaign, setCampaign] = useState<HomeCampaign | null>(null);
  const [campaignImageFailed, setCampaignImageFailed] = useState(false);
  const [guestPrompt, setGuestPrompt] = useState<EngagementMessage | null>(
    null,
  );
  const [bonusChecked, setBonusChecked] = useState(false);
  const presentedBoundaryRef = useRef<AccountSessionBoundary | null>(null);
  const presentedIdentityRef = useRef<string | null>(null);
  const rewardFlightRef = useRef<{
    identityKey: string;
    promise: Promise<boolean>;
  } | null>(null);
  const rewardAttemptRef = useRef('');

  // Daily credit stays automatic and idempotent. Presentation never grants coins.
  useEffect(() => {
    if (!active || serverSession !== true) return;
    const attempt = `${identityKey}:${roknCalendarDay(
      new Date(serverNowMs()),
    )}`;
    if (
      rewardFlightRef.current?.identityKey === identityKey ||
      rewardAttemptRef.current === attempt
    )
      return;
    rewardAttemptRef.current = attempt;
    const promise = captureAccountSessionBoundary()
      .then(boundary => claimDailyReward(boundary))
      .then(
        () => true,
        () => {
          if (rewardAttemptRef.current === attempt)
            rewardAttemptRef.current = '';
          return false;
        },
      );
    rewardFlightRef.current = {identityKey, promise};
    void promise.finally(() => {
      if (rewardFlightRef.current?.promise === promise)
        rewardFlightRef.current = null;
    });
  }, [active, identityKey, serverSession]);

  useEffect(() => {
    let current = true;
    setBonusChecked(false);
    setGuestPrompt(null);
    setCampaign(null);
    setCampaignImageFailed(false);
    presentedBoundaryRef.current = null;
    // Retire the old post-login dialog receipt silently, not the wallet credit.
    void captureAccountSessionBoundary()
      .then(async boundary => {
        const pending = await getPendingWelcomeBonus(boundary);
        assertAccountSessionBoundary(boundary);
        if (!current) return;
        if (pending !== null) {
          presentationSession.suppress();
          void clearPendingWelcomeBonus(boundary).catch(() => undefined);
        }
        setBonusChecked(true);
      })
      .catch(() => {
        if (current) setBonusChecked(true);
      });
    return () => {
      current = false;
      presentedBoundaryRef.current = null;
    };
  }, [identityKey, presentationSession]);

  useEffect(() => {
    if (
      !active ||
      !readyForPrompts ||
      loading ||
      !bonusChecked ||
      serverSession === null
    )
      return;
    presentationSession.observeIdentity(identityKey);
    if (!presentationSession.available()) return;
    let current = true;
    const controller = new AbortController();
    const load = async () => {
      const boundary = await captureAccountSessionBoundary();
      if (!serverSession) {
        // Installation-owned: logout or a template edit cannot repeat the gift.
        if (await getItem(WELCOME_PRESENTED_KEY)) return;
        assertAccountSessionBoundary(boundary);
        const message = await getEngagementMessage(
          'guest_registration_prompt',
          boundary,
        );
        assertAccountSessionBoundary(boundary);
        if (
          !current ||
          !message ||
          message.coins <= 0 ||
          !presentationSession.reserve()
        )
          return;
        presentedBoundaryRef.current = boundary;
        presentedIdentityRef.current = identityKey;
        setGuestPrompt(message);
        void saveItem(WELCOME_PRESENTED_KEY, true).catch(() => undefined);
        return;
      }
      // Existing backend campaign targeting selects recipients. Only course
      // announcements open a Home card, never reports, certificates or tasks.
      let cursor: string | null = null;
      const visited = new Set<string>();
      while (current && presentationSession.available()) {
        const page = await getNotificationsPage({
          surface: 'home',
          cursor,
          signal: controller.signal,
          ownerBoundary: boundary,
        });
        assertAccountSessionBoundary(boundary);
        if (!current || !presentationSession.available()) return;
        for (const notification of page.notifications) {
          const course = notification.homeCourse;
          if (!course) continue;
          const seen = await getItem(
            await receiptKey(`campaign/${notification.id}`, boundary),
          );
          assertAccountSessionBoundary(boundary);
          if (!current || !presentationSession.available()) return;
          if (seen) continue;
          if (!presentationSession.reserve()) return;
          presentedBoundaryRef.current = boundary;
          presentedIdentityRef.current = identityKey;
          setCampaignImageFailed(false);
          setCampaign({
            id: notification.id,
            title: course.title,
            description: '',
            courseId: course.id,
            image: {uri: course.imageUrl},
            actionLabel: 'ابدأ الكورس',
            badge: 'جديد',
          });
          return;
        }
        if (!page.hasMore) return;
        if (!page.nextCursor || visited.has(page.nextCursor))
          throw new Error('HOME_NOTIFICATIONS_CURSOR_INVALID');
        visited.add(page.nextCursor);
        cursor = page.nextCursor;
      }
    };
    void load().catch(() => undefined);
    return () => {
      current = false;
      controller.abort();
    };
  }, [
    active,
    bonusChecked,
    identityKey,
    loading,
    presentationSession,
    readyForPrompts,
    serverSession,
  ]);

  const dismissGuest = useCallback(() => {
    setGuestPrompt(null);
    presentedBoundaryRef.current = null;
  }, []);
  const openGuest = useCallback(() => {
    const boundary = presentedBoundaryRef.current;
    if (!boundary || presentedIdentityRef.current !== identityKey) return;
    assertAccountSessionBoundary(boundary);
    dismissGuest();
    openGuestLogin(navigation);
  }, [dismissGuest, identityKey, navigation]);
  const dismissCampaign = useCallback(
    async (open = false) => {
      const selected = campaign;
      const boundary = presentedBoundaryRef.current;
      if (
        !selected ||
        !boundary ||
        presentedIdentityRef.current !== identityKey
      )
        return;
      assertAccountSessionBoundary(boundary);
      setCampaign(null);
      setCampaignImageFailed(false);
      presentedBoundaryRef.current = null;
      // Closing this Home card is a local presentation decision, not a network
      // ACK. Keep its receipt independent of the inbox read and navigation.
      void (async () => {
        const seen = await receiptKey(`campaign/${selected.id}`, boundary);
        assertAccountSessionBoundary(boundary);
        await saveItem(seen, true);
        assertAccountSessionBoundary(boundary);
      })().catch(() => undefined);
      // A failed/stalled disk write must not stop the existing server read,
      // either. A failed ACK may leave the inbox unread, not the Home card unseen.
      void markNotificationRead(selected.id, boundary).catch(() => undefined);
      if (!open || !selected.courseId || !openCourse({id: selected.courseId}))
        return;
      void trackProductEvent({
        event_name: 'notification_opened',
        source: 'notification',
        screen_key: 'home',
        campaign_key: selected.id,
        course_id: selected.courseId,
      });
      void trackProductEvent({
        event_name: 'course_opened',
        source: 'notification',
        screen_key: 'course_details',
        campaign_key: selected.id,
        course_id: selected.courseId,
      });
    },
    [campaign, identityKey, openCourse],
  );

  return {
    campaign,
    campaignImageFailed,
    dismissCampaign,
    dismissGuest,
    guestPrompt,
    markCampaignImageFailed: () => setCampaignImageFailed(true),
    openGuest,
  };
};
