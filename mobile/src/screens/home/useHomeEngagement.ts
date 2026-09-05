import {CommonActions} from '@react-navigation/native';
import {useCallback, useEffect, useRef, useState} from 'react';
import type {RootNavigation} from '../../navigation/types';
import type {Course} from '../../types/Course';
import {
  claimDailyReward,
  getNotifications,
  markNotificationRead,
  type Notification as NotificationDto,
} from '../../services/roknApi';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {parseRoknDestination} from '../../navigation/deepLinks';
import {trackProductEvent} from '../../services/productAnalytics';
import {
  type EngagementMessage,
  getEngagementMessage,
  getNextEngagementMessage,
} from '../../services/api/engagement';
import {
  clearPendingWelcomeBonus,
  getPendingWelcomeBonus,
} from '../../services/pendingWelcomeBonus';
import {serverNowMs} from '../../utils/serverClock';
import {roknCalendarDay} from '../../constants/roknCalendar';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import type {HomeCampaign} from './HomeOverlays';

const receiptKey = (path: string, boundary?: AccountSessionBoundary) =>
  accountScopedStorageKey(`@rokn/home-receipt/${path}`, boundary);

const saveReceipt = async (
  path: string,
  value: unknown,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  await saveItem(await receiptKey(path, boundary), value);
  assertAccountSessionBoundary(boundary);
};

const withinCooldown = (receipt: unknown, hours: number) => {
  if (receipt === true) return true;
  const shownAt = Number(receipt || 0);
  if (!Number.isFinite(shownAt) || shownAt <= 0) return false;
  const elapsed = serverNowMs() - shownAt;
  return elapsed >= 0 && elapsed < Math.max(1, hours) * 60 * 60 * 1000;
};

const welcomeMessage = (
  notification: NotificationDto,
  coins: number,
): EngagementMessage | null =>
  parseRoknDestination(notification.link)
    ? {
        id: notification.id,
        key: 'welcome_bonus_received',
        title: notification.title,
        description: notification.description,
        actionLabel: notification.actionLabel,
        secondaryActionLabel: '',
        imageUrl: notification.imageUrl,
        link: notification.link,
        coins,
        dismissible: true,
        cooldownHours: 0,
        version: notification.campaignId || notification.id,
        campaignKey: notification.campaignId,
      }
    : null;

type HomeEngagementInput = {
  active: boolean;
  identityKey: string;
  loading: boolean;
  navigation: RootNavigation;
  openCourse: (course: Pick<Course, 'id'>) => boolean;
  remoteCourses: Course[] | null;
  serverSession: boolean | null;
};

export const useHomeEngagement = ({
  active,
  identityKey,
  loading,
  navigation,
  openCourse,
  remoteCourses,
  serverSession,
}: HomeEngagementInput) => {
  const [bonus, setBonus] = useState<number | null>(null);
  const [bonusChecked, setBonusChecked] = useState(false);
  const [campaign, setCampaign] = useState<HomeCampaign | null>(null);
  const [campaignImageFailed, setCampaignImageFailed] = useState(false);
  const [guestPrompt, setGuestPrompt] = useState<EngagementMessage | null>(
    null,
  );
  const [welcome, setWelcome] = useState<EngagementMessage | null>(null);
  const [rewardPrompt, setRewardPrompt] = useState<EngagementMessage | null>(
    null,
  );
  const rewardFlightRef = useRef<{
    identityKey: string;
    promise: Promise<boolean>;
  } | null>(null);
  const rewardAttemptRef = useRef('');
  const presentedIdentityRef = useRef<string | null>(null);
  const presentedBoundaryRef = useRef<AccountSessionBoundary | null>(null);

  useEffect(() => {
    if (!active || serverSession !== true) return;
    const attempt = `${identityKey}:${roknCalendarDay(
      new Date(serverNowMs()),
    )}`;
    if (
      rewardFlightRef.current?.identityKey === identityKey ||
      rewardAttemptRef.current === attempt
    ) {
      return;
    }
    rewardAttemptRef.current = attempt;
    const promise = captureAccountSessionBoundary()
      .then(boundary => claimDailyReward(boundary))
      .then(
        () => true,
        () => {
          // A transport failure may mean either that the idempotent award was
          // accepted or that it never reached the server. Allow the next
          // foreground activation to reconcile by calling the same endpoint.
          if (rewardAttemptRef.current === attempt) {
            rewardAttemptRef.current = '';
          }
          return false;
        },
      );
    rewardFlightRef.current = {identityKey, promise};
    void promise.finally(() => {
      if (rewardFlightRef.current?.promise === promise) {
        rewardFlightRef.current = null;
      }
    });
  }, [active, identityKey, serverSession]);

  useEffect(() => {
    let current = true;
    setBonusChecked(false);
    setBonus(null);
    setWelcome(null);
    setGuestPrompt(null);
    setRewardPrompt(null);
    setCampaign(null);
    setCampaignImageFailed(false);
    presentedBoundaryRef.current = null;
    void captureAccountSessionBoundary()
      .then(boundary => getPendingWelcomeBonus(boundary))
      .then(value => {
        if (!current) return;
        const amount = Number(value || 0);
        setBonus(amount > 0 ? amount : null);
        setBonusChecked(true);
      })
      .catch(() => {
        if (current) setBonusChecked(true);
      });
    return () => {
      current = false;
    };
  }, [identityKey]);

  useEffect(() => {
    if (!active || !bonusChecked || loading || serverSession === null) return;
    if (presentedIdentityRef.current === identityKey) return;
    let current = true;

    const load = async () => {
      const boundary = await captureAccountSessionBoundary();
      if (bonus !== null) {
        const notification = (await getNotifications(boundary)).find(
          item =>
            /^registration-bonus:\d+$/.test(item.campaignId || '') &&
            item.kind === 'coin_reward',
        );
        assertAccountSessionBoundary(boundary);
        const message = notification
          ? welcomeMessage(notification, bonus)
          : null;
        if (
          current &&
          message &&
          presentedIdentityRef.current !== identityKey
        ) {
          presentedIdentityRef.current = identityKey;
          presentedBoundaryRef.current = boundary;
          setWelcome(message);
        }
        return;
      }

      if (serverSession) {
        setGuestPrompt(null);
        const message = await getNextEngagementMessage(boundary);
        assertAccountSessionBoundary(boundary);
        if (message && current && parseRoknDestination(message.link)) {
          const identity = message.campaignKey!;
          const seen = await receiptKey(`engagement/${identity}`, boundary);
          if (
            !withinCooldown(await getItem(seen), message.cooldownHours || 72)
          ) {
            assertAccountSessionBoundary(boundary);
            if (current && presentedIdentityRef.current !== identityKey) {
              presentedIdentityRef.current = identityKey;
              presentedBoundaryRef.current = boundary;
              setRewardPrompt(message);
            }
            return;
          }
        }

        const notification = (await getNotifications(boundary)).find(
          item =>
            (item.kind === 'course_recommendation' ||
              item.kind === 'new_course') &&
            !item.read,
        );
        assertAccountSessionBoundary(boundary);
        if (!notification || !current) return;
        const seen = await receiptKey(`campaign/${notification.id}`, boundary);
        if (await getItem(seen)) return;
        assertAccountSessionBoundary(boundary);
        const destination = parseRoknDestination(notification.link);
        const courseId =
          notification.courseId ||
          (destination?.name === 'CourseDetails' ||
          destination?.name === 'Reels'
            ? destination.params.courseId
            : undefined);
        if (!courseId) return;
        const course = (remoteCourses ?? []).find(item => item.id === courseId);
        if (
          !course ||
          course.published === false ||
          course.owned === true ||
          presentedIdentityRef.current === identityKey
        ) {
          return;
        }
        presentedIdentityRef.current = identityKey;
        presentedBoundaryRef.current = boundary;
        setCampaignImageFailed(false);
        setCampaign({
          id: notification.id,
          title: notification.title,
          description: notification.description,
          courseId,
          image: notification.imageUrl
            ? {uri: notification.imageUrl}
            : undefined,
          actionLabel: notification.actionLabel,
        });
        return;
      }

      const message = await getEngagementMessage(
        'guest_registration_prompt',
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      if (!message || !current) return;
      const seen = await receiptKey(
        `engagement/${message.key}/${message.version}`,
        boundary,
      );
      if (await getItem(seen)) return;
      assertAccountSessionBoundary(boundary);
      if (presentedIdentityRef.current !== identityKey) {
        presentedIdentityRef.current = identityKey;
        presentedBoundaryRef.current = boundary;
        setGuestPrompt(message);
      }
    };

    void load().catch(() => undefined);
    return () => {
      current = false;
    };
  }, [
    active,
    bonus,
    bonusChecked,
    identityKey,
    loading,
    remoteCourses,
    serverSession,
  ]);

  const openDestination = useCallback(
    (message: EngagementMessage | null) => {
      const destination = parseRoknDestination(message?.link);
      if (!destination) return;
      navigation.dispatch(
        CommonActions.navigate(
          destination.name,
          'params' in destination ? destination.params : undefined,
        ),
      );
    },
    [navigation],
  );

  const dismissWelcome = useCallback(() => {
    const message = welcome;
    const boundary = presentedBoundaryRef.current;
    setBonus(null);
    setWelcome(null);
    presentedBoundaryRef.current = null;
    if (!boundary) return;
    void (async () => {
      assertAccountSessionBoundary(boundary);
      await clearPendingWelcomeBonus(boundary);
      if (message && /^\d+$/.test(message.id)) {
        await markNotificationRead(message.id, boundary);
      }
    })().catch(() => undefined);
  }, [welcome]);

  const openWelcome = useCallback(() => {
    const message = welcome;
    dismissWelcome();
    openDestination(message);
  }, [dismissWelcome, openDestination, welcome]);

  const dismissGuest = useCallback(() => {
    const message = guestPrompt;
    const boundary = presentedBoundaryRef.current;
    setGuestPrompt(null);
    presentedBoundaryRef.current = null;
    if (message && boundary) {
      void saveReceipt(
        `engagement/${message.key}/${message.version}`,
        true,
        boundary,
      ).catch(() => undefined);
    }
  }, [guestPrompt]);

  const openGuest = useCallback(() => {
    dismissGuest();
    openGuestLogin(navigation);
  }, [dismissGuest, navigation]);

  const dismissReward = useCallback(() => {
    const message = rewardPrompt;
    const boundary = presentedBoundaryRef.current;
    setRewardPrompt(null);
    presentedBoundaryRef.current = null;
    if (message && boundary) {
      const identity =
        message.campaignKey || `${message.key}/${message.taskId || message.id}`;
      void saveReceipt(`engagement/${identity}`, serverNowMs(), boundary).catch(
        () => undefined,
      );
    }
  }, [rewardPrompt]);

  const openReward = useCallback(() => {
    const message = rewardPrompt;
    dismissReward();
    openDestination(message);
  }, [dismissReward, openDestination, rewardPrompt]);

  const dismissCampaign = useCallback(
    async (open = false) => {
      const boundary =
        presentedBoundaryRef.current || (await captureAccountSessionBoundary());
      const current = campaign;
      setCampaign(null);
      setCampaignImageFailed(false);
      presentedBoundaryRef.current = null;
      if (!current) return;
      const seen = await receiptKey(`campaign/${current.id}`, boundary);
      if (serverSession === true) {
        try {
          await markNotificationRead(current.id, boundary);
          assertAccountSessionBoundary(boundary);
          await saveItem(seen, true);
          assertAccountSessionBoundary(boundary);
        } catch {
          assertAccountSessionBoundary(boundary);
        }
      } else {
        await saveItem(seen, true);
        assertAccountSessionBoundary(boundary);
      }
      if (!open || !current.courseId || !openCourse({id: current.courseId})) {
        return;
      }
      void trackProductEvent({
        event_name: 'notification_opened',
        source: 'notification',
        screen_key: 'home',
        campaign_key: current.id,
        course_id: current.courseId,
      });
      void trackProductEvent({
        event_name: 'course_opened',
        source: 'notification',
        screen_key: 'course_details',
        campaign_key: current.id,
        course_id: current.courseId,
      });
    },
    [campaign, openCourse, serverSession],
  );

  return {
    campaign,
    campaignImageFailed,
    dismissCampaign,
    dismissGuest,
    dismissReward,
    dismissWelcome,
    guestPrompt,
    markCampaignImageFailed: () => setCampaignImageFailed(true),
    openGuest,
    openReward,
    openWelcome,
    rewardPrompt,
    welcome,
  };
};
