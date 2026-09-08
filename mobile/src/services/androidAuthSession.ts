import * as WebBrowser from 'expo-web-browser';
import {AppState, Linking, NativeModules} from 'react-native';

export type AndroidAuthSessionResult =
  | {type: 'success'; url: string}
  | {type: 'cancel'; recoverable?: boolean};

const AUTH_SESSION_TIMEOUT_MS = 10 * 60 * 1000;
const DEEP_LINK_DELIVERY_GRACE_MS = 8000;
const RECENT_CALLBACK_OWNERSHIP_MS = 5000;

type CallbackOwner = {
  returnUrl: string;
  attempt: string | null;
};

let activeCallbackOwner: CallbackOwner | null = null;
let recentlyHandledCallback: (CallbackOwner & {until: number}) | null = null;

type NativeAuthBrowser = {
  open: (url: string) => Promise<boolean | void>;
};

const openAuthBrowser = async (url: string) => {
  const nativeBrowser = NativeModules.RoknAuthBrowser as
    | NativeAuthBrowser
    | undefined;
  if (typeof nativeBrowser?.open === 'function') {
    await nativeBrowser.open(url);
    return;
  }

  await WebBrowser.openBrowserAsync(url, {
    createTask: false,
    showInRecents: false,
    showTitle: true,
    enableDefaultShareMenuItem: false,
  });
};

const queryValue = (url: string, key: string) => {
  const query = url.split('?')[1]?.split('#')[0] || '';
  for (const part of query.split('&')) {
    const [rawKey, ...rawValue] = part.split('=');
    try {
      if (decodeURIComponent(rawKey || '') !== key) continue;
      return decodeURIComponent(rawValue.join('=') || '');
    } catch {
      return null;
    }
  }
  return null;
};

const matchesCallbackOwner = (candidate: string, owner: CallbackOwner) =>
  (candidate === owner.returnUrl ||
    candidate.startsWith(`${owner.returnUrl}?`)) &&
  (!owner.attempt || queryValue(candidate, 'attempt') === owner.attempt);

/** Prevent the durable late-callback listener from racing the live session. */
export const androidAuthSessionOwnsCallback = (url: string) => {
  if (activeCallbackOwner && matchesCallbackOwner(url, activeCallbackOwner)) {
    return true;
  }
  if (
    recentlyHandledCallback &&
    Date.now() <= recentlyHandledCallback.until &&
    matchesCallbackOwner(url, recentlyHandledCallback)
  ) {
    return true;
  }
  return false;
};

export const openAndroidAuthSession = (
  startUrl: string,
  returnUrl: string,
  expectedAttempt: string | null = null,
): Promise<AndroidAuthSessionResult> =>
  new Promise((resolve, reject) => {
    let settled = false;
    let leftApp = false;
    let cancelTimer: ReturnType<typeof setTimeout> | undefined;

    const owner = {returnUrl, attempt: expectedAttempt};
    activeCallbackOwner = owner;

    const isExpectedCallback = (url: string) =>
      matchesCallbackOwner(url, owner);

    const redirectSubscription = Linking.addEventListener('url', event => {
      if (isExpectedCallback(event.url)) {
        finish({type: 'success', url: event.url});
      }
    });
    const appStateSubscription = AppState.addEventListener('change', state => {
      if (state === 'inactive' || state === 'background') {
        leftApp = true;
        return;
      }
      if (state === 'active' && leftApp && !settled) {
        // Some Android builds resume the activity well before dispatching the
        // OAuth deep link. A short grace period produced false cancellations
        // on real devices even though the provider callback had succeeded.
        if (cancelTimer) clearTimeout(cancelTimer);
        cancelTimer = setTimeout(
          // Returning from a Custom Tab is not proof that OAuth was cancelled:
          // Android may deliver its deep link later. Release the UI while the
          // encrypted PKCE attempt remains available to the app-wide listener.
          () => finish({type: 'cancel', recoverable: true}),
          DEEP_LINK_DELIVERY_GRACE_MS,
        );
      }
    });
    const timeoutTimer = setTimeout(fail, AUTH_SESSION_TIMEOUT_MS);

    function cleanup() {
      clearTimeout(timeoutTimer);
      if (cancelTimer) clearTimeout(cancelTimer);
      redirectSubscription.remove();
      appStateSubscription.remove();
      if (activeCallbackOwner === owner) activeCallbackOwner = null;
    }

    function finish(result: AndroidAuthSessionResult) {
      if (settled) return;
      settled = true;
      if (result.type === 'success') {
        recentlyHandledCallback = {
          ...owner,
          until: Date.now() + RECENT_CALLBACK_OWNERSHIP_MS,
        };
      }
      cleanup();
      resolve(result);
    }

    function fail() {
      if (settled) return;
      settled = true;
      cleanup();
      reject(new Error('LOGIN_BROWSER_UNAVAILABLE'));
    }

    void openAuthBrowser(startUrl).catch(fail);
  });
