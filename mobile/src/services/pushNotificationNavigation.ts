import * as Notifications from 'expo-notifications';
import {parseRoknDestination, safeRoknRouteId} from '../navigation/deepLinks';
import {
  navigate,
  openRoknDestination,
} from '../navigation/RootNavigationHelper';
import {
  AsyncKeys,
  extractApiToken,
  getCurrentAccountStorageScope,
  getItem,
  removeItem,
  saveItem,
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {peekSecureSession} from './secureSession';
import {pushStorageKey} from './pushDeviceState';
import {normalizeNotificationKind} from './notificationCampaigns';
import {getNotification, markNotificationRead} from './api/notifications';

const PENDING_NOTIFICATION_OPEN_KEY = '@rokn/push-open-pending/v1';

type SessionAvailability =
  | {state: 'authenticated'; token: string}
  | {state: 'guest'}
  | {state: 'pending'};

/**
 * A locked or temporarily unavailable keychain is not an authenticated
 * logout. Cold-start notification responses must stay owned by the native
 * response until bootstrap can prove either the account or a real guest
 * session; otherwise a slow restore permanently loses the user's tap.
 */
const currentSessionAvailability = async (): Promise<SessionAvailability> => {
  const before = peekSecureSession();
  const cachedToken = extractApiToken(before.session);
  if (cachedToken) return {state: 'authenticated', token: cachedToken};
  if (before.ready) return {state: 'guest'};

  const loadedToken = extractApiToken(await getItem(AsyncKeys.USER_DATA));
  if (loadedToken) return {state: 'authenticated', token: loadedToken};
  return peekSecureSession().ready ? {state: 'guest'} : {state: 'pending'};
};

const navigateToNotificationData = (data: Record<string, unknown>) => {
  const explicitLink = [data.link, data.deep_link, data.action_url].find(
    value => typeof value === 'string' && value.trim(),
  );
  const courseId = safeRoknRouteId(data.course_id);
  const kind = normalizeNotificationKind(data.notification_type || data.type);
  const explicitDestination =
    typeof explicitLink === 'string'
      ? parseRoknDestination(explicitLink)
      : null;
  const fallbackLink = courseId
    ? `rokn://course/${encodeURIComponent(courseId)}${
        kind === 'continue_course' || kind === 'learning_reminder'
          ? '/watch'
          : ''
      }`
    : kind === 'coin_offer' || kind === 'coin_reward'
    ? 'rokn://wallet'
    : 'rokn://home';
  const destination = explicitDestination || parseRoknDestination(fallbackLink);
  if (destination) {
    return openRoknDestination(destination);
  }
  return false;
};

const notificationIdFromResponse = (
  response: Notifications.NotificationResponse,
) => {
  const value = response.notification.request.content.data?.notification_id;
  const id = String(value || '').trim();
  return /^\d+$/.test(id) ? id : null;
};

const isLocalReminderResponse = (
  response: Notifications.NotificationResponse,
) => {
  const value = response.notification.request.content.data?.rokn_reminder_id;
  return /^rokn-local-\d+$/.test(String(value || '').trim());
};

const pendingNotificationStorageKey = (
  ownerBoundary?: AccountSessionBoundary,
) => pushStorageKey(PENDING_NOTIFICATION_OPEN_KEY, ownerBoundary);

const openNotificationByIdFlights = new Map<string, Promise<boolean>>();
const responseOpenFlights = new Map<string, Promise<boolean>>();
const recentlyOpenedResponses = new Map<
  string,
  {at: number; intent: NotificationNavigationIntent}
>();
const RESPONSE_DEDUPE_MS = 8_000;
let pushNavigationGeneration = 0;
type NotificationNavigationIntent = {
  key: string;
  generation: number;
  sequence: number;
};
let notificationIntentSequence = 0;
let latestNotificationIntent: NotificationNavigationIntent | null = null;
let pendingMarkerTail: Promise<unknown> = Promise.resolve();

const ownsNotificationIntent = (intent: NotificationNavigationIntent) =>
  latestNotificationIntent === intent &&
  intent.generation === pushNavigationGeneration;

// One queue owns every marker mutation, including account teardown. A native
// write already in progress cannot be cancelled by invalidating its intent.
const enqueuePendingMarker = (operation: () => Promise<unknown>) => {
  const write = pendingMarkerTail.then(operation);
  pendingMarkerTail = write.catch(() => undefined);
  return write;
};

const updatePendingMarker = (
  intent: NotificationNavigationIntent,
  operation: () => Promise<unknown>,
) =>
  enqueuePendingMarker(async () =>
    ownsNotificationIntent(intent) ? operation() : undefined,
  );

const notificationResponseKey = (
  response: Notifications.NotificationResponse,
) => {
  const identifier = String(
    response.notification.request.identifier || '',
  ).trim();
  const action = String(response.actionIdentifier || 'default').trim();
  if (identifier) return `${identifier}:${action}`;
  // Defensive compatibility for vendor bridges that omit the native request
  // identifier. Do not collapse every such tap into one global "undefined"
  // key or a second, different notification will be ignored for eight seconds.
  let payload = '';
  try {
    payload = JSON.stringify(response.notification.request.content.data || {});
  } catch {
    payload = String(
      response.notification.request.content.title || 'notification',
    );
  }
  return `payload:${payload.slice(0, 1000)}:${action}`;
};

const claimNotificationIntent = (key: string) => {
  if (
    latestNotificationIntent?.key !== key ||
    latestNotificationIntent.generation !== pushNavigationGeneration
  ) {
    latestNotificationIntent = {
      key,
      generation: pushNavigationGeneration,
      sequence: ++notificationIntentSequence,
    };
  }
  return latestNotificationIntent;
};

const intentForResponse = (response: Notifications.NotificationResponse) =>
  claimNotificationIntent(
    notificationIdFromResponse(response)
      ? `notification:${notificationIdFromResponse(response)}`
      : notificationResponseKey(response),
  );

const openNotificationById = async (
  notificationId: string,
  intent: NotificationNavigationIntent,
) => {
  const accountScope = await getCurrentAccountStorageScope();
  if (!ownsNotificationIntent(intent)) return false;
  const generation = pushNavigationGeneration;
  const flightKey = `${accountScope}:${notificationId}:${intent.sequence}`;
  const existing = openNotificationByIdFlights.get(flightKey);
  if (existing) return existing;
  const flight = getNotification(notificationId)
    .then(async notification => {
      if (
        generation !== pushNavigationGeneration ||
        (await getCurrentAccountStorageScope()) !== accountScope ||
        !ownsNotificationIntent(intent)
      ) {
        return false;
      }
      // The fetched inbox row is the immutable delivery snapshot. Do not
      // reinterpret a missing/invalid admin destination from its broad kind;
      // that can turn a certificate or support button into Home. The inbox is
      // the only honest fallback when this exact delivery has no route.
      const destination = parseRoknDestination(notification.link);
      const opened = destination
        ? openRoknDestination(destination)
        : navigate('Notifications');
      if (opened) {
        void markNotificationRead(notificationId).catch(() => undefined);
      }
      return opened;
    })
    .catch(async error => {
      if (
        generation !== pushNavigationGeneration ||
        (await getCurrentAccountStorageScope()) !== accountScope ||
        !ownsNotificationIntent(intent)
      ) {
        return false;
      }
      const status = Number(
        (error as {status?: unknown})?.status ||
          (error as {response?: {status?: unknown}})?.response?.status ||
          0,
      );
      // The inbox may have been pruned after the OS kept an old tap. Never
      // route from its stale push payload; open the current inbox instead.
      if (status === 404) return navigate('Notifications');
      // A notification tap must still lead somewhere useful while the network
      // is slow or unavailable. The inbox owns its account-scoped stale cache
      // and retry state, so it is the safe fallback for every fetch failure.
      return navigate('Notifications');
    })
    .finally(() => {
      if (openNotificationByIdFlights.get(flightKey) === flight) {
        openNotificationByIdFlights.delete(flightKey);
      }
    });
  openNotificationByIdFlights.set(flightKey, flight);
  return flight;
};

export const openNotificationLink = async (
  response: Notifications.NotificationResponse,
  intent = intentForResponse(response),
) => {
  const accountScope = await getCurrentAccountStorageScope();
  if (!ownsNotificationIntent(intent)) return false;
  const generation = pushNavigationGeneration;
  const responseKey = `${accountScope}:${notificationResponseKey(response)}`;
  const recentOpen = recentlyOpenedResponses.get(responseKey);
  const elapsed = Date.now() - (recentOpen?.at || 0);
  if (
    recentOpen?.intent === intent &&
    elapsed >= 0 &&
    elapsed < RESPONSE_DEDUPE_MS
  )
    return true;
  const flightKey = `${responseKey}:${intent.sequence}`;
  const existing = responseOpenFlights.get(flightKey);
  if (existing) return existing;

  const flight = (async () => {
    if (
      generation !== pushNavigationGeneration ||
      (await getCurrentAccountStorageScope()) !== accountScope ||
      !ownsNotificationIntent(intent)
    ) {
      return false;
    }
    const notificationId = notificationIdFromResponse(response);
    const localReminder = isLocalReminderResponse(response);
    const requiresInboxOwnership = Boolean(notificationId) || !localReminder;
    const sessionAvailability = requiresInboxOwnership
      ? await currentSessionAvailability()
      : null;
    if (!ownsNotificationIntent(intent)) return false;
    if (requiresInboxOwnership && sessionAvailability?.state === 'pending') {
      return false;
    }
    if (requiresInboxOwnership && sessionAvailability?.state === 'guest') {
      // A tap can outlive the account that received it. Its durable inbox row
      // must never open under a guest or a later account from the old payload.
      pendingNotificationResponse = null;
      const key = await pendingNotificationStorageKey();
      await updatePendingMarker(intent, () => removeItem(key));
      if (ownsNotificationIntent(intent)) {
        await Notifications.clearLastNotificationResponseAsync();
      }
      return false;
    }
    const opened = notificationId
      ? await openNotificationById(notificationId, intent)
      : localReminder
      ? navigateToNotificationData(
          (response.notification.request.content.data || {}) as Record<
            string,
            unknown
          >,
        )
      : navigate('Notifications');
    if (opened) {
      const now = Date.now();
      recentlyOpenedResponses.set(responseKey, {at: now, intent});
      recentlyOpenedResponses.forEach((openedAt, key) => {
        if (now - openedAt.at >= RESPONSE_DEDUPE_MS) {
          recentlyOpenedResponses.delete(key);
        }
      });
    }
    return opened;
  })().finally(() => {
    if (responseOpenFlights.get(flightKey) === flight) {
      responseOpenFlights.delete(flightKey);
    }
  });
  responseOpenFlights.set(flightKey, flight);
  return flight;
};

let pendingNotificationResponse:
  | ((
      | {
          response: Notifications.NotificationResponse;
          clearNativeResponse: boolean;
        }
      | {
          notificationId: string;
          storedKey: string;
        }
    ) & {
      intent: NotificationNavigationIntent;
    })
  | null = null;
let notificationNavigationReady = false;

export const setNotificationNavigationReady = (ready: boolean) => {
  notificationNavigationReady = ready;
};

const deliverNotificationResponse = async (
  response: Notifications.NotificationResponse,
  clearNativeResponse: boolean,
) => {
  const intent = intentForResponse(response);
  // Capture the latest tap before keychain/storage waits. A stale completion
  // must never replace this pending response during bootstrap.
  pendingNotificationResponse = {response, clearNativeResponse, intent};
  const notificationId = notificationIdFromResponse(response);
  const localReminder = isLocalReminderResponse(response);
  const requiresInboxOwnership = Boolean(notificationId) || !localReminder;
  const sessionAvailability = requiresInboxOwnership
    ? await currentSessionAvailability()
    : null;
  if (!ownsNotificationIntent(intent)) return false;
  if (requiresInboxOwnership && sessionAvailability?.state === 'pending') {
    return false;
  }
  if (requiresInboxOwnership && sessionAvailability?.state === 'guest') {
    pendingNotificationResponse = null;
    const key = await pendingNotificationStorageKey();
    await updatePendingMarker(intent, () => removeItem(key));
    if (ownsNotificationIntent(intent)) {
      await Notifications.clearLastNotificationResponseAsync();
    }
    return true;
  }
  if (notificationId) {
    const key = await pendingNotificationStorageKey();
    await updatePendingMarker(intent, () => saveItem(key, notificationId));
  }
  if (!ownsNotificationIntent(intent)) return false;
  if (!notificationNavigationReady) {
    return false;
  }
  if (!(await openNotificationLink(response, intent))) {
    return false;
  }
  if (!ownsNotificationIntent(intent)) return false;
  if (notificationId) {
    const key = await pendingNotificationStorageKey();
    await updatePendingMarker(intent, () => removeItem(key));
  }
  if (!ownsNotificationIntent(intent)) return false;
  pendingNotificationResponse = null;
  if (clearNativeResponse) {
    await Notifications.clearLastNotificationResponseAsync();
  }
  return true;
};

/** Complete a notification tap only after NavigationContainer is ready. */
export const flushPendingNotificationNavigation = async () => {
  if (!notificationNavigationReady) return false;
  let pending = pendingNotificationResponse;
  if (pending && 'response' in pending) {
    if (!ownsNotificationIntent(pending.intent)) return false;
    return deliverNotificationResponse(
      pending.response,
      pending.clearNativeResponse,
    );
  }
  if (!pending) {
    // A persisted marker must not supersede a tap received by this process.
    if (latestNotificationIntent) return false;
    const generation = pushNavigationGeneration;
    const accountScope = await getCurrentAccountStorageScope();
    const storedKey = await pendingNotificationStorageKey();
    const storedId = await getItem<string>(storedKey);
    if (
      (await getCurrentAccountStorageScope()) !== accountScope ||
      generation !== pushNavigationGeneration ||
      latestNotificationIntent ||
      pendingNotificationResponse
    )
      return false;
    if (!storedId) return false;
    const intent = claimNotificationIntent(`notification:${storedId}`);
    if (!/^\d+$/.test(storedId)) {
      await updatePendingMarker(intent, () => removeItem(storedKey));
      return false;
    }
    // Preserve this exact identity for the next foreground/navigation retry.
    pending = {notificationId: storedId, storedKey, intent};
    pendingNotificationResponse = pending;
  }
  if (!ownsNotificationIntent(pending.intent)) return false;
  if (!(await openNotificationById(pending.notificationId, pending.intent)))
    return false;
  await updatePendingMarker(pending.intent, () =>
    removeItem(pending.storedKey),
  );
  if (!ownsNotificationIntent(pending.intent)) return false;
  pendingNotificationResponse = null;
  await Notifications.clearLastNotificationResponseAsync();
  return true;
};

export const subscribeToPushResponses = () => {
  const subscription = Notifications.addNotificationResponseReceivedListener(
    response => {
      void deliverNotificationResponse(response, false);
    },
  );

  const initialIntent = latestNotificationIntent;
  const initialGeneration = pushNavigationGeneration;
  void Notifications.getLastNotificationResponseAsync()
    .then(async response => {
      if (
        !response ||
        latestNotificationIntent !== initialIntent ||
        pushNavigationGeneration !== initialGeneration
      )
        return;
      await deliverNotificationResponse(response, true);
    })
    .catch(() => undefined);
  void flushPendingNotificationNavigation().catch(() => undefined);

  return () => subscription.remove();
};

/** Invalidate every old-account tap before awaiting account storage or native UI. */
export const clearNotificationNavigation = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  if (ownerBoundary) assertAccountSessionBoundary(ownerBoundary);
  pushNavigationGeneration += 1;
  latestNotificationIntent = null;
  pendingNotificationResponse = null;
  openNotificationByIdFlights.clear();
  responseOpenFlights.clear();
  recentlyOpenedResponses.clear();

  const pendingKey = await pendingNotificationStorageKey(ownerBoundary);
  await Promise.allSettled([
    enqueuePendingMarker(() => removeItem(pendingKey)),
    Notifications.clearLastNotificationResponseAsync(),
    // Remove the previous learner's private title/body from the shared OS tray.
    Notifications.dismissAllNotificationsAsync(),
    Notifications.setBadgeCountAsync(0),
  ]);
};
