import {Platform} from 'react-native';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  getItem,
  removeItem,
  saveItem,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {deleteBackendPushToken} from './nativePushTokens';

export const PUSH_TOKEN_KEY = '@rokn/push-device-token/v1';
export const PUSH_TOKEN_INVALIDATION_PENDING_KEY =
  '@rokn/push-token-invalidation-pending/v1';

export const pushStorageKey = (
  baseKey: string,
  ownerBoundary?: AccountSessionBoundary,
) => accountScopedStorageKey(baseKey, ownerBoundary);

export const getStoredPushDeviceToken = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  if (ownerBoundary) assertAccountSessionBoundary(ownerBoundary);
  const value = await getItem<string>(
    await pushStorageKey(PUSH_TOKEN_KEY, ownerBoundary),
  );
  if (ownerBoundary) assertAccountSessionBoundary(ownerBoundary);
  return value;
};

/** Retry a device-only tombstone. It contains no token, bearer or account id. */
export const retryPendingNativePushTokenInvalidation = async () => {
  if (!['android', 'ios'].includes(Platform.OS)) {
    await removeItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
    return true;
  }
  const pending = await getItem<boolean>(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
  if (!pending) return true;
  const deleted = await deleteBackendPushToken();
  if (deleted) {
    await removeItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
  }
  return deleted;
};

/**
 * Forget the account/token binding even if the API is offline. The native
 * Firebase token is deleted first, so a stale server record can no longer
 * deliver private notifications. No bearer or account data is retained.
 */
export const invalidateLocalPushDeviceRegistration = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  if (ownerBoundary) assertAccountSessionBoundary(ownerBoundary);
  // Resolve account-scoped keys before a concurrent logout removes the session.
  const tokenKey = await pushStorageKey(PUSH_TOKEN_KEY, ownerBoundary);

  const nativeTokenDeleted = await deleteBackendPushToken();
  if (nativeTokenDeleted) {
    await removeItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
  } else {
    // Device-scoped tombstone only. Never retain a bearer, FCM token or PII.
    const durable = await saveItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY, true);
    if (!durable) {
      throw new Error('PUSH_INVALIDATION_NOT_DURABLE');
    }
  }

  await removeItem(tokenKey);
  return nativeTokenDeleted;
};
