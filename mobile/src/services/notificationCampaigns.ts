export const notificationKinds = [
  'learning_reminder',
  'streak_reminder',
  'continue_course',
  'course_recommendation',
  'new_course',
  'coin_reward',
  'coin_offer',
  'project_update',
  'certificate_ready',
  'account_update',
  'support_update',
] as const;

export type NotificationKind = (typeof notificationKinds)[number];

const kindAliases: Record<string, NotificationKind> = {
  learning: 'learning_reminder',
  learning_reminder: 'learning_reminder',
  learning_nudge: 'continue_course',
  reminder: 'learning_reminder',
  streak: 'streak_reminder',
  streak_reminder: 'streak_reminder',
  continue: 'continue_course',
  continue_course: 'continue_course',
  enrolled_stalled: 'continue_course',
  course_enrolled: 'continue_course',
  institutional_grant: 'continue_course',
  new_course_lesson: 'continue_course',
  course_update: 'continue_course',
  course_promotion: 'course_recommendation',
  course_recommendation: 'course_recommendation',
  new_course: 'new_course',
  wallet_credit: 'coin_reward',
  coins_claimed: 'coin_reward',
  package_purchased: 'coin_reward',
  whatsapp_connected: 'coin_reward',
  coin_reward: 'coin_reward',
  reward: 'coin_reward',
  wallet_offer: 'coin_offer',
  coin_offer: 'coin_offer',
  project: 'project_update',
  project_update: 'project_update',
  certificate: 'certificate_ready',
  certificate_ready: 'certificate_ready',
  course_completed: 'certificate_ready',
  account: 'account_update',
  account_update: 'account_update',
  support: 'support_update',
  support_case_update: 'support_update',
  support_update: 'support_update',
};

export const normalizeNotificationKind = (value: unknown): NotificationKind =>
  kindAliases[String(value || '').trim().toLowerCase()] || 'account_update';

export const notificationDefaultAction: Record<NotificationKind, string> = {
  learning_reminder: 'أكمل من مكانك',
  streak_reminder: 'حافظ على استمراريتك',
  continue_course: 'أكمل الكورس',
  course_recommendation: 'تفاصيل الكورس',
  new_course: 'افتح الكورس',
  coin_reward: 'افتح المحفظة',
  coin_offer: 'افتح العرض',
  project_update: 'افتح النتيجة',
  certificate_ready: 'افتح الشهادة',
  account_update: 'افتح ركن',
  support_update: 'افتح البلاغ',
};

export const isCoinNotification = (kind: NotificationKind) =>
  kind === 'coin_reward' || kind === 'coin_offer';

export const isCourseNotification = (kind: NotificationKind) =>
  kind === 'learning_reminder' ||
  kind === 'streak_reminder' ||
  kind === 'continue_course' ||
  kind === 'course_recommendation' ||
  kind === 'new_course';

export const safeNotificationImageUrl = (value: unknown) => {
  const image = String(value || '').trim();
  return /^https:\/\//i.test(image) ? image : undefined;
};

/**
 * Contract understood by the app, FCM sender and dashboard campaign form.
 * Targeting belongs to the backend; the phone receives only the final copy and
 * destination chosen for that learner.
 */
export type NotificationCampaignPayload = {
  notification_type: NotificationKind;
  title_ar: string;
  message_ar: string;
  link: string;
  action_label_ar?: string;
  image_url?: string;
  course_id?: string;
  campaign_id?: string;
};
