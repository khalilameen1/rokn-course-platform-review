import {Platform} from 'react-native';
import * as Notifications from 'expo-notifications';
import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {
  AsyncKeys,
  extractApiToken,
  getCurrentAccountStorageScope,
  getItem,
  saveItem,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {
  cancelLearningReminders,
  getSmartRemindersEnabled,
} from './smartReminders';
import {
  getStoredPushDeviceToken,
  invalidateLocalPushDeviceRegistration,
  PUSH_TOKEN_KEY,
  pushStorageKey,
  retryPendingNativePushTokenInvalidation,
} from './pushDeviceState';
import {getInstallationId} from './installationIdentity';
import {
  getBackendPushToken,
  subscribeToBackendPushTokenRefresh,
} from './nativePushTokens';
import {prepareNotificationChannels} from './notificationPresentation';

// Firebase can rotate twice while an earlier registration request is still in
// flight. Keep registration mutations ordered and invalidate their ownership
// synchronously on opt-out/logout so a slow older response cannot become the
// locally remembered token after a newer one.
let pushRegistrationGeneration = 0;
let pushRegistrationTail: Promise<unknown> = Promise.resolve();

const serializePushRegistration = <T>(operation: () => Promise<T>) => {
  const result = pushRegistrationTail.then(operation, operation);
  pushRegistrationTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

// Resolve the account at admission, not after another device mutation drains.
// Store a settled capture so a retired session cannot reject unobserved while
// it is waiting behind native token acquisition or invalidation.
const capturePushOwner = (ownerBoundary?: AccountSessionBoundary) =>
  (ownerBoundary
    ? Promise.resolve(ownerBoundary)
    : captureAccountSessionBoundary()
  ).then(
    boundary => ({boundary}),
    (error: unknown) => ({error}),
  );

const resolvePushOwner = async (capture: ReturnType<typeof capturePushOwner>) => {
  const captured = await capture;
  if ('error' in captured) throw captured.error;
  assertAccountSessionBoundary(captured.boundary);
  return captured.boundary;
};

const currentSessionToken = async () =>
  extractApiToken(await getItem(AsyncKeys.USER_DATA));

const sessionStillCurrent = async (
  token: string,
  accountScope: string,
  boundary: AccountSessionBoundary,
) => {
  try {
    const currentToken = await currentSessionToken();
    assertAccountSessionBoundary(boundary);
    const currentScope = await getCurrentAccountStorageScope();
    assertAccountSessionBoundary(boundary);
    return currentToken === token && currentScope === accountScope;
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    )
      return false;
    throw error;
  }
};

const removeTokenFromCapturedSession = async (
  token: string,
  sessionToken: string,
) => {
  await publicRequest.delete('user/device-token', {
    data: {device_token: token},
    headers: {Authorization: `Bearer ${sessionToken}`},
    skipPersistedSessionInvalidation: true,
  } as RoknRequestConfig);
};

const permissionGranted = async (
  requestPermission: boolean,
  boundary: AccountSessionBoundary,
  generation: number,
) => {
  type PermissionSnapshot = {
    granted?: boolean;
    status?: string;
    canAskAgain?: boolean;
  };
  const current =
    (await Notifications.getPermissionsAsync()) as PermissionSnapshot;
  assertAccountSessionBoundary(boundary);
  if (generation !== pushRegistrationGeneration) return false;
  if (current.granted || current.status === 'granted') return true;
  if (!requestPermission || !current.canAskAgain) return false;
  const requested =
    (await Notifications.requestPermissionsAsync()) as PermissionSnapshot;
  assertAccountSessionBoundary(boundary);
  return requested.granted || requested.status === 'granted';
};

const registerTokenForCurrentAccountNow = async (
  token: string,
  generation: number,
  boundary: AccountSessionBoundary,
) => {
  assertAccountSessionBoundary(boundary);
  if (generation !== pushRegistrationGeneration) return false;
  const sessionToken = await currentSessionToken();
  assertAccountSessionBoundary(boundary);
  if (!token || !sessionToken) return false;
  if (!(await getSmartRemindersEnabled(boundary))) return false;

  const accountScope = boundary.scope;
  const tokenKey = await pushStorageKey(PUSH_TOKEN_KEY, boundary);
  const previousToken = await getItem<string>(tokenKey);
  const installationId = await getInstallationId();
  assertAccountSessionBoundary(boundary);
  if (
    generation !== pushRegistrationGeneration ||
    !(await sessionStillCurrent(sessionToken, accountScope, boundary))
  ) {
    return false;
  }
  try {
    await publicRequest.post('user/device-token', {
      device_token: token,
      device_type: Platform.OS,
      device_os: Platform.OS,
      ...(installationId ? {device_id: installationId} : {}),
    });
    assertAccountSessionBoundary(boundary);
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      await removeTokenFromCapturedSession(token, sessionToken).catch(
        () => undefined,
      );
    }
    throw error;
  }
  if (
    generation !== pushRegistrationGeneration ||
    !(await sessionStillCurrent(sessionToken, accountScope, boundary))
  ) {
    await removeTokenFromCapturedSession(token, sessionToken).catch(
      () => undefined,
    );
    return false;
  }
  await saveItem(tokenKey, token);
  if (
    generation !== pushRegistrationGeneration ||
    !(await sessionStillCurrent(sessionToken, accountScope, boundary))
  ) {
    await removeTokenFromCapturedSession(token, sessionToken).catch(
      () => undefined,
    );
    return false;
  }
  // The backend now owns authenticated learning-reminder cadence. Clear any
  // guest timer left on this installation before login so it cannot double it.
  cancelLearningReminders();
  if (previousToken && previousToken !== token) {
    await removeTokenFromCapturedSession(previousToken, sessionToken).catch(
      () => undefined,
    );
  }

  return true;
};

const registerTokenForCurrentAccount = (
  token: string,
  generation = pushRegistrationGeneration,
) => {
  const capture = capturePushOwner();
  return serializePushRegistration(async () =>
    registerTokenForCurrentAccountNow(
      token,
      generation,
      await resolvePushOwner(capture),
    ),
  );
};

/**
 * Register only after both account authentication and the learner's explicit
 * opt-in. Calling with requestPermission=false is safe during bootstrap: it
 * never opens an OS prompt.
 */
export const registerPushDeviceIfEligible = ({
  requestPermission = false,
  ownerBoundary,
}: {
  requestPermission?: boolean;
  ownerBoundary?: AccountSessionBoundary;
} = {}) => {
  const capture = capturePushOwner(ownerBoundary);
  const generation = pushRegistrationGeneration;
  return serializePushRegistration(async () => {
    const boundary = await resolvePushOwner(capture);
    if (generation !== pushRegistrationGeneration) return false;
    // Token acquisition belongs inside the mutation queue. If a learner turns
    // notifications off and immediately on, the new registration must mint a
    // token after native invalidation rather than re-registering the one that
    // the preceding opt-out just deleted.
    if (!(await retryPendingNativePushTokenInvalidation())) return false;
    assertAccountSessionBoundary(boundary);
    if (!(await currentSessionToken())) return false;
    assertAccountSessionBoundary(boundary);
    if (!(await getSmartRemindersEnabled(boundary))) return false;
    if (!(await permissionGranted(requestPermission, boundary, generation)))
      return false;
    assertAccountSessionBoundary(boundary);
    if (generation !== pushRegistrationGeneration) return false;

    await prepareNotificationChannels();
    assertAccountSessionBoundary(boundary);
    if (generation !== pushRegistrationGeneration) return false;
    const token = await getBackendPushToken();
    assertAccountSessionBoundary(boundary);
    if (!token) return false;

    return registerTokenForCurrentAccountNow(token, generation, boundary);
  });
};

export const unregisterPushDevice = (ownerBoundary?: AccountSessionBoundary) => {
  // A stale caller must not invalidate a newer account's active registration.
  if (ownerBoundary) {
    try {
      assertAccountSessionBoundary(ownerBoundary);
    } catch (error) {
      return Promise.reject(error);
    }
  }
  const capture = capturePushOwner(ownerBoundary);
  pushRegistrationGeneration += 1;
  const generation = pushRegistrationGeneration;
  return serializePushRegistration(async () => {
    const boundary = await resolvePushOwner(capture);
    if (generation !== pushRegistrationGeneration) return false;
    const token = await getStoredPushDeviceToken(boundary);
    if (generation !== pushRegistrationGeneration) return false;
    let removedFromServer = !token;
    const sessionToken = await currentSessionToken();
    assertAccountSessionBoundary(boundary);
    if (token && sessionToken) {
      removedFromServer = await publicRequest
        .delete('user/device-token', {data: {device_token: token}})
        .then(() => true)
        .catch(() => false);
    }
    assertAccountSessionBoundary(boundary);
    if (generation !== pushRegistrationGeneration) return false;
    await invalidateLocalPushDeviceRegistration(boundary);
    assertAccountSessionBoundary(boundary);
    return removedFromServer;
  });
};

/**
 * Retire registration ownership synchronously, then wait out any in-flight
 * registration before deleting the installation token and account-scoped state.
 * This is account teardown, not an opt-out or a navigation reset.
 */
export const clearPushDeviceRegistration = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  if (ownerBoundary) assertAccountSessionBoundary(ownerBoundary);
  pushRegistrationGeneration += 1;
  return serializePushRegistration(() =>
    invalidateLocalPushDeviceRegistration(ownerBoundary),
  );
};

/** Reconcile token rotation or a previously interrupted unregister. Never prompts. */
export const reconcilePushRegistration = async () => {
  const boundary = await captureAccountSessionBoundary();
  // Runs during bootstrap and foreground transitions even for guests.
  const hasSession = Boolean(await currentSessionToken());
  assertAccountSessionBoundary(boundary);
  if (hasSession) {
    // Authentication transfers reminder ownership to the backend even before
    // a token refresh succeeds. Retire a guest timer here as well as after
    // registration, otherwise an offline first login can fire the old local
    // reminder beside the durable inbox campaign later.
    cancelLearningReminders();
  }
  if (!(await retryPendingNativePushTokenInvalidation())) return false;
  assertAccountSessionBoundary(boundary);
  if (!hasSession) return false;
  const optedIn = await getSmartRemindersEnabled(boundary);
  if (optedIn) {
    return registerPushDeviceIfEligible({
      requestPermission: false,
      ownerBoundary: boundary,
    }).catch(() => false);
  }
  return unregisterPushDevice(boundary).catch(() => false);
};

export const subscribeToPushTokenRefresh = () =>
  subscribeToBackendPushTokenRefresh(token => {
    void registerTokenForCurrentAccount(token).catch(() => undefined);
  });
