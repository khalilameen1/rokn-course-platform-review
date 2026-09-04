import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {
  type ApiRecord,
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  payload,
  resourceList,
} from './common';

type WatchHistoryDto = ApiRecord;

export type WatchHistoryItem = {
  id: string;
  courseId: string;
  courseTitle: string;
  courseImage?: string;
  lessonId: string;
  lessonTitle: string;
  lessonThumbnail?: string;
  positionSeconds: number;
  durationSeconds?: number;
  progress: number;
  completed: boolean;
  watchedAt?: string;
};

export type WatchHistory = {
  trackingEnabled: boolean;
  items: WatchHistoryItem[];
};

export const clearWatchHistory = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  await publicRequest.delete('user/watch-history');
  assertAccountSessionBoundary(boundary);
};

export const getWatchHistory = async (
  limit = 6,
  ownerBoundary?: AccountSessionBoundary,
): Promise<WatchHistory> => {
  const safeLimit = Math.max(1, Math.min(6, Math.trunc(limit) || 6));
  const accountBoundary =
    ownerBoundary || (await captureAccountSessionBoundary());
  const data = payload(
    await publicRequest.get('user/watch-history', {
      params: {per_page: safeLimit},
    }),
  );
  assertAccountSessionBoundary(accountBoundary);
  if (
    !isApiRecord(data) ||
    !isResourceListPayload(data.items) ||
    firstBoolean(data.tracking_enabled) === undefined
  ) {
    throw new Error('WATCH_HISTORY_CONTRACT_INVALID');
  }

  const rawItems = resourceList<WatchHistoryDto>(data.items);
  const identities = new Set<string>();
  if (
    rawItems.some(item => {
      if (!isApiRecord(item)) return true;
      const courseId = String(item.course_id ?? '').trim();
      const lessonId = String(item.lesson_id ?? '').trim();
      const identity = `${courseId}:${lessonId}`;
      const position = Number(item.position_seconds);
      const rawDuration = item.duration_seconds;
      const duration = rawDuration === null ? null : Number(rawDuration);
      const watchedAt = String(item.watched_at || '').trim();
      if (
        !/^\d+$/.test(courseId) ||
        !/^\d+$/.test(lessonId) ||
        identities.has(identity) ||
        !String(item.course_title || item.course_title_en || '').trim() ||
        !String(item.lesson_title || '').trim() ||
        !Number.isSafeInteger(position) ||
        position < 0 ||
        (duration !== null &&
          (!Number.isSafeInteger(duration) || duration < 1)) ||
        firstBoolean(item.is_completed) === undefined ||
        !watchedAt ||
        !Number.isFinite(Date.parse(watchedAt))
      ) {
        return true;
      }
      identities.add(identity);
      return false;
    })
  ) {
    throw new Error('WATCH_HISTORY_CONTRACT_INVALID');
  }

  const seenLessons = new Set<string>();
  const items: WatchHistoryItem[] = [];
  for (const item of rawItems) {
    const courseId = item?.course_id;
    const lessonId = item?.lesson_id;
    if (courseId === null || courseId === undefined) continue;
    if (lessonId === null || lessonId === undefined) continue;

    const lessonKey = `${courseId}:${lessonId}`;
    if (seenLessons.has(lessonKey)) continue;
    seenLessons.add(lessonKey);

    const positionSeconds = Math.max(0, Number(item.position_seconds || 0));
    const durationNumber = Number(item.duration_seconds);
    const durationSeconds =
      Number.isFinite(durationNumber) && durationNumber > 0
        ? durationNumber
        : undefined;
    const reportedProgress = Number(item.progress_percentage);
    const calculatedProgress = durationSeconds
      ? (positionSeconds / durationSeconds) * 100
      : 0;
    const progress = Math.max(
      0,
      Math.min(
        100,
        item.is_completed
          ? 100
          : Number.isFinite(reportedProgress)
          ? reportedProgress
          : calculatedProgress,
      ),
    );

    items.push({
      id: String(item.id ?? lessonKey),
      courseId: String(courseId),
      courseTitle: String(item.course_title || item.course_title_en),
      courseImage: item.course_image ? String(item.course_image) : undefined,
      lessonId: String(lessonId),
      lessonTitle: String(item.lesson_title),
      lessonThumbnail: item.lesson_thumbnail
        ? String(item.lesson_thumbnail)
        : undefined,
      positionSeconds,
      durationSeconds,
      progress,
      completed: firstBoolean(item.is_completed) ?? false,
      watchedAt: item.watched_at ? String(item.watched_at) : undefined,
    });

    if (items.length >= safeLimit) break;
  }

  assertAccountSessionBoundary(accountBoundary);
  return {
    trackingEnabled: firstBoolean(data.tracking_enabled)!,
    items,
  };
};
