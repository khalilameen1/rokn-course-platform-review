import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {mapNotification} from '../notificationMapper';
import type {Notification} from '../notificationMapper';
import {
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  payload,
  resourceList,
  responseEnvelope,
} from './common';

export type {Notification} from '../notificationMapper';

export type NotificationsPage = {
  notifications: Notification[];
  page: number;
  hasMore: boolean;
  nextCursor: string | null;
};

type NotificationDto = Parameters<typeof mapNotification>[0] & {
  id?: unknown;
};

const mapNotificationContract = (value: unknown): Notification => {
  if (!isApiRecord(value)) {
    throw new Error('NOTIFICATIONS_CONTRACT_INVALID');
  }
  const id = String(value.id ?? '').trim();
  const title = String(value.title_ar ?? value.title ?? '').trim();
  const message = String(value.message_ar ?? value.message ?? '').trim();
  const type = String(value.notification_type ?? '').trim();
  const createdAt = String(value.created_at ?? '').trim();
  const read = firstBoolean(value.is_read);
  if (
    !/^\d+$/.test(id) ||
    !title ||
    !message ||
    !type ||
    !createdAt ||
    !Number.isFinite(Date.parse(createdAt)) ||
    read === undefined
  ) {
    throw new Error('NOTIFICATIONS_CONTRACT_INVALID');
  }
  const mapped = mapNotification(value);
  if (
    mapped.id !== id ||
    !mapped.title.trim() ||
    !mapped.description.trim() ||
    !mapped.createdAt ||
    Boolean(mapped.link) !== Boolean(mapped.actionLabel.trim())
  ) {
    throw new Error('NOTIFICATIONS_CONTRACT_INVALID');
  }
  return mapped;
};

type NotificationsPayloadDto = {
  data?: NotificationDto[];
  pagination?: PaginationDto;
};

type PaginationDto = {
  current_page?: unknown;
  last_page?: unknown;
  has_more_pages?: unknown;
  next_cursor?: unknown;
};

export const getNotificationsPage = async ({
  page = 1,
  perPage = 30,
  cursor,
  signal,
  ownerBoundary,
}: {
  page?: number;
  perPage?: number;
  cursor?: string | null;
  signal?: AbortSignal;
  ownerBoundary?: AccountSessionBoundary;
} = {}): Promise<NotificationsPage> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.get('notifications', {
    signal,
    params: {
      page: Math.max(1, Math.floor(page)),
      per_page: Math.max(1, Math.min(50, Math.floor(perPage))),
      pagination_mode: 'cursor',
      ...(cursor ? {cursor} : {}),
    },
  });
  assertAccountSessionBoundary(boundary);
  const data = payload<NotificationsPayloadDto | NotificationDto[]>(response);
  if (!isResourceListPayload(data)) {
    throw new Error('NOTIFICATIONS_CONTRACT_INVALID');
  }
  const items = resourceList<NotificationDto>(data);
  const envelope = responseEnvelope(response);
  const envelopePagination = isApiRecord(envelope.pagination)
    ? (envelope.pagination as PaginationDto)
    : undefined;
  const payloadPagination =
    isApiRecord(data) && isApiRecord(data.pagination)
      ? (data.pagination as PaginationDto)
      : undefined;
  const pagination =
    envelopePagination || payloadPagination || ({} as PaginationDto);
  const rawCurrentPage = Number(pagination.current_page ?? page);
  const currentPage =
    Number.isSafeInteger(rawCurrentPage) && rawCurrentPage > 0
      ? rawCurrentPage
      : Math.max(1, Math.floor(page));
  // Cursor pagination is lossless only when every row is understood. Silently
  // dropping one malformed row and accepting next_cursor would make that
  // notification unreachable forever. Reject the page so the screen keeps its
  // last-known-good inbox and retries the same cursor.
  const notifications = items.map(mapNotificationContract);
  if (
    new Set(notifications.map(item => item.id)).size !== notifications.length
  ) {
    // Accepting a duplicate row would produce two cards for one mutation and
    // make cursor continuation ambiguous. Keep the last good inbox instead.
    throw new Error('NOTIFICATIONS_CONTRACT_INVALID');
  }
  const hasMore =
    firstBoolean(pagination.has_more_pages) ??
    (Number.isSafeInteger(Number(pagination.last_page)) &&
      Number(pagination.last_page) > currentPage);
  const nextCursor =
    typeof pagination.next_cursor === 'string' && pagination.next_cursor
      ? pagination.next_cursor
      : null;
  if (hasMore && !nextCursor) {
    throw new Error('NOTIFICATIONS_PAGINATION_CONTRACT_INVALID');
  }
  assertAccountSessionBoundary(boundary);
  return {
    notifications,
    page: currentPage,
    nextCursor,
    hasMore,
  };
};

export const getNotifications = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<Notification[]> =>
  (await getNotificationsPage({ownerBoundary})).notifications;

export const getNotification = async (
  id: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<Notification> => {
  const normalizedId = String(id || '').trim();
  if (!/^\d+$/.test(normalizedId)) {
    throw new Error('INVALID_NOTIFICATION_ID');
  }
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.get(`notifications/${normalizedId}`);
  assertAccountSessionBoundary(boundary);
  const data = payload<unknown>(response);
  const item = isApiRecord(data)
    ? isApiRecord(data.data)
      ? (data.data as NotificationDto)
      : (data as NotificationDto)
    : null;
  if (!item) throw new Error('NOTIFICATION_CONTRACT_INVALID');
  if (String(item.id ?? '').trim() !== normalizedId) {
    throw new Error('NOTIFICATION_NOT_FOUND');
  }
  const notification = mapNotificationContract(item);
  assertAccountSessionBoundary(boundary);
  return notification;
};

export const markNotificationRead = async (
  id: string,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const normalizedId = String(id || '').trim();
  if (!/^\d+$/.test(normalizedId)) {
    throw new Error('INVALID_NOTIFICATION_ID');
  }
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.post(
    `notifications/${normalizedId}/mark-read`,
  );
  assertAccountSessionBoundary(boundary);
  return response;
};

export const markAllNotificationsRead = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.post('notifications/mark-all-read');
  assertAccountSessionBoundary(boundary);
  return response;
};
