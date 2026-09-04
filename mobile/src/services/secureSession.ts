import AsyncStorage from '@react-native-async-storage/async-storage';
import {sha256Hex} from '../utils/sha256';
import {
  resetSecureSessionMutationForTests,
  serializeSecureSessionMutation as serializeSessionMutation,
} from './secureSessionMutation';
import {
  bindingMatches,
  deleteSecureTokens,
  PENDING_SOCIAL_AUTH_KEY,
  readSecureToken,
  resetSecureSessionStorageForTests,
  SECURE_SESSION_BINDING_KEY,
  secureDeleteItem,
  secureGetItem,
  secureSetItem,
  sessionBinding,
  writeSecureToken,
} from './secureSessionStorage';

export {
  assertSecureSessionStorageAvailable,
  secureStoreOptionsForPlatform,
} from './secureSessionStorage';
export {
  deletePendingSocialAuthAttempt,
  loadPendingSocialAuthAttempt,
  replacePendingSocialAuthAttempt,
  savePendingSocialAuthAttempt,
} from './pendingSocialAuthAttemptStore';
export type {PendingSocialAuthAttempt} from './pendingSocialAuthAttemptStore';

const USER_DATA_KEY = 'USER_DATA';

const SENSITIVE_SESSION_KEYS = new Set([
  'api_token',
  'access_token',
  'refresh_token',
  'id_token',
  'auth_token',
  'bearer_token',
  'authorization',
  'password',
  'secret',
]);

const isSensitiveSessionKey = (key: string) => {
  const lowerKey = key.toLowerCase();
  if (SENSITIVE_SESSION_KEYS.has(lowerKey)) return true;

  // Never let an unexpected credential-shaped provider field fall back to
  // plaintext AsyncStorage.
  const canonicalKey = lowerKey.replace(/[^a-z0-9]/g, '');
  return (
    canonicalKey === 'token' ||
    canonicalKey === 'jwt' ||
    canonicalKey.endsWith('token') ||
    canonicalKey.endsWith('secret') ||
    canonicalKey.includes('password') ||
    canonicalKey.endsWith('credential') ||
    canonicalKey.endsWith('credentials')
  );
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const parseJson = (value: string | null): unknown => {
  if (value === null) return null;
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
};

export type SessionProfile = {
  [key: string]: unknown;
  id?: string | number;
  user_id?: string | number;
  social_id?: string | number;
  email?: string;
  social_provider?: string;
  review?: boolean;
  name?: string;
  job_title?: string;
  portfolio_slug?: string;
  username?: string;
  avatar?: string;
  profile_image?: string;
  image?: string;
  wallet_purchased_coins?: string | number;
};

export const extractApiToken = (value: unknown): string | null => {
  if (!isRecord(value) || typeof value.api_token !== 'string') return null;
  const token = value.api_token.trim();
  return token || null;
};

export const extractUserProfile = (value: unknown): SessionProfile => {
  if (!isRecord(value) || !isRecord(value.user)) return {};
  const clean = {...value.user} as SessionProfile;
  for (const key of ['avatar', 'profile_image', 'image'] as const) {
    const uri = clean[key];
    if (
      typeof uri === 'string' &&
      /\/images\/service\.jpg(?:\?|#|$)/i.test(uri.trim())
    ) {
      delete clean[key];
    }
  }
  return clean;
};

/**
 * Process-local UI/operation ownership key. Prefer the durable account id and
 * use a one-way bearer fingerprint only for valid sessions whose provider did
 * not return an id. The raw bearer must never become a React key, log field or
 * storage suffix, and two such authenticated accounts must not collapse into
 * the old shared `authenticated` bucket.
 */
export const sessionIdentityKey = (value: unknown): string => {
  const profile = extractUserProfile(value);
  const stableAccountId = profile.id ?? profile.user_id;
  if (stableAccountId !== undefined && stableAccountId !== null) {
    const normalized = String(stableAccountId).trim();
    if (normalized) {
      return `account-${sha256Hex(`id:${normalized}`).slice(0, 32)}`;
    }
  }
  const token = extractApiToken(value);
  return token
    ? `session-${sha256Hex(`token:${token}`).slice(0, 32)}`
    : 'guest';
};

/** Remove credentials recursively before the non-secret session is persisted. */
export const sanitizeSessionForStorage = (value: unknown): unknown => {
  if (Array.isArray(value)) {
    return value.map(sanitizeSessionForStorage);
  }
  if (!isRecord(value)) return value;

  return Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => !isSensitiveSessionKey(key))
      .map(([key, childValue]) => [key, sanitizeSessionForStorage(childValue)]),
  );
};

const attachTokenInMemory = (
  storedProfile: unknown,
  apiToken: string | null,
) => {
  if (!apiToken) return storedProfile;
  if (!isRecord(storedProfile)) {
    return {api_token: apiToken};
  }
  return {...storedProfile, api_token: apiToken};
};

const sessionOwnerKey = (value: unknown): string => {
  const profile = extractUserProfile(value);
  const id = profile.id ?? profile.user_id;
  return id === undefined || id === null ? '' : String(id).trim();
};

/**
 * Login is normally preceded by logout, but deep links and shared devices can
 * replace one valid session directly. Quiesce the old account while its token
 * and profile are still current, then remove only its scoped local state.
 */
const clearPreviousAccountBeforeReplacement = async () => {
  const helpers = await import('../constants/helpers');
  const accountScope = await helpers.getCurrentAccountStorageScope();
  const [reminders, push, deviceSessions, learning, chat] = await Promise.all([
    import('./smartReminders'),
    import('./pushNotifications'),
    import('./deviceSessions'),
    import('../components/VideoPlayer/courseLearningApi'),
    import('../utils/fileCache'),
  ]);

  reminders.cancelLearningReminders();
  await reminders.setSmartRemindersEnabled(false).catch(() => undefined);
  const previousPushToken = await push
    .getCurrentPushDeviceToken()
    .catch(() => null);
  // A direct account switch is also a logout from this installation. Close
  // the old bearer while it is still the active secure session; otherwise a
  // discarded token remains listed as a live device for up to its full TTL.
  await deviceSessions
    .revokeCurrentDeviceSession(previousPushToken, {
      // This call runs inside the serialized replacement transaction. A 401
      // belongs to the superseded bearer and must not queue deletion of the
      // very session mutation which is waiting for this request to finish.
      preservePersistedSessionOnUnauthorized: true,
    })
    .catch(() => undefined);
  await push.clearCurrentPushDeviceRegistration();
  await learning.clearCurrentAccountLearningFiles(accountScope);
  await chat.clearTransientChatCache({accountBoundary: true});
  await helpers.clearAccountScopedStorage(accountScope, {
    preserveFinancialRecovery: true,
  });
};

const revokeReplacedBearerForSameAccount = async () => {
  const [push, deviceSessions] = await Promise.all([
    import('./pushNotifications'),
    import('./deviceSessions'),
  ]);
  const pushToken = await push.getCurrentPushDeviceToken().catch(() => null);
  await deviceSessions
    .revokeCurrentDeviceSession(pushToken, {
      preservePersistedSessionOnUnauthorized: true,
    })
    .catch(() => undefined);
};

let cachedSession: unknown = null;
let sessionCacheReady = false;
let sessionLoadPromise: Promise<unknown> | null = null;
let sessionCacheEpoch = 0;

/**
 * Read the in-memory bootstrap result without starting or waiting for a native
 * keychain operation. Public guest journeys use this so a locked keychain
 * cannot hold catalogue or authentication discovery requests.
 */
export const peekSecureSession = () => ({
  ready: sessionCacheReady,
  session: sessionCacheReady ? cachedSession : null,
  epoch: sessionCacheEpoch,
});

const persistSecureSession = async (session: unknown) => {
  const apiToken = extractApiToken(session);
  if (!apiToken) {
    throw new Error('SESSION_STORAGE_UNAVAILABLE_MISSING_TOKEN');
  }
  const profile = extractUserProfile(session);
  if (profile.id === undefined && profile.user_id === undefined) {
    throw new Error('SESSION_STORAGE_UNAVAILABLE_INVALID_PROFILE');
  }
  const rawPreviousSession = parseJson(
    await AsyncStorage.getItem(USER_DATA_KEY),
  );
  const previousSession =
    sessionCacheReady && cachedSession ? cachedSession : rawPreviousSession;
  const previousOwner = sessionOwnerKey(previousSession);
  const nextOwner = sessionOwnerKey(session);
  if (previousOwner && nextOwner && previousOwner !== nextOwner) {
    await clearPreviousAccountBeforeReplacement();
  }
  const sanitized = sanitizeSessionForStorage(session);
  const previousToken = await readSecureToken();
  const tokenChanged = Boolean(apiToken && apiToken !== previousToken);

  if (
    tokenChanged &&
    previousToken &&
    previousOwner &&
    previousOwner === nextOwner
  ) {
    // Re-signing into the same account replaces this installation's bearer.
    // Revoke the superseded session instead of leaving an invisible duplicate
    // in the device list until server expiry.
    await revokeReplacedBearerForSameAccount();
  }

  if (tokenChanged && apiToken) {
    await writeSecureToken(apiToken);
  }

  try {
    await AsyncStorage.setItem(USER_DATA_KEY, JSON.stringify(sanitized));
  } catch (error) {
    // Avoid pairing a newly written token with another account's old profile.
    if (tokenChanged) {
      if (previousToken) {
        await writeSecureToken(previousToken);
      } else {
        await deleteSecureTokens();
      }
    }
    throw error;
  }

  // This is the commit record for the two-store session. The token and the
  // non-secret profile cannot be written atomically across Keychain and
  // AsyncStorage; binding both after their writes lets cold start reject a
  // process-death mix of account A's profile and account B's bearer.
  await secureSetItem(
    SECURE_SESSION_BINDING_KEY,
    await sessionBinding(nextOwner, apiToken),
  );

  // The credential and matching profile are now durable.
  sessionCacheEpoch += 1;
  cachedSession = attachTokenInMemory(sanitized, apiToken);
  sessionCacheReady = true;
  sessionLoadPromise = null;
};

export const saveSecureSession = (session: unknown) =>
  serializeSessionMutation(() => persistSecureSession(session));

/** Apply a profile mutation only while the same account still owns the session. */
export const updateSecureSessionForOwner = (
  expectedOwner: string,
  update: (session: unknown) => unknown,
) =>
  serializeSessionMutation(async () => {
    const normalizedOwner = expectedOwner.trim();
    const current = await loadSecureSession();
    if (!normalizedOwner || sessionOwnerKey(current) !== normalizedOwner) {
      throw new Error('ACCOUNT_CHANGED_DURING_SESSION_UPDATE');
    }
    const next = update(current);
    if (sessionOwnerKey(next) !== normalizedOwner) {
      throw new Error('SESSION_UPDATE_OWNER_MISMATCH');
    }
    await persistSecureSession(next);
    return next;
  });

export const loadSecureSession = async () => {
  if (sessionCacheReady) {
    return cachedSession;
  }
  if (sessionLoadPromise) {
    return sessionLoadPromise;
  }

  const loadEpoch = sessionCacheEpoch;
  const load = async () => {
    const [rawProfile, apiToken, rawBinding] = await Promise.all([
      AsyncStorage.getItem(USER_DATA_KEY),
      readSecureToken(),
      secureGetItem(SECURE_SESSION_BINDING_KEY),
    ]);
    const storedProfile = parseJson(rawProfile);

    let restoredSession: unknown = null;
    if (rawProfile === null || !isRecord(storedProfile)) {
      // A secure token without its profile can only be a partially completed
      // logout. Remove it instead of resurrecting an ownerless session.
      if (apiToken) {
        await deleteSecureTokens();
      }
      if (rawProfile !== null) {
        await AsyncStorage.removeItem(USER_DATA_KEY);
      }
    } else if (!apiToken) {
      // A profile is not a session. Android backup/key rotation or an
      // interrupted logout can leave the non-secret half behind; keeping it
      // would make guest caches use the previous learner's account scope.
      await AsyncStorage.removeItem(USER_DATA_KEY);
    } else {
      restoredSession = attachTokenInMemory(storedProfile, apiToken);
      const owner = sessionOwnerKey(restoredSession);
      const bindingValid = await bindingMatches(rawBinding, owner, apiToken);
      if (!owner) {
        await Promise.all([
          deleteSecureTokens(),
          AsyncStorage.removeItem(USER_DATA_KEY),
        ]);
        restoredSession = null;
      } else if (bindingValid !== true) {
        await Promise.all([
          deleteSecureTokens(),
          AsyncStorage.removeItem(USER_DATA_KEY),
        ]);
        restoredSession = null;
      }
    }

    // A logout or account switch may finish while the keychain read is in
    // flight. Never let that older read resurrect the previous account.
    if (loadEpoch !== sessionCacheEpoch) {
      return sessionCacheReady ? cachedSession : null;
    }
    cachedSession = restoredSession;
    sessionCacheReady = true;
    sessionCacheEpoch += 1;
    return restoredSession;
  };
  let trackedLoad: Promise<unknown>;
  trackedLoad = load().finally(() => {
    if (sessionLoadPromise === trackedLoad) sessionLoadPromise = null;
  });
  sessionLoadPromise = trackedLoad;
  return trackedLoad;
};

/**
 * Let a foreground retry replace a native keychain read that never settled.
 * The epoch prevents the abandoned read from restoring an older owner if the
 * OS eventually calls it back after a logout, login or replacement attempt.
 */
export const abandonPendingSecureSessionRestore = () => {
  if (sessionCacheReady || !sessionLoadPromise) return false;
  sessionCacheEpoch += 1;
  sessionLoadPromise = null;
  return true;
};

/** Single source of truth for cold-start Redux hydration. */
export const restoreSecureAuthState = async () => {
  const session = await loadSecureSession();
  return {
    session,
    isAuthenticated: Boolean(extractApiToken(session)),
  };
};

const performDeleteSecureSession = async () => {
  sessionCacheEpoch += 1;
  cachedSession = null;
  sessionCacheReady = true;
  sessionLoadPromise = null;
  const results = await Promise.allSettled([
    deleteSecureTokens(),
    secureDeleteItem(PENDING_SOCIAL_AUTH_KEY),
    AsyncStorage.removeItem(USER_DATA_KEY),
  ]);
  return results.every(result => result.status === 'fulfilled');
};

export const deleteSecureSession = () =>
  serializeSessionMutation(performDeleteSecureSession);

/**
 * Expire only the bearer which actually received a terminal 401. Social auth
 * may commit a replacement while the old request is still unwinding; keeping
 * the comparison inside the session mutation queue prevents that old response
 * from deleting the newly authenticated owner.
 */
export const deleteSecureSessionIfToken = (expectedToken: string) =>
  serializeSessionMutation(async () => {
    const normalized = expectedToken.trim();
    const current = await loadSecureSession();
    if (!normalized || extractApiToken(current) !== normalized) return false;
    await performDeleteSecureSession();
    return true;
  });

const performClearSecureSessionStorage = async () => {
  sessionCacheEpoch += 1;
  cachedSession = null;
  sessionCacheReady = true;
  sessionLoadPromise = null;
  await Promise.all([
    deleteSecureTokens(),
    secureDeleteItem(PENDING_SOCIAL_AUTH_KEY),
  ]);
  await AsyncStorage.clear();
};

export const clearSecureSessionStorage = () =>
  serializeSessionMutation(performClearSecureSessionStorage);

/** Reset module-level session state between isolated tests. */
export const resetSecureSessionForTests = () => {
  cachedSession = null;
  sessionCacheReady = false;
  sessionLoadPromise = null;
  sessionCacheEpoch = 0;
  resetSecureSessionStorageForTests();
  resetSecureSessionMutationForTests();
};
