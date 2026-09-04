import axios, {type AxiosInstance, type AxiosResponse} from 'axios';
import {extractApiToken, rotateGuestStorageScope} from './helpers';
import {
  getLoginReturnToSnapshot,
  navigate,
} from '../navigation/RootNavigationHelper';
import {savePendingLoginReturnTo} from '../navigation/authReturn';
// import {showMessage} from 'react-native-flash-message';
import {store} from '../store/store';
import {LogOut} from '../store/reducers/auth';
import {
  cancelLearningReminders,
  setSmartRemindersEnabled,
} from '../services/smartReminders';
import {invalidateLocalPushDeviceRegistration} from '../services/pushDeviceState';
import {roknApiUrl} from './apiBaseUrl';
import {observeServerTime} from '../utils/serverClock';
import {
  deleteSecureSessionIfToken,
  peekSecureSession,
} from '../services/secureSession';
import {retryableReadTransportFailure} from '../services/networkExperience';
import {
  assertResponseStillBelongsToSession,
  captureSessionAtApiCall,
  type RoknRequestConfig,
} from './apiSessionBoundary';
import {responseConfig, responseError} from './apiRequestPolicy';
// Expo inlines EXPO_PUBLIC_* values at build time; each release channel uses
// its configured Rokn host and has no hidden fallback origin.
export const mainUrl = roknApiUrl;
export const headers = {
  Accept: 'application/json',
  'Cache-Control': 'no-cache',
  Pragma: 'no-cache',
  Expires: '0',
};
export {getExceptionPayload, InternalError} from './apiErrors';
export type {APIError} from './apiErrors';

export type {RoknRequestConfig} from './apiSessionBoundary';
export {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  responseConfig,
  responseError,
} from './apiRequestPolicy';

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

export const onFulfilledRequest = async (response: AxiosResponse) => {
  const body = isRecord(response.data) ? response.data : undefined;
  observeServerTime(body?.server_time ?? response.headers?.date);
  await assertResponseStillBelongsToSession(
    isRecord(response.config) ? response.config : undefined,
  );
  return response;
};
let handledExpiredToken: string | null = null;

// Laravel Cloud may return a short run of 502/503/504 responses while a
// hibernated origin is waking. Public catalogue and login discovery are the
// first reads a learner makes, so surfacing that infrastructure transition as
// two unrelated product errors makes a healthy deployment look broken. Reads
// are safe to replay; writes keep their endpoint-owned idempotency rules.
// One foreground read must fail in human time. A long ladder here multiplies
// the wait across every GET (including screens which have no cached answer)
// and can leave a healthy UI spinning for more than a minute after the origin
// has already failed. Three short attempts cover a radio hand-off or gateway
// wake; the screen-owned background refresh handles longer outages.
export const READ_RECOVERY_DELAYS_MS = [300, 700, 1_500] as const;
const cancelledReadError = (config?: RoknRequestConfig) =>
  Object.assign(new Error('canceled'), {
    code: 'ERR_CANCELED',
    config,
  });

const waitForReadRetry = (
  delayMs: number,
  config?: RoknRequestConfig,
): Promise<void> =>
  new Promise((resolve, reject) => {
    const signal = config?.signal;
    if (signal?.aborted) {
      reject(cancelledReadError(config));
      return;
    }
    const onAbort = () => {
      clearTimeout(timer);
      if (typeof signal?.removeEventListener === 'function') {
        signal.removeEventListener('abort', onAbort);
      }
      reject(cancelledReadError(config));
    };
    const timer = setTimeout(() => {
      if (typeof signal?.removeEventListener === 'function') {
        signal.removeEventListener('abort', onAbort);
      }
      resolve();
    }, delayMs);
    if (typeof signal?.addEventListener === 'function') {
      signal.addEventListener('abort', onAbort, {once: true});
    }
  });

const bearerTokenUsedByRequest = (
  config?: Record<string, unknown>,
): string | null => {
  const rawHeaders = config?.headers;
  if (!rawHeaders || typeof rawHeaders !== 'object') return null;
  const headerRecord = rawHeaders as Record<string, unknown>;
  let authorization: unknown;
  if (typeof headerRecord.get === 'function') {
    authorization = (
      headerRecord.get as (this: unknown, name: string) => unknown
    ).call(rawHeaders, 'Authorization');
  } else {
    authorization = headerRecord.Authorization ?? headerRecord.authorization;
  }
  const matched = String(authorization || '').match(/^Bearer\s+(.+)$/i);
  return matched?.[1]?.trim() || null;
};

export const onRejectedResponse = async (error: unknown): Promise<never> => {
  const errorRecord = isRecord(error) ? error : {};
  const response = isRecord(errorRecord.response)
    ? errorRecord.response
    : undefined;
  const responseBody = isRecord(response?.data) ? response.data : undefined;
  const responseHeaders = isRecord(response?.headers)
    ? response.headers
    : undefined;
  observeServerTime(responseBody?.server_time ?? responseHeaders?.date);
  const config = isRecord(errorRecord.config) ? errorRecord.config : undefined;
  await assertResponseStillBelongsToSession(config);
  const method =
    typeof config?.method === 'string' ? config.method.toLowerCase() : '';
  const rawRetryCount = Number(config?.roknNetworkRetryCount || 0);
  const retryCount =
    Number.isSafeInteger(rawRetryCount) && rawRetryCount >= 0
      ? rawRetryCount
      : 0;
  const retryDeadlineAt = Number(config?.roknNetworkRetryDeadlineAt || 0);
  const responseStatus = Number(response?.status || 0);
  const safeTransientReadFailure = retryableReadTransportFailure(error);

  // Cover both a socket lost during Wi-Fi/mobile-data hand-off and the brief
  // gateway responses returned while the origin wakes. Keep the retry budget
  // finite and never replay a mutation whose server result may be unknown.
  if (
    safeTransientReadFailure &&
    Boolean(config) &&
    retryCount < READ_RECOVERY_DELAYS_MS.length &&
    (method === 'get' || method === 'head')
  ) {
    const retryDelay = READ_RECOVERY_DELAYS_MS[retryCount];
    const remainingRetryBudget = retryDeadlineAt
      ? retryDeadlineAt - Date.now() - retryDelay
      : Number.POSITIVE_INFINITY;
    // A timeout applies to each Axios attempt, not to the logical read. The
    // request policy supplies one journey-wide deadline so a weak connection cannot
    // multiply 15 seconds by every origin-wake retry and strand first launch.
    if (remainingRetryBudget <= 0) {
      return Promise.reject(response ?? error);
    }
    const configuredTimeout = Number(config?.timeout || 0);
    const retryConfig = {
      ...(config as RoknRequestConfig),
      roknNetworkRetryCount: retryCount + 1,
      ...(Number.isFinite(remainingRetryBudget)
        ? {
            timeout: Math.max(
              1,
              Math.floor(
                configuredTimeout > 0
                  ? Math.min(configuredTimeout, remainingRetryBudget)
                  : remainingRetryBudget,
              ),
            ),
          }
        : {}),
    };
    await waitForReadRetry(retryDelay, config as RoknRequestConfig | undefined);
    if (retryDeadlineAt && Date.now() >= retryDeadlineAt) {
      return Promise.reject(response ?? error);
    }
    return publicRequest.request(retryConfig).then(
      value => value as never,
      retryError => Promise.reject(retryError),
    );
  }

  if (
    response?.status === 401 &&
    config?.skipPersistedSessionInvalidation !== true
  ) {
    const rejectedToken = bearerTokenUsedByRequest(config);
    // A public guest request has no session to invalidate. Do not turn an
    // unexpected gateway 401 into a keychain read or Login navigation.
    if (!rejectedToken) return Promise.reject(response ?? error);
    const expiredToken = extractApiToken(peekSecureSession().session);
    // Several requests can fail together. Handle this bearer once so the
    // learner gets one Login screen instead of a stack of duplicates. A late
    // 401 from a request sent before reauthentication must never erase the
    // newer session that is already durable on the device.
    if (
      expiredToken &&
      rejectedToken === expiredToken &&
      handledExpiredToken !== expiredToken
    ) {
      handledExpiredToken = expiredToken;
      const returnTo = getLoginReturnToSnapshot();
      // Persist before deleting the session. If Android kills the process
      // while Login or the provider browser is opening, cold start restores
      // the same course/lesson instead of silently dropping the learner home.
      if (returnTo) {
        await savePendingLoginReturnTo(returnTo).catch(() => undefined);
      }
      cancelLearningReminders();
      await setSmartRemindersEnabled(false).catch(() => undefined);
      await invalidateLocalPushDeviceRegistration().catch(() => undefined);
      await import('../components/VideoPlayer/courseLearningApi')
        .then(module => module.quiesceLearningRuntime())
        .catch(() => undefined);
      await Promise.all([
        import('../components/VideoPlayer/courseChat/persistence')
          .then(module => module.quiesceCourseChatPersistence())
          .catch(() => undefined),
        import('../components/VideoPlayer/attachmentActions')
          .then(module => module.quiescePrivateAttachmentDownloads())
          .catch(() => undefined),
      ]);
      // Reauthentication can complete while the old 401 cleanup is awaiting
      // native storage or runtime quiescence (for example a late provider
      // callback/deep link). Never let that old response erase the newer
      // durable session or navigate its owner back to Login.
      let invalidated = false;
      try {
        invalidated = await deleteSecureSessionIfToken(expiredToken);
      } catch (storageError) {
        if (handledExpiredToken === expiredToken) handledExpiredToken = null;
        throw storageError;
      }
      if (!invalidated) {
        if (handledExpiredToken === expiredToken) handledExpiredToken = null;
        return Promise.reject(response ?? error);
      }
      // A 401 asks for reauthentication; it is not account deletion. Keep the
      // owner's scoped progress, pending submissions and editor drafts. If a
      // different person signs in next, secureSession clears the previous
      // scope before committing the replacement profile.
      await rotateGuestStorageScope().catch(() => undefined);
      // All awaited cleanup belongs to the expired bearer. If a provider
      // callback committed another session meanwhile, its synchronous Redux
      // state and navigation now own the app.
      if (extractApiToken(peekSecureSession().session)) {
        return Promise.reject(response ?? error);
      }
      handledExpiredToken = null;
      store.dispatch(LogOut());
      navigate('Login', returnTo ? {returnTo} : undefined);
    }
  }

  // Record only terminal availability failures. Validation, permissions and
  // expected not-found responses are product outcomes, not operational
  // incidents. Import lazily so diagnostics never become an HTTP dependency.
  if (safeTransientReadFailure || responseStatus >= 500) {
    const diagnosticError =
      error instanceof Error
        ? error
        : Object.assign(
            new Error(
              responseStatus ? `HTTP_${responseStatus}` : 'NETWORK_ERROR',
            ),
            errorRecord,
          );
    void import('../services/operationalTelemetry')
      .then(({reportClientError}) =>
        reportClientError(diagnosticError, {source: 'api_transport'}),
      )
      .catch(() => undefined);
  }

  return Promise.reject(response ?? error);
};
const axiosClient = axios.create({
  headers: headers,
  baseURL: mainUrl,
});
axiosClient.defaults.timeout = 15000;
axiosClient.defaults.timeoutErrorMessage = 'timeout';
axiosClient.defaults.maxRedirects = 0;

const withCapturedSession = <T>(
  config: RoknRequestConfig | undefined,
  method: string,
  send: (bound: RoknRequestConfig) => Promise<T>,
) => Promise.resolve(captureSessionAtApiCall(config, method)).then(send);

const request = ((
  configOrUrl: RoknRequestConfig | string,
  optionalConfig?: RoknRequestConfig,
) => {
  const urlFirst = typeof configOrUrl === 'string';
  const config = urlFirst ? optionalConfig : configOrUrl;
  const method = String(config?.method || 'get');
  return withCapturedSession(config, method, bound =>
    urlFirst
      ? axiosClient.request({...bound, url: configOrUrl})
      : axiosClient.request(bound),
  );
}) as AxiosInstance['request'];

const get = ((url: string, config?: RoknRequestConfig) =>
  withCapturedSession(config, 'get', bound =>
    axiosClient.get(url, bound),
  )) as AxiosInstance['get'];
const remove = ((url: string, config?: RoknRequestConfig) =>
  withCapturedSession(config, 'delete', bound =>
    axiosClient.delete(url, bound),
  )) as AxiosInstance['delete'];
const head = ((url: string, config?: RoknRequestConfig) =>
  withCapturedSession(config, 'head', bound =>
    axiosClient.head(url, bound),
  )) as AxiosInstance['head'];
const options = ((url: string, config?: RoknRequestConfig) =>
  withCapturedSession(config, 'options', bound =>
    axiosClient.options(url, bound),
  )) as AxiosInstance['options'];
const post = ((url: string, data?: unknown, config?: RoknRequestConfig) =>
  withCapturedSession(config, 'post', bound =>
    axiosClient.post(url, data, bound),
  )) as AxiosInstance['post'];
const put = ((url: string, data?: unknown, config?: RoknRequestConfig) =>
  withCapturedSession(config, 'put', bound =>
    axiosClient.put(url, data, bound),
  )) as AxiosInstance['put'];
const patch = ((url: string, data?: unknown, config?: RoknRequestConfig) =>
  withCapturedSession(config, 'patch', bound =>
    axiosClient.patch(url, data, bound),
  )) as AxiosInstance['patch'];

export const publicRequest: Pick<
  AxiosInstance,
  'delete' | 'get' | 'head' | 'options' | 'patch' | 'post' | 'put' | 'request'
> = {
  delete: remove,
  get,
  head,
  options,
  patch,
  post,
  put,
  request,
};

// interceptors
axiosClient.interceptors.request.use(responseConfig, responseError);
axiosClient.interceptors.response.use(onFulfilledRequest, onRejectedResponse);
