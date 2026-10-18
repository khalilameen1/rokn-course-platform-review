import AsyncStorage from '@react-native-async-storage/async-storage';
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
  clearWatchHistory,
  updatePlaybackPreferences,
  updatePrivacyPreferences,
} from './roknApi';
import {REMINDER_HOUR_KEY, setSmartReminderHour} from './smartReminders';
import {WATCH_HISTORY_ENABLED_KEY} from './learningPlayerState';
import {
  markPlaybackPreferenceMutation,
  markPrivacyPreferenceMutation,
  withAccountPreferenceWrite,
} from './accountPreferenceWrites';
import {
  createDurableOutbox,
  type DurableOutboxDelivery,
  type DurableOutboxItem,
} from './durableOutbox';

/**
 * Typed M2 adapter: account writes that must survive offline time and app
 * kills. One outbox item per kind and account; a new edit coalesces into the
 * pending one and gets a new generation, so an acknowledgement only retires
 * the exact version it sent (A -> B -> A is still sent).
 */
export const MARKETING_NOTIFICATIONS_KEY = 'PREF_MARKETING_NOTIFICATIONS';

export type PendingPrivacyPreferences = {
  watchHistoryEnabled?: boolean;
  marketingNotificationsEnabled?: boolean;
  learningReminderHour?: number;
  learningReminderTimezone?: string;
};

export type PlaybackPreferencePatch = {
  videoQualityPreference?: string;
  playbackSpeed?: number;
};

type AccountWrite =
  | {kind: 'privacy_preferences'; patch: PendingPrivacyPreferences}
  | {kind: 'playback_preferences'; patch: PlaybackPreferencePatch}
  | {kind: 'watch_history_clear'};

const LEGACY_KEYS = {
  privacy_preferences: '@rokn/pending-privacy-preferences/v1',
  playback_preferences: '@rokn/pending-playback-preferences/v1',
  watch_history_clear: '@rokn/pending-watch-history-clear/v1',
} as const;

const qualityValues = ['auto', 'data_saver', '1080p', '720p', '480p', '360p'];
const speedValues = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
const playbackCacheFields = [
  ['videoQualityPreference', 'VIDEO_QUALITY'],
  ['playbackSpeed', 'VIDEO_PLAYBACK_SPEED'],
] as const;

const validPrivacyPatch = (value: unknown): PendingPrivacyPreferences => {
  const candidate =
    value && typeof value === 'object'
      ? (value as PendingPrivacyPreferences)
      : {};
  const patch: PendingPrivacyPreferences = {};
  if (typeof candidate.watchHistoryEnabled === 'boolean')
    patch.watchHistoryEnabled = candidate.watchHistoryEnabled;
  if (typeof candidate.marketingNotificationsEnabled === 'boolean')
    patch.marketingNotificationsEnabled =
      candidate.marketingNotificationsEnabled;
  if (
    typeof candidate.learningReminderHour === 'number' &&
    [10, 15, 20].includes(candidate.learningReminderHour) &&
    typeof candidate.learningReminderTimezone === 'string' &&
    candidate.learningReminderTimezone.trim()
  ) {
    patch.learningReminderHour = candidate.learningReminderHour;
    patch.learningReminderTimezone = candidate.learningReminderTimezone.trim();
  }
  return patch;
};

const validPlaybackPatch = (value: unknown): PlaybackPreferencePatch => {
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

const decodeWrite = (value: unknown): AccountWrite | null => {
  const write = value as Partial<AccountWrite> & {patch?: unknown};
  if (write?.kind === 'watch_history_clear') return {kind: write.kind};
  if (write?.kind === 'privacy_preferences') {
    const patch = validPrivacyPatch(write.patch);
    return Object.keys(patch).length ? {kind: write.kind, patch} : null;
  }
  if (write?.kind === 'playback_preferences') {
    const patch = validPlaybackPatch(write.patch);
    return Object.keys(patch).length ? {kind: write.kind, patch} : null;
  }
  return null;
};

const privacyPatchOf = (write: AccountWrite) =>
  write.kind === 'privacy_preferences' ? write.patch : null;
const playbackPatchOf = (write: AccountWrite) =>
  write.kind === 'playback_preferences' ? write.patch : null;

const readLegacy = async (base: string, scope: string): Promise<unknown> => {
  const raw = await AsyncStorage.getItem(`${base}:${scope}`);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
};

/** v1 journals: `{revision, patch}`; privacy v1 also stored a bare patch. */
const legacyPatch = (value: unknown) =>
  value && typeof value === 'object' && 'patch' in value
    ? (value as {patch: unknown}).patch
    : value;

export const accountWritesOutbox = createDurableOutbox<AccountWrite>({
  namespace: '@rokn/account-writes/v1',
  accountScoped: true,
  maxItems: 8,
  ttlMs: 90 * 24 * 60 * 60 * 1000,
  backoff: {baseMs: 5_000, maxMs: 30 * 60 * 1000},
  decode: decodeWrite,
  // Each kind has its own endpoint: one failing never holds back another.
  groupOf: write => write.kind,
  coalesce: (previous, next) =>
    'patch' in previous && 'patch' in next && previous.kind === next.kind
      ? ({kind: next.kind, patch: {...previous.patch, ...next.patch}} as AccountWrite)
      : next,
  legacy: {
    read: async scope => {
      const [privacy, playback, clear] = await Promise.all([
        readLegacy(LEGACY_KEYS.privacy_preferences, scope),
        readLegacy(LEGACY_KEYS.playback_preferences, scope),
        readLegacy(LEGACY_KEYS.watch_history_clear, scope),
      ]);
      return [
        decodeWrite({kind: 'privacy_preferences', patch: legacyPatch(privacy)}),
        decodeWrite({kind: 'playback_preferences', patch: legacyPatch(playback)}),
        clear === true ? decodeWrite({kind: 'watch_history_clear'}) : null,
      ].flatMap(write => (write ? [{id: write.kind, payload: write}] : []));
    },
    clear: async scope => {
      await Promise.all(
        Object.values(LEGACY_KEYS).map(base =>
          AsyncStorage.removeItem(`${base}:${scope}`),
        ),
      );
    },
  },
});

const mirrorPrivacyCache = async (
  patch: PendingPrivacyPreferences,
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  let complete = true;
  const fields = [
    ['watchHistoryEnabled', WATCH_HISTORY_ENABLED_KEY],
    ['marketingNotificationsEnabled', MARKETING_NOTIFICATIONS_KEY],
    ['learningReminderHour', REMINDER_HOUR_KEY],
  ] as const;
  for (const [field, cacheKey] of fields) {
    if (patch[field] === undefined) continue;
    const key = await accountScopedStorageKey(cacheKey, boundary);
    assertAccountSessionBoundary(boundary);
    if ((await getItem(key)) !== patch[field]) {
      assertAccountSessionBoundary(boundary);
      complete =
        (field === 'learningReminderHour'
          ? await setSmartReminderHour(patch.learningReminderHour!, boundary)
          : await saveItem(key, patch[field])) && complete;
    }
    assertAccountSessionBoundary(boundary);
  }
  return complete;
};

const mirrorPlaybackCache = async (
  patch: PlaybackPreferencePatch,
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  let complete = true;
  for (const [field, cacheKey] of playbackCacheFields) {
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

/** The deliver function: one request per kind, then the local mirror. */
const deliverAccountWrite =
  (boundary: AccountSessionBoundary) =>
  async (
    item: DurableOutboxItem<AccountWrite>,
  ): Promise<DurableOutboxDelivery<AccountWrite>> => {
    const write = item.payload;
    assertAccountSessionBoundary(boundary);
    if (write.kind === 'watch_history_clear') {
      const token = extractApiToken(await getItem(AsyncKeys.USER_DATA));
      assertAccountSessionBoundary(boundary);
      if (!token) return {outcome: 'keep'};
      await clearWatchHistory(boundary);
      assertAccountSessionBoundary(boundary);
      return {outcome: 'ack'};
    }
    if (write.kind === 'privacy_preferences') {
      await updatePrivacyPreferences(write.patch, boundary);
      assertAccountSessionBoundary(boundary);
      // A successful server write cannot retire the only durable local copy
      // if the optimistic cache write failed: repair it first.
      return {
        outcome: 'ack',
        commit: current =>
          mirrorPrivacyCache(privacyPatchOf(current.payload) ?? {}, boundary),
      };
    }
    await updatePlaybackPreferences(write.patch, boundary);
    assertAccountSessionBoundary(boundary);
    return {
      outcome: 'ack',
      commit: current =>
        mirrorPlaybackCache(playbackPatchOf(current.payload) ?? {}, boundary),
    };
  };

/**
 * Deliver every pending account write of this owner. Saves, foreground,
 * startup and reconnection are lifecycle triggers, so they do not wait for
 * the automatic backoff clock; HTTP never holds the preference write lock.
 */
export const flushAccountWrites = async (
  boundary: AccountSessionBoundary,
  {afterCurrent = false}: {afterCurrent?: boolean} = {},
): Promise<void> => {
  assertAccountSessionBoundary(boundary);
  if (boundary.scope.startsWith('guest-')) return;
  await accountWritesOutbox.flush({
    boundary,
    afterCurrent,
    ignoreBackoff: true,
    deliver: deliverAccountWrite(boundary),
  });
};

/** Retry every durable account preference write without opening permission UI. */
export const flushPendingAccountWrites = async (): Promise<void> => {
  const boundary = await captureAccountSessionBoundary();
  await flushAccountWrites(boundary, {afterCurrent: true});
};

const pendingWrite = async (
  kind: AccountWrite['kind'],
  boundary: AccountSessionBoundary,
) => {
  const item = await accountWritesOutbox.get(kind, {boundary});
  assertAccountSessionBoundary(boundary);
  return item?.payload ?? null;
};

export const readPendingPrivacyPreferences = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<PendingPrivacyPreferences> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const write = await pendingWrite('privacy_preferences', boundary);
  return (write && privacyPatchOf(write)) ?? {};
};

export const readPendingPlaybackPreferences = async (
  boundary: AccountSessionBoundary,
): Promise<PlaybackPreferencePatch> => {
  const write = await pendingWrite('playback_preferences', boundary);
  return (write && playbackPatchOf(write)) ?? {};
};

/** Persist intent before optimistic cache writes; do not await connectivity. */
export const queuePendingPrivacyPreferences = async (
  patch: PendingPrivacyPreferences,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const normalized = validPrivacyPatch(patch);
  if (Object.keys(normalized).length !== Object.keys(patch).length)
    throw new Error('ACCOUNT_PREFERENCE_INVALID');
  if (normalized.watchHistoryEnabled !== undefined)
    markPrivacyPreferenceMutation(boundary, 'watchHistory');
  if (normalized.marketingNotificationsEnabled !== undefined)
    markPrivacyPreferenceMutation(boundary, 'marketing');
  if (normalized.learningReminderHour !== undefined)
    markPrivacyPreferenceMutation(boundary, 'reminder');
  // Settings and playback share this native preference write owner.
  await withAccountPreferenceWrite(boundary, async () => {
    if (!Object.keys(normalized).length) {
      const pending = await readPendingPrivacyPreferences(boundary);
      if (Object.keys(pending).length) {
        await mirrorPrivacyCache(pending, boundary);
      }
      return;
    }
    let stored: DurableOutboxItem<AccountWrite>;
    try {
      stored = await accountWritesOutbox.enqueue(
        'privacy_preferences',
        {kind: 'privacy_preferences', patch: normalized},
        {boundary},
      );
    } catch (error) {
      assertAccountSessionBoundary(boundary);
      throw Object.assign(new Error('DURABLE_ACCOUNT_WRITE_UNAVAILABLE'), {
        cause: error,
      });
    }
    assertAccountSessionBoundary(boundary);
    // The outbox stays authoritative if an optimistic cache mirror fails.
    try {
      await mirrorPrivacyCache(privacyPatchOf(stored.payload) ?? {}, boundary);
    } catch {
      // The accepted durable intent repairs a failed cache write later.
      assertAccountSessionBoundary(boundary);
    }
  });
  void flushAccountWrites(boundary, {afterCurrent: true}).catch(
    () => undefined,
  );
};

/** Persist intent before optimistic cache writes; do not await connectivity. */
export const savePlaybackPreferencePatch = async (
  patch: PlaybackPreferencePatch,
  boundary: AccountSessionBoundary,
): Promise<void> => {
  assertAccountSessionBoundary(boundary);
  const normalized = validPlaybackPatch(patch);
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
      try {
        await accountWritesOutbox.enqueue(
          'playback_preferences',
          {kind: 'playback_preferences', patch: normalized},
          {boundary},
        );
      } catch (error) {
        assertAccountSessionBoundary(boundary);
        throw Object.assign(new Error('SETTINGS_STORAGE_WRITE_FAILED'), {
          cause: error,
        });
      }
      assertAccountSessionBoundary(boundary);
    }
    for (const [field, key] of playbackCacheFields) {
      if (normalized[field] === undefined) continue;
      const storageKey = await accountScopedStorageKey(key, boundary);
      assertAccountSessionBoundary(boundary);
      const stored = await saveItem(storageKey, normalized[field]);
      assertAccountSessionBoundary(boundary);
      // Signed-in playback can recover from its outbox even if a cache write
      // fails. Guests have no server journal and must report the failure.
      if (!stored && !authenticated)
        throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
    }
  });
  if (authenticated)
    void flushAccountWrites(boundary, {afterCurrent: true}).catch(
      () => undefined,
    );
};

/**
 * Clear the server watch history now; when that fails, keep the deletion
 * intent durable and replay it on the next lifecycle trigger.
 * Resolves true only when the server has accepted the deletion.
 */
export const requestWatchHistoryClear = async (
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  assertAccountSessionBoundary(boundary);
  try {
    await clearWatchHistory(boundary);
    assertAccountSessionBoundary(boundary);
    await accountWritesOutbox
      .remove('watch_history_clear', {boundary})
      .catch(() => undefined);
    assertAccountSessionBoundary(boundary);
    return true;
  } catch {
    assertAccountSessionBoundary(boundary);
    await accountWritesOutbox.enqueue(
      'watch_history_clear',
      {kind: 'watch_history_clear'},
      {boundary},
    );
    assertAccountSessionBoundary(boundary);
    return false;
  }
};
