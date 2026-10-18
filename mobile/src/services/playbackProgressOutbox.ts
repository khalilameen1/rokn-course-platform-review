import AsyncStorage from '@react-native-async-storage/async-storage';

import {createDurableOutbox} from './durableOutbox';
import {invalidateLearningProgress} from './query/learningInvalidation';

/**
 * Typed M2 adapter: the newest unacknowledged watch sample per lesson. The
 * feature (courseLearning/playbackProgress) owns sampling and throttling; the
 * engine owns durability, coalescing, generations and the retry clock.
 */
export type PendingWatchHistory = {
  lessonId: number;
  positionSeconds: number;
  durationSeconds?: number;
  completed: boolean;
  playbackSessionId?: string;
  sequence?: number;
  effectiveQuality?: string;
  effectiveBitrateKbps?: number;
  playbackRate?: number;
  recoveryCount?: number;
  bufferCount?: number;
  bufferDurationMs?: number;
  startupLatencyMs?: number;
  eventType?: string;
  endReason?: string;
  errorCode?: string;
  diagnostics?: Record<string, unknown>;
};

const LEGACY_PREFIX = '@rokn/watch-evidence/v1';

export const isPendingWatchHistory = (
  value: unknown,
): value is PendingWatchHistory => {
  const pending = value as Partial<PendingWatchHistory> | null;
  return Boolean(
    pending &&
      Number.isFinite(pending.lessonId) &&
      Number.isFinite(pending.positionSeconds) &&
      typeof pending.completed === 'boolean',
  );
};

/**
 * Coalesce to the newest sample. Resume means the latest observed position,
 * not the furthest point reached: a learner can rewind and leave before the
 * 30-second flush, and WatchHistoryController orders samples by session.
 * Completion is sticky; the longest known duration wins.
 */
export const mergePendingWatchHistory = (
  previous: PendingWatchHistory | undefined,
  next: PendingWatchHistory,
): PendingWatchHistory =>
  previous?.completed
    ? previous
    : {
        ...next,
        positionSeconds: next.positionSeconds,
        durationSeconds:
          Math.max(previous?.durationSeconds || 0, next.durationSeconds || 0) ||
          undefined,
        completed: previous?.completed === true || next.completed,
        eventType:
          previous?.completed === true || next.completed
            ? 'complete'
            : next.eventType,
      };

const decode = (value: unknown): PendingWatchHistory | null => {
  if (!isPendingWatchHistory(value)) return null;
  // v1 records carried a per-write owner token; generations replace it.
  const sample: PendingWatchHistory & {writeOwner?: string} = {...value};
  delete sample.writeOwner;
  return sample;
};

const status = (error: unknown) =>
  Number(
    (error as {status?: unknown; response?: {status?: unknown}})?.status ??
      (error as {response?: {status?: unknown}})?.response?.status ??
      0,
  );

export const playbackProgressOutbox = createDurableOutbox<PendingWatchHistory>(
  {
    namespace: '@rokn/watch-evidence/v2',
    accountScoped: true,
    maxItems: 200,
    ttlMs: 30 * 24 * 60 * 60 * 1000,
    backoff: {baseMs: 30_000, maxMs: 10 * 60 * 1000},
    decode,
    coalesce: mergePendingWatchHistory,
    // A malformed sample is refused for good; everything else (offline,
    // 401 before refresh, 429, 5xx) keeps the newest sample for retry.
    classify: error => ([400, 404, 410, 422].includes(status(error)) ? 'drop' : 'retry'),
    retractOnBoundaryChange: true,
    onDelivered: (payload, scope) => {
      if (payload.completed) invalidateLearningProgress(scope);
    },
    legacy: {
      // v1 kept one key per lesson: `${prefix}:${scope}:${lessonId}`.
      read: async scope => {
        const prefix = `${LEGACY_PREFIX}:${scope}:`;
        const keys = (await AsyncStorage.getAllKeys()).filter(key =>
          key.startsWith(prefix),
        );
        if (!keys.length) return [];
        return (await AsyncStorage.multiGet(keys)).flatMap(([, raw]) => {
          try {
            const sample = decode(raw ? JSON.parse(raw) : null);
            return sample ? [{id: String(sample.lessonId), payload: sample}] : [];
          } catch {
            return [];
          }
        });
      },
      clear: async scope => {
        const prefix = `${LEGACY_PREFIX}:${scope}:`;
        const keys = (await AsyncStorage.getAllKeys()).filter(key =>
          key.startsWith(prefix),
        );
        await Promise.all(keys.map(key => AsyncStorage.removeItem(key)));
      },
    },
  },
);
