import {NativeModules, Platform} from 'react-native';
import * as Notifications from 'expo-notifications';
import {
  AsyncKeys,
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {
  formatArabicDisplayText,
  formatRoknCoins,
} from '../constants/arabicFormatting';
import {
  NotificationKind,
  notificationDefaultAction,
  safeNotificationImageUrl,
} from './notificationCampaigns';

export const REMINDER_ENABLED_KEY = 'PREF_NOTIFICATIONS';
export const REMINDER_HOUR_KEY = 'PREF_REMINDER_HOUR';

const reminderEnabledStorageKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(REMINDER_ENABLED_KEY, boundary);
const reminderHourStorageKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(REMINDER_HOUR_KEY, boundary);

const getSmartRemindersEnabledFor = async (
  boundary: AccountSessionBoundary,
) => {
  const enabled =
    (await getItem(await reminderEnabledStorageKey(boundary))) === true;
  assertAccountSessionBoundary(boundary);
  return enabled;
};

export const getSmartRemindersEnabled = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  return getSmartRemindersEnabledFor(boundary);
};

export const setSmartRemindersEnabled = async (
  enabled: boolean,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const saved = await saveItem(
    await reminderEnabledStorageKey(boundary),
    enabled,
  );
  assertAccountSessionBoundary(boundary);
  return saved;
};

const getSmartReminderHourFor = async (boundary: AccountSessionBoundary) => {
  const hour = safeReminderHour(
    await getItem(await reminderHourStorageKey(boundary)),
  );
  assertAccountSessionBoundary(boundary);
  return hour;
};

export const getSmartReminderHour = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  return getSmartReminderHourFor(boundary);
};

export const setSmartReminderHour = async (
  hour: number,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const saved = await saveItem(
    await reminderHourStorageKey(boundary),
    safeReminderHour(hour),
  );
  assertAccountSessionBoundary(boundary);
  return saved;
};

type ReminderModule = {
  requestPermission: () => Promise<boolean>;
  schedule: (
    id: number,
    title: string,
    body: string,
    triggerAt: number,
    courseId?: string,
    link?: string,
    kind?: NotificationKind,
    imageUrl?: string,
    actionLabel?: string,
  ) => Promise<boolean>;
  preview: (
    title: string,
    body: string,
    link?: string,
    kind?: NotificationKind,
    imageUrl?: string,
    actionLabel?: string,
  ) => Promise<boolean>;
  cancel: (id: number) => void;
};

const nativeReminders = NativeModules?.RoknReminders as
  | ReminderModule
  | undefined;

const expoReminderId = (id: number) => `rokn-local-${id}`;

const scheduleExpoReminder = async ({
  actionLabel,
  body,
  courseId,
  id,
  imageUrl,
  kind,
  link,
  title,
  triggerAt,
}: {
  actionLabel?: string;
  body: string;
  courseId?: string;
  id: number;
  imageUrl?: string;
  kind?: NotificationKind;
  link?: string;
  title: string;
  triggerAt?: number;
}) => {
  const identifier = expoReminderId(id);
  const scheduled = await Notifications.getAllScheduledNotificationsAsync();
  await Promise.all(
    scheduled
      .filter(item => item.content.data?.rokn_reminder_id === identifier)
      .map(item =>
        Notifications.cancelScheduledNotificationAsync(item.identifier),
      ),
  );
  await Notifications.scheduleNotificationAsync({
    content: {
      title,
      body,
      data: {
        rokn_reminder_id: identifier,
        ...(actionLabel ? {action_label: actionLabel} : {}),
        ...(courseId ? {course_id: courseId} : {}),
        ...(imageUrl ? {image_url: imageUrl} : {}),
        ...(link ? {link} : {}),
        ...(kind ? {notification_type: kind} : {}),
      },
    },
    trigger: triggerAt
      ? {
          type: Notifications.SchedulableTriggerInputTypes.DATE,
          date: triggerAt,
        }
      : null,
  });
  return true;
};

const cancelReminderId = async (id: number) => {
  if (nativeReminders) {
    nativeReminders.cancel(id);
    return;
  }
  const identifier = expoReminderId(id);
  const scheduled = await Notifications.getAllScheduledNotificationsAsync();
  await Promise.all(
    scheduled
      .filter(item => item.content.data?.rokn_reminder_id === identifier)
      .map(item =>
        Notifications.cancelScheduledNotificationAsync(item.identifier),
      ),
  );
};

/**
 * Local notification scheduling is an external side effect. If logout or an
 * account replacement wins while the OS call is in flight, remove the timer
 * which just landed instead of letting the previous learner's course or coin
 * text appear in the next learner's device session.
 */
const scheduleForAccount = async (
  id: number,
  boundary: AccountSessionBoundary,
  operation: () => Promise<boolean>,
) => {
  assertAccountSessionBoundary(boundary);
  const scheduled = await operation();
  try {
    assertAccountSessionBoundary(boundary);
    return scheduled;
  } catch (error) {
    await cancelReminderId(id).catch(() => undefined);
    throw error;
  }
};

/**
 * A read-only capability check for permission primers. It never asks for an
 * OS permission and keeps unsupported platforms from showing a dead action.
 */
export const areSmartRemindersSupported = () =>
  Platform.OS === 'android' || Platform.OS === 'ios';

const safeReminderHour = (value: unknown) => {
  const hour = Number(value);
  return Number.isInteger(hour) && hour >= 9 && hour <= 21 ? hour : 20;
};

const nextPreferredTime = (hour = 20) => {
  const now = new Date();
  const next = new Date(now);
  next.setHours(safeReminderHour(hour), 0, 0, 0);
  if (next.getTime() <= now.getTime() + 60 * 60 * 1_000) {
    next.setDate(next.getDate() + 1);
  }
  return next.getTime();
};

export const enableSmartReminders = async () => {
  if (!areSmartRemindersSupported()) return false;
  type PermissionSnapshot = {
    granted?: boolean;
    status?: string;
    canAskAgain?: boolean;
  };
  const current =
    (await Notifications.getPermissionsAsync()) as PermissionSnapshot;
  if (current.granted || current.status === 'granted') return true;
  if (!current.canAskAgain) return false;
  const requested =
    (await Notifications.requestPermissionsAsync()) as PermissionSnapshot;
  return requested.granted || requested.status === 'granted';
};

export const previewSmartReminder = async () => {
  if (nativeReminders)
    return nativeReminders.preview(
      'أكمل من مكانك',
      'افتح ركن\nمقطعك التالي جاهز',
      'rokn://home',
      'learning_reminder',
      undefined,
      notificationDefaultAction.learning_reminder,
    );
  return scheduleExpoReminder({
    id: 8191,
    title: 'أكمل من مكانك',
    body: 'افتح ركن\nمقطعك التالي جاهز',
    link: 'rokn://home',
    kind: 'learning_reminder',
    actionLabel: notificationDefaultAction.learning_reminder,
  });
};

export const previewCoinNotification = async ({
  amount = 20,
  offer = false,
}: {amount?: number; offer?: boolean} = {}) => {
  const kind: NotificationKind = offer ? 'coin_offer' : 'coin_reward';
  const title = offer ? 'عملات إضافية لك' : 'وصلت مكافأتك';
  const body = offer
    ? `المكافأة ${formatRoknCoins(amount)}\nافتح المحفظة`
    : `${formatRoknCoins(amount)} في محفظتك`;
  if (nativeReminders)
    return nativeReminders.preview(
      title,
      body,
      'rokn://wallet',
      kind,
      undefined,
      notificationDefaultAction[kind],
    );
  return scheduleExpoReminder({
    id: 8192,
    title,
    body,
    link: 'rokn://wallet',
    kind,
    actionLabel: notificationDefaultAction[kind],
  });
};

export const scheduleNextLearningReminder = async (
  {
    nextReelTitle,
    courseTitle,
    streakDays = 0,
    courseId,
    preferredHour,
  }: {
    nextReelTitle?: string;
    courseTitle?: string;
    streakDays?: number;
    courseId?: string;
    preferredHour?: number;
  },
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  if (!(await getSmartRemindersEnabledFor(boundary))) return false;
  // Authenticated learners are scheduled centrally by the backend, which owns
  // cooldowns and course completion. A second local timer would produce the
  // exact duplicate reminder users complain about. Guests keep the local path.
  const session = await getItem(AsyncKeys.USER_DATA);
  assertAccountSessionBoundary(boundary);
  if (extractApiToken(session)) return false;
  const destinationCourseId = String(courseId || '').trim();
  // A preference change has no learning destination yet. Wait for a real
  // course progress event rather than scheduling a notification that opens a
  // synthetic or stale course identifier in a distributed build.
  if (!destinationCourseId) return false;
  const storedHour = await getSmartReminderHourFor(boundary);
  const reminderHour = safeReminderHour(preferredHour ?? storedHour);
  const kind: NotificationKind =
    streakDays > 1 ? 'streak_reminder' : 'learning_reminder';
  const body = formatArabicDisplayText(
    nextReelTitle
      ? `${
          courseTitle ? `${courseTitle}\n` : ''
        }توقفت عند ${nextReelTitle}\nأكمل عندما يناسبك`
      : streakDays > 1
      ? `مقطع واحد يحافظ على استمرارية ${streakDays} أيام`
      : 'مقطعك التالي جاهز',
  );
  const title = formatArabicDisplayText(
    streakDays > 1 ? 'حافظ على استمراريتك اليوم' : 'أكمل من مكانك',
  );
  const triggerAt = nextPreferredTime(reminderHour);
  const link = `rokn://course/${encodeURIComponent(destinationCourseId)}/watch`;
  return scheduleForAccount(8101, boundary, () =>
    nativeReminders
      ? nativeReminders.schedule(
          8101,
          title,
          body,
          triggerAt,
          destinationCourseId,
          link,
          kind,
          undefined,
          notificationDefaultAction[kind],
        )
      : scheduleExpoReminder({
          id: 8101,
          title,
          body,
          triggerAt,
          courseId: destinationCourseId,
          link,
          kind,
          actionLabel: notificationDefaultAction[kind],
        }),
  );
};

export const scheduleProjectReviewResult = async (
  projectTitle: string,
  courseId?: string,
) => {
  const boundary = await captureAccountSessionBoundary();
  if (!(await getSmartRemindersEnabledFor(boundary))) return false;
  const destinationCourseId = String(courseId || '').trim();
  if (!destinationCourseId) return false;
  const title = 'تم اعتماد مشروعك';
  const body = `اعتمدنا ${projectTitle}\nالوحدة التالية مفتوحة`;
  const triggerAt = Date.now() + 12_000;
  const link = `rokn://course/${encodeURIComponent(destinationCourseId)}`;
  return scheduleForAccount(8102, boundary, () =>
    nativeReminders
      ? nativeReminders.schedule(
          8102,
          title,
          body,
          triggerAt,
          destinationCourseId,
          link,
          'project_update',
          undefined,
          notificationDefaultAction.project_update,
        )
      : scheduleExpoReminder({
          id: 8102,
          title,
          body,
          triggerAt,
          courseId: destinationCourseId,
          link,
          kind: 'project_update',
          actionLabel: notificationDefaultAction.project_update,
        }),
  );
};

export const scheduleCoinRewardNotification = async ({
  amount,
  reason,
  delayMs = 2_000,
}: {
  amount: number;
  reason?: string;
  delayMs?: number;
}) => {
  const boundary = await captureAccountSessionBoundary();
  if (!(await getSmartRemindersEnabledFor(boundary))) return false;
  const safeAmount = Math.max(0, Math.floor(Number(amount) || 0));
  if (!safeAmount) return false;
  const title = 'وصلت مكافأتك';
  const body = `${
    reason ? `${formatArabicDisplayText(reason)}\n` : ''
  }${formatRoknCoins(safeAmount)} في محفظتك`;
  const triggerAt = Date.now() + Math.max(1_000, delayMs);
  return scheduleForAccount(8103, boundary, () =>
    nativeReminders
      ? nativeReminders.schedule(
          8103,
          title,
          body,
          triggerAt,
          undefined,
          'rokn://wallet',
          'coin_reward',
          undefined,
          notificationDefaultAction.coin_reward,
        )
      : scheduleExpoReminder({
          id: 8103,
          title,
          body,
          triggerAt,
          link: 'rokn://wallet',
          kind: 'coin_reward',
          actionLabel: notificationDefaultAction.coin_reward,
        }),
  );
};

export const previewCourseNotification = async ({
  title,
  courseId,
  imageUrl,
  isNew = false,
}: {
  title: string;
  courseId: string;
  imageUrl?: string;
  isNew?: boolean;
}) => {
  const kind: NotificationKind = isNew ? 'new_course' : 'continue_course';
  const notificationTitle = isNew ? 'كورس جديد' : 'أكمل من مكانك';
  const body = isNew ? title : `${title}\nأكمل من آخر مقطع`;
  const link = `rokn://course/${encodeURIComponent(courseId)}${
    isNew ? '' : '/watch'
  }`;
  const safeImage = safeNotificationImageUrl(imageUrl);
  if (nativeReminders)
    return nativeReminders.preview(
      notificationTitle,
      body,
      link,
      kind,
      safeImage,
      notificationDefaultAction[kind],
    );
  return scheduleExpoReminder({
    id: 8193,
    title: notificationTitle,
    body,
    courseId,
    link,
    kind,
    imageUrl: safeImage,
    actionLabel: notificationDefaultAction[kind],
  });
};

export const cancelLearningReminders = () => {
  if (nativeReminders) {
    nativeReminders.cancel(8101);
    nativeReminders.cancel(8102);
    nativeReminders.cancel(8103);
    return;
  }
  void Notifications.getAllScheduledNotificationsAsync()
    .then(items =>
      Promise.all(
        items
          .filter(item =>
            ['8101', '8102', '8103'].some(id =>
              String(item.content.data?.rokn_reminder_id || '').endsWith(id),
            ),
          )
          .map(item =>
            Notifications.cancelScheduledNotificationAsync(item.identifier),
          ),
      ),
    )
    .catch(() => undefined);
};
