import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  getItem,
  removeItem,
  saveItem,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {updatePlaybackPreferences} from './roknApi';
import {
  markPlaybackPreferenceMutation,
  withAccountPreferenceWrite,
} from './accountPreferenceWrites';

export type PlaybackPreferencePatch = {
  videoQualityPreference?: string;
  playbackSpeed?: number;
};
type PendingRecord = {revision: number; patch: PlaybackPreferencePatch};
export const PENDING_PLAYBACK_PREFERENCES_KEY =
  '@rokn/pending-playback-preferences/v1';
type SyncFlight = {epoch: number; promise: Promise<void>};
const flights = new Map<string, SyncFlight>();
const qualityValues = ['auto', 'data_saver', '1080p', '720p', '480p', '360p'];
const speedValues = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
const pendingKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(PENDING_PLAYBACK_PREFERENCES_KEY, boundary);
const cacheFields = [
  ['videoQualityPreference', 'VIDEO_QUALITY'],
  ['playbackSpeed', 'VIDEO_PLAYBACK_SPEED'],
] as const;

const mirrorPreferenceCache = async (
  patch: PlaybackPreferencePatch,
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  let complete = true;
  for (const [field, cacheKey] of cacheFields) {
    if (patch[field] === undefined) continue;
    const key = await accountScopedStorageKey(cacheKey, boundary);
    assertAccountSessionBoundary(boundary);
    if ((await getItem(key)) !== patch[field]) {
      assertAccountSessionBoundary(boundary);
      complete = (await saveItem(key, patch[field])) && complete;
    }
    assertAccountSessionBoundary(boundary);
  }
  return complete;
};

const validPatch = (value: unknown): PlaybackPreferencePatch => {
  const candidate =
    value && typeof value === 'object'
      ? (value as PlaybackPreferencePatch)
      : {};
  const patch: PlaybackPreferencePatch = {};
  if (
    typeof candidate.videoQualityPreference === 'string' &&
    qualityValues.includes(candidate.videoQualityPreference)
  ) {
    patch.videoQualityPreference = candidate.videoQualityPreference;
  }
  if (
    typeof candidate.playbackSpeed === 'number' &&
    speedValues.includes(candidate.playbackSpeed)
  ) {
    patch.playbackSpeed = candidate.playbackSpeed;
  }
  return patch;
};

const readRecord = async (
  boundary: AccountSessionBoundary,
): Promise<PendingRecord | null> => {
  assertAccountSessionBoundary(boundary);
  const key = await pendingKey(boundary);
  assertAccountSessionBoundary(boundary);
  const value = await getItem<PendingRecord>(key);
  assertAccountSessionBoundary(boundary);
  if (!value || !Number.isSafeInteger(value.revision) || value.revision < 1)
    return null;
  const patch = validPatch(value.patch);
  return Object.keys(patch).length ? {revision: value.revision, patch} : null;
};

export const readPendingPlaybackPreferences = async (
  boundary: AccountSessionBoundary,
): Promise<PlaybackPreferencePatch> =>
  (await readRecord(boundary))?.patch ?? {};

/** One coalescing journal per account. HTTP never holds the local write queue. */
export const flushPlaybackPreferenceWrites = (
  boundary: AccountSessionBoundary,
  {afterCurrent = false}: {afterCurrent?: boolean} = {},
): Promise<void> => {
  assertAccountSessionBoundary(boundary);
  if (boundary.scope.startsWith('guest-')) return Promise.resolve();
  const owner = boundary.scope;
  const current = flights.get(owner);
  if (current) {
    if (current.epoch === boundary.epoch && !afterCurrent)
      return current.promise;
    // Updating the stored profile can advance the epoch without changing the
    // account. New edits/reconnection also require a probe after an old failed
    // request, not just joining it. Local edits never wait for network work.
    const resume = () => flushPlaybackPreferenceWrites(boundary);
    return current.promise.then(resume, resume);
  }
  // Start on the next microtask so ownership is installed before any I/O.
  const work: Promise<void> = Promise.resolve().then(async () => {
    try {
      for (;;) {
        const pending = await withAccountPreferenceWrite(boundary, () =>
          readRecord(boundary),
        );
        if (!pending) return;
        try {
          assertAccountSessionBoundary(boundary);
          await updatePlaybackPreferences(pending.patch, boundary);
          assertAccountSessionBoundary(boundary);
        } catch {
          assertAccountSessionBoundary(boundary);
          return; // Keep the durable command for entry/foreground retry.
        }
        const acknowledged = await withAccountPreferenceWrite(
          boundary,
          async () => {
            const latest = await readRecord(boundary);
            if (latest?.revision !== pending.revision) return false;
            // A successful server write cannot retire the only durable local
            // copy if the optimistic cache write failed. Repair it first.
            if (!(await mirrorPreferenceCache(latest.patch, boundary)))
              return false;
            // The acknowledgement belongs only to this exact persisted revision.
            // A newer choice, including A→B→A, must still be sent.
            const key = await pendingKey(boundary);
            assertAccountSessionBoundary(boundary);
            const removed = await removeItem(key);
            assertAccountSessionBoundary(boundary);
            return removed;
          },
        );
        // Drain again even after acknowledgement: a new edit may already be
        // queued behind the cache/delete operation and belongs to this worker.
        if (acknowledged) continue;
        // If native deletion failed, retry on a later lifecycle event, not a loop.
        const latest = await withAccountPreferenceWrite(boundary, () =>
          readRecord(boundary),
        );
        if (!latest || latest.revision === pending.revision) return;
      }
    } finally {
      // Retire synchronously before resolving the promise. A following save
      // must start a new worker, not join an already-finished flight.
      if (flights.get(owner)?.promise === work) flights.delete(owner);
    }
  });
  flights.set(owner, {epoch: boundary.epoch, promise: work});
  return work;
};

/** Persist intent before optimistic cache writes; do not await connectivity. */
export const savePlaybackPreferencePatch = async (
  patch: PlaybackPreferencePatch,
  boundary: AccountSessionBoundary,
): Promise<void> => {
  assertAccountSessionBoundary(boundary);
  const normalized = validPatch(patch);
  if (
    Object.keys(normalized).length !== Object.keys(patch).length ||
    !Object.keys(normalized).length
  ) {
    throw new Error('PLAYBACK_PREFERENCE_INVALID');
  }
  if (normalized.videoQualityPreference !== undefined)
    markPlaybackPreferenceMutation(boundary, 'quality');
  if (normalized.playbackSpeed !== undefined)
    markPlaybackPreferenceMutation(boundary, 'speed');
  const authenticated = !boundary.scope.startsWith('guest-');
  await withAccountPreferenceWrite(boundary, async () => {
    if (authenticated) {
      const previous = await readRecord(boundary);
      const key = await pendingKey(boundary);
      assertAccountSessionBoundary(boundary);
      const stored = await saveItem(key, {
        revision: (previous?.revision ?? 0) + 1,
        patch: {...previous?.patch, ...normalized},
      });
      assertAccountSessionBoundary(boundary);
      if (!stored) throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
    }
    for (const [field, key] of cacheFields) {
      if (normalized[field] === undefined) continue;
      const storageKey = await accountScopedStorageKey(key, boundary);
      assertAccountSessionBoundary(boundary);
      const stored = await saveItem(storageKey, normalized[field]);
      assertAccountSessionBoundary(boundary);
      // Signed-in playback can recover from its journal even if a cache write
      // fails. Guests have no server journal and must report persistence failure.
      if (!stored && !authenticated)
        throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
    }
  });
  if (authenticated)
    void flushPlaybackPreferenceWrites(boundary, {afterCurrent: true}).catch(
      () => undefined,
    );
};
