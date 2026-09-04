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

export const PENDING_PRIVACY_PREFERENCES_KEY =
  '@rokn/pending-privacy-preferences/v1';
export const PENDING_WATCH_HISTORY_CLEAR_KEY =
  '@rokn/pending-watch-history-clear/v1';

export type PendingPrivacyPreferences = {
  watchHistoryEnabled?: boolean;
  marketingNotificationsEnabled?: boolean;
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

export const readPendingPrivacyPreferences = async (
  storageKey?: string,
  ownerBoundary?: AccountSessionBoundary,
) =>
  (await getItem<PendingPrivacyPreferences>(
    storageKey || (await privacyKey(ownerBoundary)),
  )) || {};

const flushWithinBoundary = async (boundary: AccountSessionBoundary) => {
  assertAccountSessionBoundary(boundary);
  const token = extractApiToken(await getItem(AsyncKeys.USER_DATA));
  assertAccountSessionBoundary(boundary);
  if (!token) return;

  const [pendingKey, historyKey] = await Promise.all([
    privacyKey(boundary),
    watchClearKey(boundary),
  ]);
  const [preferences, clearHistoryPending] = await Promise.all([
    readPendingPrivacyPreferences(pendingKey),
    getItem<boolean>(historyKey),
  ]);
  assertAccountSessionBoundary(boundary);

  if (Object.keys(preferences).length) {
    try {
      await updatePrivacyPreferences(preferences, boundary);
      assertAccountSessionBoundary(boundary);
      await removeItem(pendingKey);
    } catch {
      // The account-scoped record remains the source of truth until retry.
    }
  }

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
  return withScopeWrite(boundary, () => flushWithinBoundary(boundary));
};

export const queuePendingPrivacyPreferences = async (
  patch: PendingPrivacyPreferences,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  return withScopeWrite(boundary, async () => {
    assertAccountSessionBoundary(boundary);
    const key = await privacyKey(boundary);
    const pending = {
      ...(await readPendingPrivacyPreferences(key)),
      ...patch,
    };
    assertAccountSessionBoundary(boundary);
    if (!Object.keys(pending).length) return;
    const stored = await saveItem(key, pending);
    if (!stored) throw new Error('DURABLE_ACCOUNT_WRITE_UNAVAILABLE');
    await flushWithinBoundary(boundary);
  });
};
