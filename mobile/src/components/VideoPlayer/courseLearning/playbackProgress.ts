import AsyncStorage from '@react-native-async-storage/async-storage';
import {AppState} from 'react-native';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {hasSession} from '../../../services/roknApi';
import type {VideoQuality} from '../types';
import {
  type PlaybackDiagnostics,
  type PlaybackEndReason,
  type PlaybackLifecycleEventType,
  sanitizePlaybackDiagnostics,
} from '../playbackTelemetry';
import {isWatchHistoryEnabled, updatePlayerStateForScope} from './persistence';
import {
  nextPlaybackSequence,
  postPlaybackSample,
  restorePlaybackSequence,
  type PlaybackEvidenceContext,
} from './playbackSession';

const WATCH_EVIDENCE_PREFIX = '@rokn/watch-evidence/v1';
const WATCH_HISTORY_SYNC_INTERVAL_MS = 30_000;

type PendingWatchHistory = {
  writeOwner?: string;
  lessonId: number;
  positionSeconds: number;
  durationSeconds?: number;
  completed: boolean;
  playbackSessionId?: string;
  sequence?: number;
  effectiveQuality?: VideoQuality;
  effectiveBitrateKbps?: number;
  playbackRate?: number;
  recoveryCount?: number;
  bufferCount?: number;
  bufferDurationMs?: number;
  startupLatencyMs?: number;
  eventType?: PlaybackLifecycleEventType;
  endReason?: PlaybackEndReason;
  errorCode?: string;
  diagnostics?: PlaybackDiagnostics;
};
const pendingWatchHistory = new Map<string, PendingWatchHistory>();
const watchHistoryTimers = new Map<string, ReturnType<typeof setTimeout>>();
const watchHistoryFlights = new Map<string, Promise<boolean>>();
const watchEvidenceWriteQueues = new Map<string, Promise<unknown>>();
const watchHistoryLastSyncedAt = new Map<string, number>();
const MAX_RECENT_WATCH_SYNC_KEYS = 128;
let playbackRuntimeGeneration = 0;
let watchEvidenceWriteSequence = 0;

const isPendingWatchHistory = (
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

const mergePendingWatchHistory = (
  previous: PendingWatchHistory | undefined,
  next: PendingWatchHistory,
): PendingWatchHistory =>
  previous?.completed
    ? {...previous, writeOwner: next.writeOwner}
    : {
        ...next,
        // Resume means the latest observed position, not the furthest point
        // reached. A learner can rewind and leave before the 30-second flush;
        // keeping max(position) would make the next launch jump forward again
        // and also disagrees with WatchHistoryController's ordered-session
        // contract.
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

const watchEvidenceStorageKey = (key: string) =>
  `${WATCH_EVIDENCE_PREFIX}:${key}`;

const assertWatchHistoryOwner = (
  generation: number,
  boundary: AccountSessionBoundary,
) => {
  if (generation !== playbackRuntimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(boundary);
};

const hydratePendingWatchEvidence = async (
  boundary: AccountSessionBoundary,
  generation: number,
) => {
  assertWatchHistoryOwner(generation, boundary);
  const scopePrefix = `${WATCH_EVIDENCE_PREFIX}:${boundary.scope}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(scopePrefix),
  );
  assertWatchHistoryOwner(generation, boundary);
  if (!keys.length) return;
  const entries = await AsyncStorage.multiGet(keys);
  assertWatchHistoryOwner(generation, boundary);
  entries.forEach(([storageKey, raw]) => {
    assertWatchHistoryOwner(generation, boundary);
    if (!raw) return;
    try {
      const pending = JSON.parse(raw) as PendingWatchHistory;
      if (isPendingWatchHistory(pending)) {
        const key = storageKey.slice(`${WATCH_EVIDENCE_PREFIX}:`.length);
        if (!pendingWatchHistory.has(key)) {
          pendingWatchHistory.set(key, pending);
        }
        if (pending.playbackSessionId && pending.sequence) {
          restorePlaybackSequence(
            pending.playbackSessionId,
            boundary.scope,
            pending.sequence,
          );
        }
      }
    } catch {
      void AsyncStorage.removeItem(storageKey);
    }
  });
};

const scheduleWatchHistoryFlush = (key: string, delay: number) => {
  const existing = watchHistoryTimers.get(key);
  if (existing) clearTimeout(existing);
  const timer = setTimeout(() => {
    watchHistoryTimers.delete(key);
    if (AppState.currentState !== 'active') return;
    void flushWatchHistoryEntry(key);
  }, Math.max(0, delay));
  watchHistoryTimers.set(key, timer);
};

const flushWatchHistoryEntry = async (key: string): Promise<boolean> => {
  const activeFlight = watchHistoryFlights.get(key);
  if (activeFlight) return activeFlight;
  const pending = pendingWatchHistory.get(key);
  if (!pending) return true;
  const generation = playbackRuntimeGeneration;
  let boundary: AccountSessionBoundary;
  try {
    boundary = await captureAccountSessionBoundary();
    assertWatchHistoryOwner(generation, boundary);
  } catch {
    return false;
  }
  if (!key.startsWith(`${boundary.scope}:`)) {
    return false;
  }

  const stillOwned = () => {
    try {
      assertWatchHistoryOwner(generation, boundary);
      return true;
    } catch {
      return false;
    }
  };

  const timer = watchHistoryTimers.get(key);
  if (timer) clearTimeout(timer);
  watchHistoryTimers.delete(key);

  const flight = postPlaybackSample(
    pending.playbackSessionId,
    {
      lesson_id: pending.lessonId,
      position_seconds: pending.positionSeconds,
      ...(pending.durationSeconds && pending.durationSeconds > 0
        ? {duration_seconds: pending.durationSeconds}
        : {}),
      is_completed: pending.completed,
      ...(pending.playbackSessionId
        ? {playback_session_id: pending.playbackSessionId}
        : {}),
      ...(pending.sequence ? {sequence: pending.sequence} : {}),
      ...(pending.effectiveQuality
        ? {effective_quality: pending.effectiveQuality}
        : {}),
      ...(pending.effectiveBitrateKbps
        ? {effective_bitrate_kbps: pending.effectiveBitrateKbps}
        : {}),
      ...(pending.playbackRate ? {playback_rate: pending.playbackRate} : {}),
      ...(Number.isFinite(pending.recoveryCount)
        ? {recovery_count: pending.recoveryCount}
        : {}),
      ...(Number.isFinite(pending.bufferCount)
        ? {buffer_count: pending.bufferCount}
        : {}),
      ...(Number.isFinite(pending.bufferDurationMs)
        ? {buffer_duration_ms: pending.bufferDurationMs}
        : {}),
      ...(Number.isFinite(pending.startupLatencyMs)
        ? {startup_latency_ms: pending.startupLatencyMs}
        : {}),
      event_type:
        pending.eventType || (pending.completed ? 'complete' : 'heartbeat'),
      ...(pending.endReason ? {end_reason: pending.endReason} : {}),
      ...(pending.errorCode ? {error_code: pending.errorCode} : {}),
      ...(pending.diagnostics ? {diagnostics: pending.diagnostics} : {}),
    },
    boundary,
  )
    .then(() => {
      watchHistoryLastSyncedAt.set(key, Date.now());
      while (watchHistoryLastSyncedAt.size > MAX_RECENT_WATCH_SYNC_KEYS) {
        const oldest = watchHistoryLastSyncedAt.keys().next().value;
        if (typeof oldest !== 'string') break;
        watchHistoryLastSyncedAt.delete(oldest);
      }
      if (pendingWatchHistory.get(key) === pending) {
        pendingWatchHistory.delete(key);
        // The sample is already committed remotely. Native storage cleanup
        // must not route this success into the network catch and repeatedly
        // POST the same completion/heartbeat.
        void AsyncStorage.removeItem(watchEvidenceStorageKey(key)).catch(
          () => undefined,
        );
      }
      return true;
    })
    .catch(() => {
      // Keep only the newest sample in memory and retry later. Playback and
      // the local resume point never wait for this network request.
      if (stillOwned()) {
        scheduleWatchHistoryFlush(key, WATCH_HISTORY_SYNC_INTERVAL_MS);
      }
      return false;
    })
    .finally(() => {
      if (watchHistoryFlights.get(key) === flight) {
        watchHistoryFlights.delete(key);
      }
      if (
        stillOwned() &&
        pendingWatchHistory.has(key) &&
        !watchHistoryTimers.has(key)
      ) {
        scheduleWatchHistoryFlush(key, WATCH_HISTORY_SYNC_INTERVAL_MS);
      }
    });
  watchHistoryFlights.set(key, flight);
  return flight;
};

const queueWatchHistorySync = async (
  lessonId: number,
  seconds: number,
  durationSeconds?: number,
  completed = false,
  context?: PlaybackEvidenceContext,
  boundary?: AccountSessionBoundary,
  generation = playbackRuntimeGeneration,
) => {
  const owner = boundary || (await captureAccountSessionBoundary());
  assertWatchHistoryOwner(generation, owner);
  const key = `${owner.scope}:${lessonId}`;
  const next: PendingWatchHistory = {
    writeOwner: `${generation}:${++watchEvidenceWriteSequence}`,
    lessonId,
    positionSeconds: Math.max(0, Math.floor(seconds)),
    ...(durationSeconds && durationSeconds > 0
      ? {durationSeconds: Math.floor(durationSeconds)}
      : {}),
    completed,
    ...(context?.playbackSessionId
      ? {
          playbackSessionId: context.playbackSessionId,
          sequence: nextPlaybackSequence(
            context.playbackSessionId,
            owner.scope,
          ),
        }
      : {}),
    ...(context?.effectiveQuality
      ? {effectiveQuality: context.effectiveQuality}
      : {}),
    ...(context?.effectiveBitrateKbps
      ? {
          effectiveBitrateKbps: Math.max(
            1,
            Math.round(context.effectiveBitrateKbps),
          ),
        }
      : {}),
    ...(context?.playbackRate ? {playbackRate: context.playbackRate} : {}),
    ...(Number.isFinite(context?.recoveryCount)
      ? {
          recoveryCount: Math.max(
            0,
            Math.min(20, Number(context?.recoveryCount)),
          ),
        }
      : {}),
    ...(Number.isFinite(context?.bufferCount)
      ? {bufferCount: Math.max(0, Math.min(500, Number(context?.bufferCount)))}
      : {}),
    ...(Number.isFinite(context?.bufferDurationMs)
      ? {
          bufferDurationMs: Math.max(
            0,
            Math.min(3_600_000, Math.round(Number(context?.bufferDurationMs))),
          ),
        }
      : {}),
    ...(Number.isFinite(context?.startupLatencyMs)
      ? {
          startupLatencyMs: Math.max(
            0,
            Math.min(120_000, Math.round(Number(context?.startupLatencyMs))),
          ),
        }
      : {}),
    eventType: completed ? 'complete' : 'heartbeat',
    ...(sanitizePlaybackDiagnostics(context?.diagnostics)
      ? {diagnostics: sanitizePlaybackDiagnostics(context?.diagnostics)}
      : {}),
  };
  const previousWrite = watchEvidenceWriteQueues.get(key) || Promise.resolve();
  const write = previousWrite
    .catch(() => undefined)
    .then(async () => {
      assertWatchHistoryOwner(generation, owner);
      const storageKey = watchEvidenceStorageKey(key);
      const raw = await AsyncStorage.getItem(storageKey);
      assertWatchHistoryOwner(generation, owner);
      let durable: PendingWatchHistory | undefined;
      if (raw) {
        try {
          const parsed = JSON.parse(raw) as unknown;
          if (isPendingWatchHistory(parsed)) durable = parsed;
        } catch {
          // The next valid sample repairs this account-scoped record.
        }
      }
      const previous = mergePendingWatchHistory(
        durable,
        pendingWatchHistory.get(key) || next,
      );
      const pending = mergePendingWatchHistory(previous, next);
      await AsyncStorage.setItem(storageKey, JSON.stringify(pending));
      try {
        assertWatchHistoryOwner(generation, owner);
      } catch (error) {
        // Logout/account replacement can clear this scope while a native
        // storage write is already in flight. Remove only this exact write;
        // a newer runtime may already have persisted a newer sample at the
        // same key and must never be erased by the old operation's cleanup.
        const current = await AsyncStorage.getItem(storageKey).catch(
          () => null,
        );
        if (current) {
          try {
            const stored = JSON.parse(current) as PendingWatchHistory;
            if (stored.writeOwner === pending.writeOwner) {
              await AsyncStorage.removeItem(storageKey).catch(() => undefined);
            }
          } catch {
            // A later valid write owns malformed-record repair.
          }
        }
        throw error;
      }
      pendingWatchHistory.set(key, pending);
      const elapsed = Date.now() - (watchHistoryLastSyncedAt.get(key) || 0);
      if (pending.completed || elapsed >= WATCH_HISTORY_SYNC_INTERVAL_MS) {
        return true;
      }
      scheduleWatchHistoryFlush(key, WATCH_HISTORY_SYNC_INTERVAL_MS - elapsed);
      return false;
    });
  const settled = write
    .catch(() => undefined)
    .finally(() => {
      if (watchEvidenceWriteQueues.get(key) === settled) {
        watchEvidenceWriteQueues.delete(key);
      }
    });
  watchEvidenceWriteQueues.set(key, settled);
  const shouldFlush = await write;
  if (!shouldFlush) return;

  const flushed = await flushWatchHistoryEntry(key);
  // Completion gates depend on the latest evidence, not merely whichever
  // heartbeat happened to own the active request when completion arrived.
  // One bounded drain is sufficient because completion makes the merged
  // durable sample terminal for this lesson.
  if (completed && flushed && pendingWatchHistory.has(key)) {
    await flushWatchHistoryEntry(key);
  }
};

/** Flush the latest sample per lesson when the app backgrounds or changes reel. */
export const flushPendingPlaybackPositions = async () => {
  const generation = playbackRuntimeGeneration;
  let boundary: AccountSessionBoundary;
  try {
    boundary = await captureAccountSessionBoundary();
    await hydratePendingWatchEvidence(boundary, generation);
    assertWatchHistoryOwner(generation, boundary);
  } catch {
    return;
  }
  await Promise.allSettled(
    Array.from(pendingWatchHistory.keys())
      .filter(key => key.startsWith(`${boundary.scope}:`))
      .map(flushWatchHistoryEntry),
  );
};

export const retryPendingPlaybackPositions = async () => {
  if (!(await hasSession())) return;
  await flushPendingPlaybackPositions();
};

/** Persist the last rendered frame without emitting a second server sample. */
export const persistLocalPlaybackPosition = async (
  courseId: string,
  reelId: string,
  seconds: number,
  boundary?: AccountSessionBoundary,
) => {
  const owner = boundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(owner);
  if (!(await isWatchHistoryEnabled())) return;
  assertAccountSessionBoundary(owner);
  const historyKey = `${courseId}:${reelId}`;
  await updatePlayerStateForScope(
    owner.scope,
    state => ({
      ...state,
      positions: {
        ...state.positions,
        [historyKey]: Math.max(0, Math.floor(seconds)),
      },
      lastWatchedAt: {
        ...state.lastWatchedAt,
        [historyKey]: new Date().toISOString(),
      },
    }),
    owner,
  );
};

export const savePlaybackPosition = async (
  courseId: string,
  reelId: string,
  seconds: number,
  lessonId?: string,
  durationSeconds?: number,
  completed = false,
  context?: PlaybackEvidenceContext,
) => {
  const generation = playbackRuntimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertWatchHistoryOwner(generation, boundary);
  await persistLocalPlaybackPosition(courseId, reelId, seconds, boundary);
  assertWatchHistoryOwner(generation, boundary);

  const remoteLessonId = Number(lessonId);
  if (lessonId && Number.isFinite(remoteLessonId) && (await hasSession())) {
    assertWatchHistoryOwner(generation, boundary);
    await queueWatchHistorySync(
      remoteLessonId,
      seconds,
      durationSeconds,
      completed,
      context,
      boundary,
      generation,
    );
  }
};

export const resetPlaybackProgressRuntime = () => {
  playbackRuntimeGeneration += 1;
  watchHistoryTimers.forEach(timer => clearTimeout(timer));
  watchHistoryTimers.clear();
  pendingWatchHistory.clear();
  watchHistoryFlights.clear();
  // Keep native storage writes serialized across a token/account runtime
  // reset. Their generation check prevents any old response from applying,
  // while preserving the queue prevents an unfinished old write from
  // overwriting a newer record for the same account scope.
  watchHistoryLastSyncedAt.clear();
};
