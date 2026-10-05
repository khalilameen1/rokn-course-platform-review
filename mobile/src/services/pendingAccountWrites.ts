import {
  AsyncKeys,
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {clearWatchHistory, updatePrivacyPreferences} from './roknApi';
import {flushPlaybackPreferenceWrites} from './playbackPreferenceSync';
import {REMINDER_HOUR_KEY, setSmartReminderHour} from './smartReminders';
import {WATCH_HISTORY_ENABLED_KEY} from '../components/VideoPlayer/courseLearning/persistence';
import {
  markPrivacyPreferenceMutation,
  withAccountPreferenceWrite,
} from './accountPreferenceWrites';

export const PENDING_PRIVACY_PREFERENCES_KEY =
  '@rokn/pending-privacy-preferences/v1';
export const PENDING_WATCH_HISTORY_CLEAR_KEY =
  '@rokn/pending-watch-history-clear/v1';
export const MARKETING_NOTIFICATIONS_KEY = 'PREF_MARKETING_NOTIFICATIONS';

export type PendingPrivacyPreferences = {
  watchHistoryEnabled?: boolean;
  marketingNotificationsEnabled?: boolean;
  learningReminderHour?: number;
  learningReminderTimezone?: string;
};
type PendingRecord = {revision: number; patch: PendingPrivacyPreferences};
type SyncFlight = {epoch: number; promise: Promise<void>};
const preferenceFlights = new Map<string, SyncFlight>();

const validPatch = (value: unknown): PendingPrivacyPreferences => {
  const candidate = value && typeof value === 'object'
    ? value as PendingPrivacyPreferences : {};
  const patch: PendingPrivacyPreferences = {};
  if (typeof candidate.watchHistoryEnabled === 'boolean')
    patch.watchHistoryEnabled = candidate.watchHistoryEnabled;
  if (typeof candidate.marketingNotificationsEnabled === 'boolean')
    patch.marketingNotificationsEnabled = candidate.marketingNotificationsEnabled;
  if (typeof candidate.learningReminderHour === 'number'
    && [10, 15, 20].includes(candidate.learningReminderHour)
    && typeof candidate.learningReminderTimezone === 'string'
    && candidate.learningReminderTimezone.trim()) {
    patch.learningReminderHour = candidate.learningReminderHour;
    patch.learningReminderTimezone = candidate.learningReminderTimezone.trim();
  }
  return patch;
};

const scopeTails = new Map<string, Promise<unknown>>();

const withScopeWrite = <T>(
  boundary: AccountSessionBoundary,
  callback: () => Promise<T>,
): Promise<T> => {
  const previous = scopeTails.get(boundary.scope) ?? Promise.resolve();
  const result = previous.then(callback, callback);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  scopeTails.set(boundary.scope, tail);
  void tail.finally(() => {
    if (scopeTails.get(boundary.scope) === tail) {
      scopeTails.delete(boundary.scope);
    }
  });
  return result;
};

const privacyKey = (boundary?: AccountSessionBoundary) =>
  accountScopedStorageKey(PENDING_PRIVACY_PREFERENCES_KEY, boundary);

const watchClearKey = (boundary?: AccountSessionBoundary) =>
  accountScopedStorageKey(PENDING_WATCH_HISTORY_CLEAR_KEY, boundary);

const readRecord = async (
  boundary: AccountSessionBoundary,
  storageKey?: string,
): Promise<PendingRecord | null> => {
  assertAccountSessionBoundary(boundary);
  const key = storageKey || await privacyKey(boundary);
  assertAccountSessionBoundary(boundary);
  const value = await getItem<PendingRecord | PendingPrivacyPreferences>(key);
  assertAccountSessionBoundary(boundary);
  if (!value || typeof value !== 'object') return null;
  // v1 installations stored a plain patch under this same account-scoped key.
  // Retain those intents; new writes use a revisioned envelope, not another key.
  const envelope = 'patch' in value && 'revision' in value;
  if (envelope && (!Number.isSafeInteger(value.revision) || value.revision < 1))
    return null;
  const patch = validPatch(envelope ? value.patch : value);
  return Object.keys(patch).length
    ? {revision: envelope ? value.revision : 0, patch} : null;
};

export const readPendingPrivacyPreferences = async (
  storageKey?: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PendingPrivacyPreferences> => {
  const boundary = ownerBoundary || await captureAccountSessionBoundary();
  return (await readRecord(boundary, storageKey))?.patch ?? {};
};

const mirrorPreferenceCache = async (
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
      complete = (field === 'learningReminderHour'
        ? await setSmartReminderHour(patch.learningReminderHour!, boundary)
        : await saveItem(key, patch[field])) && complete;
    }
    assertAccountSessionBoundary(boundary);
  }
  return complete;
};

/** Same revision/ACK protocol as playback preferences; HTTP never owns the native queue. */
export const flushPrivacyPreferenceWrites = (
  boundary: AccountSessionBoundary,
  {afterCurrent = false}: {afterCurrent?: boolean} = {},
): Promise<void> => {
  assertAccountSessionBoundary(boundary);
  if (boundary.scope.startsWith('guest-')) return Promise.resolve();
  const owner = boundary.scope;
  const current = preferenceFlights.get(owner);
  if (current) {
    if (current.epoch === boundary.epoch && !afterCurrent) return current.promise;
    const resume = () => flushPrivacyPreferenceWrites(boundary);
    return current.promise.then(resume, resume);
  }
  const work: Promise<void> = Promise.resolve().then(async () => {
    try {
      for (;;) {
        const pending = await withAccountPreferenceWrite(boundary, () =>
          readRecord(boundary));
        if (!pending) return;
        try {
          assertAccountSessionBoundary(boundary);
          await updatePrivacyPreferences(pending.patch, boundary);
          assertAccountSessionBoundary(boundary);
        } catch {
          assertAccountSessionBoundary(boundary);
          return; // Retry from the persisted intent on entry/foreground.
        }
        const acknowledged = await withAccountPreferenceWrite(boundary, async () => {
          const latest = await readRecord(boundary);
          if (latest?.revision !== pending.revision) return false;
          if (!(await mirrorPreferenceCache(latest.patch, boundary))) return false;
          const key = await privacyKey(boundary);
          assertAccountSessionBoundary(boundary);
          const removed = await removeItem(key);
          assertAccountSessionBoundary(boundary);
          return removed;
        });
        if (acknowledged) continue;
        const latest = await withAccountPreferenceWrite(boundary, () => readRecord(boundary));
        if (!latest || latest.revision === pending.revision) return;
      }
    } finally {
      if (preferenceFlights.get(owner)?.promise === work) preferenceFlights.delete(owner);
    }
  });
  preferenceFlights.set(owner, {epoch: boundary.epoch, promise: work});
  return work;
};

const flushWatchHistoryClear = async (boundary: AccountSessionBoundary) => {
  assertAccountSessionBoundary(boundary);
  const token = extractApiToken(await getItem(AsyncKeys.USER_DATA));
  assertAccountSessionBoundary(boundary);
  if (!token) return;

  const historyKey = await watchClearKey(boundary);
  assertAccountSessionBoundary(boundary);
  const clearHistoryPending = await getItem<boolean>(historyKey);
  assertAccountSessionBoundary(boundary);

  if (clearHistoryPending) {
    try {
      assertAccountSessionBoundary(boundary);
      await clearWatchHistory(boundary);
      assertAccountSessionBoundary(boundary);
      await removeItem(historyKey);
    } catch {
      // Keep the deletion intent across process restarts and offline periods.
    }
  }
};

/** Retry every durable account preference write without opening permission UI. */
export const flushPendingAccountWrites = async (): Promise<void> => {
  const boundary = await captureAccountSessionBoundary();
  await Promise.all([
    flushPrivacyPreferenceWrites(boundary, {afterCurrent: true}),
    withScopeWrite(boundary, () => flushWatchHistoryClear(boundary)),
    flushPlaybackPreferenceWrites(boundary, {afterCurrent: true}),
  ]);
};

export const queuePendingPrivacyPreferences = async (
  patch: PendingPrivacyPreferences,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const normalized = validPatch(patch);
  if (Object.keys(normalized).length !== Object.keys(patch).length)
    throw new Error('ACCOUNT_PREFERENCE_INVALID');
  if (normalized.watchHistoryEnabled !== undefined)
    markPrivacyPreferenceMutation(boundary, 'watchHistory');
  if (normalized.marketingNotificationsEnabled !== undefined)
    markPrivacyPreferenceMutation(boundary, 'marketing');
  if (normalized.learningReminderHour !== undefined)
    markPrivacyPreferenceMutation(boundary, 'reminder');
  await withAccountPreferenceWrite(boundary, async () => {
    assertAccountSessionBoundary(boundary);
    const key = await privacyKey(boundary);
    const previous = await readRecord(boundary, key);
    const pending = {...previous?.patch, ...normalized};
    assertAccountSessionBoundary(boundary);
    if (!Object.keys(pending).length) return;
    if (!Object.keys(normalized).length) {
      await mirrorPreferenceCache(pending, boundary);
      return;
    }
    const stored = await saveItem(key, {
      revision: (previous?.revision ?? 0) + 1, patch: pending,
    });
    assertAccountSessionBoundary(boundary);
    if (!stored) throw new Error('DURABLE_ACCOUNT_WRITE_UNAVAILABLE');
    // The journal remains authoritative if an optimistic cache mirror fails.
    try {
      await mirrorPreferenceCache(pending, boundary);
    } catch {
      // Accepted durable intent can repair a failed cache read/write later.
      // Account changes still retire this owner's continuation.
      assertAccountSessionBoundary(boundary);
    }
  });
  void flushPrivacyPreferenceWrites(boundary, {afterCurrent: true}).catch(() => undefined);
};
