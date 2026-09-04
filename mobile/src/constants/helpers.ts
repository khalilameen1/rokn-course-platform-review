import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import {
  deleteSecureSession,
  extractApiToken,
  extractUserProfile,
  loadSecureSession,
  peekSecureSession,
  saveSecureSession,
  sessionIdentityKey,
} from '../services/secureSession';
export enum AsyncKeys {
  USER_DATA = 'USER_DATA',
  PENDING_WELCOME_BONUS = 'PENDING_WELCOME_BONUS',
}

const GUEST_STORAGE_ID_KEY = '@rokn/guest-storage-id/v1';
let guestStorageIdentityPromise: Promise<string> | null = null;

export {extractApiToken, extractUserProfile, sessionIdentityKey};

const errorMessage = (error: unknown) =>
  error instanceof Error ? error.message : String(error);

export const saveItem = async (key: string, data: unknown) => {
  try {
    if (key === AsyncKeys.USER_DATA) {
      await saveSecureSession(data);
      return true;
    }
    await AsyncStorage.setItem(key, JSON.stringify(data));
    return true;
  } catch (error) {
    if (__DEV__) console.warn('saveItem', errorMessage(error));
  }
  return false;
};

export const getItem = async <T = unknown>(key: string): Promise<T | null> => {
  try {
    if (key === AsyncKeys.USER_DATA) {
      return (await loadSecureSession()) as T | null;
    }
    const retrievedItem = await AsyncStorage.getItem(key);
    return retrievedItem === null ? null : (JSON.parse(retrievedItem) as T);
  } catch (error) {
    if (__DEV__) console.warn('getItem', errorMessage(error));
  }
  return null;
};

const storageIdentityHash = async (value: string) =>
  (
    await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, value)
  ).slice(0, 24);

const getGuestStorageIdentity = (): Promise<string> => {
  if (!guestStorageIdentityPromise) {
    const flight = (async () => {
      const stored = String(
        (await AsyncStorage.getItem(GUEST_STORAGE_ID_KEY).catch(
          () => null,
        )) || '',
      ).trim();
      if (/^[0-9a-f-]{32,64}$/i.test(stored)) return stored;
      const created = Crypto.randomUUID();
      // A full device may reject this non-sensitive identity write. Keep one
      // process-stable guest scope so the app remains usable; the next launch
      // deliberately starts a fresh anonymous scope rather than sharing data.
      await AsyncStorage.setItem(GUEST_STORAGE_ID_KEY, created).catch(
        () => undefined,
      );
      return created;
    })();
    guestStorageIdentityPromise = flight;
    void flight.catch(() => {
      if (guestStorageIdentityPromise === flight) {
        guestStorageIdentityPromise = null;
      }
    });
  }
  return guestStorageIdentityPromise as Promise<string>;
};

/**
 * Account-scoped local data uses separate AsyncStorage keys derived from the
 * bootstrap session snapshot, including before Redux hydration and offline.
 * Before that snapshot is ready, public journeys remain in the guest scope.
 */
const accountStorageScopeForSession = async (
  session: unknown,
): Promise<string> => {
  // Bootstrap owns the native keychain read. Storage consumers must not start
  // a second read that can hold guest screens indefinitely on a locked device.
  const profile = extractUserProfile(session);
  const stableIdentity =
    profile?.id ?? profile?.user_id ?? profile?.social_id ?? profile?.email;
  if (stableIdentity === undefined || stableIdentity === null) {
    return `guest-${await storageIdentityHash(
      await getGuestStorageIdentity(),
    )}`;
  }
  const normalizedIdentity = String(stableIdentity).trim().toLowerCase();
  const identityHash = await storageIdentityHash(normalizedIdentity);
  return `user-${identityHash}`;
};

export const getCurrentAccountStorageScope = async (): Promise<string> => {
  const cachedSession = peekSecureSession();
  return accountStorageScopeForSession(
    cachedSession.ready ? cachedSession.session : null,
  );
};

/** Stable anonymous journey identity, including while that journey is being
 * attached to a newly authenticated account. */
export const getCurrentGuestJourneyScope = async (): Promise<string> =>
  `guest-${await storageIdentityHash(await getGuestStorageIdentity())}`;

export type AccountSessionBoundary = Readonly<{
  epoch: number;
  scope: string;
}>;

const accountBoundaryStillMatches = (boundary: AccountSessionBoundary) => {
  const current = peekSecureSession();
  if (current.epoch === boundary.epoch) return true;
  // On a slow physical keychain AppInitializer deliberately opens the guest
  // shell before restore finishes. Resolving that same empty restore advances
  // the epoch, but it is not an account switch and must not discard public
  // catalogue/auth discovery responses already owned by this guest scope.
  return (
    boundary.scope.startsWith('guest-') &&
    current.ready &&
    !extractApiToken(current.session)
  );
};

/**
 * Capture one owner for a complete async read/write operation. The epoch also
 * covers guest-to-user bootstrap where comparing storage keys alone is too
 * late: a response already in flight must not render into the new session.
 */
export const captureAccountSessionBoundary =
  async (): Promise<AccountSessionBoundary> => {
    const snapshot = peekSecureSession();
    const scope = await accountStorageScopeForSession(
      snapshot.ready ? snapshot.session : null,
    );
    const boundary = {epoch: snapshot.epoch, scope};
    if (!accountBoundaryStillMatches(boundary)) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
    return boundary;
  };

export const assertAccountSessionBoundary = (
  boundary: AccountSessionBoundary,
) => {
  if (!accountBoundaryStillMatches(boundary)) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
};

/** Start a clean anonymous journey when a learner leaves the device. */
export const rotateGuestStorageScope = async () => {
  const previousIdentity = await getGuestStorageIdentity();
  const previousScope = `guest-${await storageIdentityHash(previousIdentity)}`;
  const nextIdentity = Crypto.randomUUID();
  await AsyncStorage.setItem(GUEST_STORAGE_ID_KEY, nextIdentity).catch(
    () => undefined,
  );
  guestStorageIdentityPromise = Promise.resolve(nextIdentity);
  await clearAccountScopedStorage(previousScope);
  return previousScope;
};

export const accountScopedStorageKey = async (
  baseKey: string,
  boundary?: AccountSessionBoundary,
) => `${baseKey}:${boundary?.scope ?? (await getCurrentAccountStorageScope())}`;

const belongsToAccountScope = (key: string, accountScope: string) =>
  key.endsWith(`:${accountScope}`) || key.includes(`:${accountScope}:`);

/**
 * Remove only data owned by one signed-in account. Device preferences such as
 * language and the device-only push invalidation tombstone survive
 * logout, while course caches, queues and privacy preferences cannot leak into
 * a later account on the same phone. A normal logout may retain only immutable
 * payment recovery intents under this same account scope; deletion removes all.
 */
export const clearAccountScopedStorage = async (
  accountScope: string,
  options: {preserveFinancialRecovery?: boolean} = {},
): Promise<string[]> => {
  const normalizedScope = String(accountScope || '').trim();
  if (!/^[a-z0-9_-]+$/i.test(normalizedScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }

  const keys = await AsyncStorage.getAllKeys();
  const financialRecoveryKeys = [
    '@rokn/coin-checkout-attempt/v2',
    '@rokn/course-purchase-attempt/v2',
    '@rokn/native-store-reconciliation/v1',
  ];
  const ownedKeys = keys.filter(key => {
    if (!belongsToAccountScope(key, normalizedScope)) return false;
    return !(
      options.preserveFinancialRecovery &&
      financialRecoveryKeys.some(prefix => key.startsWith(prefix))
    );
  });
  if (ownedKeys.length) {
    await AsyncStorage.multiRemove(ownedKeys);
  }

  return ownedKeys;
};

export const removeItem = async (key: string) => {
  try {
    if (key === AsyncKeys.USER_DATA) {
      return await deleteSecureSession();
    }
    await AsyncStorage.removeItem(key);
    return true;
  } catch (error) {
    if (__DEV__) console.warn('removeItem', errorMessage(error));
  }
  return false;
};
