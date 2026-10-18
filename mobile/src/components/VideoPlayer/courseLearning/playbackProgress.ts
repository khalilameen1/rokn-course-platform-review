import {AppState} from 'react-native';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {hasSession} from '../../../services/roknApi';
import {
  isWatchHistoryEnabled,
  updatePlayerStateForScope,
} from '../../../services/learningPlayerState';
import {
  playbackProgressOutbox,
  type PendingWatchHistory,
} from '../../../services/playbackProgressOutbox';
import type {DurableOutboxItem} from '../../../services/durableOutbox';
import {sanitizePlaybackDiagnostics} from '../playbackTelemetry';
import {
  nextPlaybackSequence,
  postPlaybackSample,
  restorePlaybackSequence,
  type PlaybackEvidenceContext,
} from './playbackSession';

/**
 * Watch evidence: the newest sample per lesson rides the durable outbox
 * (services/playbackProgressOutbox). This module owns sampling and the
 * 30-second heartbeat throttle; the engine owns storage and the retry clock.
 */
const WATCH_HISTORY_SYNC_INTERVAL_MS = 30_000;
const watchHistoryTimers = new Map<string, ReturnType<typeof setTimeout>>();
const watchHistoryFlights = new Map<string, Promise<boolean>>();
const watchHistoryLastSyncedAt = new Map<string, number>();
const MAX_RECENT_WATCH_SYNC_KEYS = 128;
let playbackRuntimeGeneration = 0;

const assertWatchHistoryOwner = (
  generation: number,
  boundary: AccountSessionBoundary,
) => {
  if (generation !== playbackRuntimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(boundary);
};

const ownedBy = (generation: number, boundary: AccountSessionBoundary) => {
  try {
    assertWatchHistoryOwner(generation, boundary);
    return true;
  } catch {
    return false;
  }
};

const sampleBody = (pending: PendingWatchHistory) => ({
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
});

const deliverSample =
  (boundary: AccountSessionBoundary) =>
  async (item: DurableOutboxItem<PendingWatchHistory>) => {
    await postPlaybackSample(
      item.payload.playbackSessionId,
      sampleBody(item.payload),
      boundary,
    );
    return 'ack' as const;
  };

const scheduleWatchHistoryFlush = (
  key: string,
  boundary: AccountSessionBoundary,
  generation: number,
  delay: number,
) => {
  const existing = watchHistoryTimers.get(key);
  if (existing) clearTimeout(existing);
  const timer = setTimeout(() => {
    watchHistoryTimers.delete(key);
    if (AppState.currentState !== 'active') return;
    void flushWatchHistoryEntry(key, boundary, generation, false);
  }, Math.max(0, delay));
  watchHistoryTimers.set(key, timer);
};

const rememberSync = (key: string) => {
  watchHistoryLastSyncedAt.set(key, Date.now());
  while (watchHistoryLastSyncedAt.size > MAX_RECENT_WATCH_SYNC_KEYS) {
    const oldest = watchHistoryLastSyncedAt.keys().next().value;
    if (typeof oldest !== 'string') break;
    watchHistoryLastSyncedAt.delete(oldest);
  }
};

/**
 * Keep only the newest sample and retry it on the engine's clock (never
 * sooner than the heartbeat interval). Playback never waits for this.
 */
const scheduleRemaining = async (
  key: string,
  lessonId: string,
  boundary: AccountSessionBoundary,
  generation: number,
) => {
  if (!ownedBy(generation, boundary) || watchHistoryTimers.has(key)) return;
  const pending = await playbackProgressOutbox
    .get(lessonId, {boundary})
    .catch(() => null);
  if (
    !pending ||
    !ownedBy(generation, boundary) ||
    watchHistoryTimers.has(key) ||
    watchHistoryFlights.has(key)
  ) {
    return;
  }
  const due = Math.max(
    Date.parse(pending.nextAttemptAt) - Date.now(),
    WATCH_HISTORY_SYNC_INTERVAL_MS -
      (Date.now() - (watchHistoryLastSyncedAt.get(key) || 0)),
    0,
  );
  scheduleWatchHistoryFlush(key, boundary, generation, due);
};

/** One flight per lesson; resolves true when nothing is left unaccepted. */
const flushWatchHistoryEntry = (
  key: string,
  boundary: AccountSessionBoundary,
  generation: number,
  ignoreBackoff: boolean,
  completionDrain = false,
): Promise<boolean> => {
  const activeFlight = watchHistoryFlights.get(key);
  if (activeFlight) return activeFlight;
  if (!ownedBy(generation, boundary)) return Promise.resolve(false);
  const lessonId = key.slice(boundary.scope.length + 1);
  const timer = watchHistoryTimers.get(key);
  if (timer) clearTimeout(timer);
  watchHistoryTimers.delete(key);

  const flight: Promise<boolean> = playbackProgressOutbox
    .flush({
      boundary,
      ids: [lessonId],
      ignoreBackoff,
      // Completion gates need the newest evidence, not whichever heartbeat
      // owned the request; heartbeats otherwise keep their 30 s throttle.
      redeliverSuperseded: completionDrain,
      // An accepted sample never waits for its native cleanup.
      awaitRetirement: false,
      deliver: deliverSample(boundary),
    })
    .then(report => {
      if (report.results.some(result => result.outcome === 'ack')) {
        rememberSync(key);
      }
      return report.results.every(result => result.outcome === 'ack');
    })
    .catch(() => false)
    .finally(() => {
      if (watchHistoryFlights.get(key) === flight) {
        watchHistoryFlights.delete(key);
      }
      void scheduleRemaining(key, lessonId, boundary, generation);
    });
  watchHistoryFlights.set(key, flight);
  return flight;
};

const queueWatchHistorySync = async (
  lessonId: number,
  seconds: number,
  durationSeconds: number | undefined,
  completed: boolean,
  context: PlaybackEvidenceContext | undefined,
  owner: AccountSessionBoundary,
  generation: number,
) => {
  assertWatchHistoryOwner(generation, owner);
  const key = `${owner.scope}:${lessonId}`;
  const diagnostics = sanitizePlaybackDiagnostics(context?.diagnostics);
  const next: PendingWatchHistory = {
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
    ...(diagnostics ? {diagnostics} : {}),
  };
  const item = await playbackProgressOutbox.enqueue(String(lessonId), next, {
    boundary: owner,
  });
  if (!ownedBy(generation, owner)) {
    // A runtime reset (logout, token replacement) landed during the native
    // write. Retract only this exact generation; a newer runtime may already
    // have persisted a newer sample at the same id.
    await playbackProgressOutbox
      .remove(String(lessonId), {boundary: owner, generation: item.generation})
      .catch(() => undefined);
    assertWatchHistoryOwner(generation, owner);
  }
  const elapsed = Date.now() - (watchHistoryLastSyncedAt.get(key) || 0);
  if (!item.payload.completed && elapsed < WATCH_HISTORY_SYNC_INTERVAL_MS) {
    if (!watchHistoryTimers.has(key) && !watchHistoryFlights.has(key)) {
      scheduleWatchHistoryFlush(
        key,
        owner,
        generation,
        WATCH_HISTORY_SYNC_INTERVAL_MS - elapsed,
      );
    }
    return;
  }
  const flushed = await flushWatchHistoryEntry(
    key,
    owner,
    generation,
    item.payload.completed,
    item.payload.completed,
  );
  // A completion that arrived while a heartbeat owned the request still has
  // to reach the server now: one bounded drain of the newest sample.
  if (item.payload.completed && flushed) {
    await flushWatchHistoryEntry(key, owner, generation, true, true);
  }
};

/** Lifecycle trigger: flush the newest sample of every lesson now. */
export const flushPendingPlaybackPositions = async () => {
  const generation = playbackRuntimeGeneration;
  let boundary: AccountSessionBoundary;
  let pending: DurableOutboxItem<PendingWatchHistory>[];
  try {
    boundary = await captureAccountSessionBoundary();
    assertWatchHistoryOwner(generation, boundary);
    pending = await playbackProgressOutbox.list({boundary});
    assertWatchHistoryOwner(generation, boundary);
  } catch {
    return;
  }
  pending.forEach(item => {
    if (item.payload.playbackSessionId && item.payload.sequence) {
      restorePlaybackSequence(
        item.payload.playbackSessionId,
        boundary.scope,
        item.payload.sequence,
      );
    }
  });
  await Promise.allSettled(
    pending.map(item =>
      flushWatchHistoryEntry(
        `${boundary.scope}:${item.id}`,
        boundary,
        generation,
        true,
      ),
    ),
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
  watchHistoryFlights.clear();
  // Durable writes stay serialized by the outbox across a runtime reset; the
  // generation check keeps an old response from applying to the new owner.
  watchHistoryLastSyncedAt.clear();
};
