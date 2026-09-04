import {parseRoknDestination} from '../../navigation/deepLinks';
import type {Notification as NotificationDto} from '../../services/roknApi';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {serverNowMs} from '../../utils/serverClock';

const NOTIFICATIONS_CACHE_KEY = '@rokn/notifications-cache/v2';
let notificationsCacheWriteTail: Promise<void> = Promise.resolve();

type NotificationsCache = {
  version: 2;
  savedAt: number;
  items: NotificationDto[];
};

export const notificationCacheKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(NOTIFICATIONS_CACHE_KEY, boundary);

export const readCachedNotifications = async (
  key: string,
  boundary: AccountSessionBoundary,
) => {
  const cached = await getItem<Partial<NotificationsCache>>(key);
  assertAccountSessionBoundary(boundary);
  if (cached?.version !== 2 || !Array.isArray(cached.items)) {
    return [];
  }
  return cached.items.filter(
    (item): item is NotificationDto =>
      typeof item === 'object' &&
      item !== null &&
      typeof item.id === 'string' &&
      item.id.length > 0 &&
      typeof item.title === 'string' &&
      item.title.trim().length > 0 &&
      typeof item.description === 'string' &&
      item.description.trim().length > 0 &&
      typeof item.createdAt === 'string' &&
      Number.isFinite(Date.parse(item.createdAt)) &&
      typeof item.read === 'boolean' &&
      typeof item.actionLabel === 'string' &&
      Boolean(item.link) === Boolean(item.actionLabel.trim()) &&
      (item.link === undefined ||
        (typeof item.link === 'string' &&
          Boolean(parseRoknDestination(item.link)))) &&
      typeof item.kind === 'string' &&
      ['learning', 'project', 'coins'].includes(item.tone),
  );
};

export const saveCachedNotifications = async (
  key: string | null,
  items: NotificationDto[],
  boundary: AccountSessionBoundary | null,
) => {
  if (!key || !boundary) return false;
  const write = notificationsCacheWriteTail
    .catch(() => undefined)
    .then(async () => {
      assertAccountSessionBoundary(boundary);
      const saved = await saveItem(key, {
        version: 2,
        savedAt: serverNowMs(),
        items: items.slice(0, 120),
      } satisfies NotificationsCache);
      assertAccountSessionBoundary(boundary);
      return saved;
    });
  notificationsCacheWriteTail = write.then(
    () => undefined,
    () => undefined,
  );
  return write;
};
