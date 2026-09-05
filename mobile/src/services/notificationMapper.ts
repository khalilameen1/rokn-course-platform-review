import {
  isCoinNotification,
  normalizeNotificationKind,
  NotificationKind,
  safeNotificationImageUrl,
} from './notificationCampaigns';
import {parseRoknDestination} from '../navigation/deepLinks';
import {firstBoolean} from './api/common';
import {cleanUnicodeText} from '../utils/unicodeText';
import {formatAuthoredDisplayText} from '../constants/arabicFormatting';

export type Notification = {
  id: string;
  campaignId?: string;
  type: string;
  title: string;
  description: string;
  createdAt: string;
  read: boolean;
  link?: string;
  courseId?: string;
  imageUrl?: string;
  actionLabel: string;
  kind: NotificationKind;
  tone: 'learning' | 'project' | 'coins';
};

const safeCourseId = (value: unknown): string | undefined => {
  const id = String(value || '').trim();
  return /^\d{1,18}$/.test(id) && Number(id) > 0 ? id : undefined;
};

const courseIdFromItem = (item: Record<string, unknown>) => {
  const explicit = safeCourseId(firstValue(item, ['course_id', 'courseId']));
  if (explicit) return explicit;
  const notifiableType = String(item.notifiable_type || '').toLowerCase();
  return notifiableType.includes('course')
    ? safeCourseId(item.notifiable_id)
    : undefined;
};

const courseLinkForKind = (courseId: string, kind: NotificationKind): string =>
  `rokn://course/${encodeURIComponent(courseId)}${
    kind === 'continue_course' ||
    kind === 'learning_reminder' ||
    kind === 'streak_reminder'
      ? '/watch'
      : ''
  }`;

const normalizedExplicitLink = (
  value: unknown,
  kind: NotificationKind,
): string | undefined => {
  const link = String(value || '').trim();
  if (!link) return undefined;
  const destination = parseRoknDestination(link);
  if (!destination) return undefined;
  if (
    destination?.name === 'CourseDetails' &&
    (kind === 'continue_course' ||
      kind === 'learning_reminder' ||
      kind === 'streak_reminder')
  ) {
    return courseLinkForKind(destination.params.courseId, kind);
  }
  return link;
};

const notificationTone = (kind: NotificationKind): Notification['tone'] => {
  if (isCoinNotification(kind)) return 'coins';
  if (kind === 'project_update' || kind === 'certificate_ready') {
    return 'project';
  }
  return 'learning';
};

const safeDate = (value: unknown) => {
  const date = String(value || '').trim();
  return date && Number.isFinite(Date.parse(date)) ? date : '';
};

// Delivery copy may include authored course titles or code, not API errors.
// Keep the existing display bound and let the API contract reject missing text.
const notificationText = (value: unknown): string =>
  typeof value === 'string'
    ? formatAuthoredDisplayText(cleanUnicodeText(value).slice(0, 240))
    : '';

const firstValue = (item: Record<string, unknown>, keys: string[]): unknown => {
  for (const key of keys) {
    const value = item[key];
    if (value !== undefined && value !== null && String(value).trim()) {
      return value;
    }
  }
  return undefined;
};

/**
 * Maps the multilingual API payload at one boundary. Arabic UI must prefer
 * the explicit Arabic fields even when the API also returns generic text.
 */
export const mapNotification = (
  item: Record<string, unknown>,
): Notification => {
  const type = String(item.notification_type || 'learning');
  const kind = normalizeNotificationKind(type);
  const explicitLink = normalizedExplicitLink(
    firstValue(item, ['link', 'deep_link', 'action_url']),
    kind,
  );
  const courseId = courseIdFromItem(item);
  const imageUrl = safeNotificationImageUrl(
    firstValue(item, [
      'image_url',
      'image',
      'course_image',
      'cover_image',
      'thumbnail',
    ]),
  );
  return {
    id: String(item.id),
    campaignId: String(item.campaign_id || '').trim() || undefined,
    type,
    title: notificationText(item.title_ar || item.title),
    description: notificationText(item.message_ar || item.message),
    createdAt: safeDate(item.created_at),
    read:
      firstBoolean(item.is_read) ??
      (typeof item.read_at === 'string' && item.read_at.trim().length > 0),
    link: explicitLink,
    courseId,
    imageUrl,
    actionLabel: notificationText(
      firstValue(item, ['action_label_ar', 'cta_ar', 'action_label']),
    ),
    kind,
    tone: notificationTone(kind),
  };
};
