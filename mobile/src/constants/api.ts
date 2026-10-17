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
import {cancelLearningReminders} from '../services/smartReminders';
import {invalidateLocalPushDeviceRegistration} from '../services/pushDeviceState';
import {roknApiUrl} from './apiBaseUrl';
import {observeServerTime} from '../utils/serverClock';
import {
  deleteSecureSessionIfToken,
  peekSecureSession,
} from '../services/secureSession';
import {
  retryAfterMs,
  retryableReadTransportFailure,
} from '../services/networkExperience';
import {reportBackgroundWriteFailure} from '../services/backgroundFailure';
import {toApiRejection} from './apiHttpError';
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
export {ApiError, isApiError} from './apiHttpError';

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
// has already failed. Three attempts in total cover a radio hand-off or gateway
// wake; the screen-owned background refresh handles longer outages.
//
// Delays use "full jitter" exponential backoff from the AWS Architecture Blog,
// "Exponential Backoff and Jitter" (Marc Brooker, 2015), the policy the AWS
// SDKs ship: sleep = random(0, min(cap, base * 2 ** attempt)). Fixed delays made
// every client that lost the origin together hit it again together.
export const READ_RETRY_LIMIT = 2;
export const READ_RETRY_BASE_DELAY_MS = 300;
export const READ_RETRY_MAX_DELAY_MS = 2_000;
// RFC 9110 §10.2.3 Retry-After is honoured only while it fits a foreground
// read. Rokn's maintenance/recovery 503s send `Retry-After: 300`; those fail
// fast so the screen shows its maintenance state instead of spinning.
export const READ_RETRY_AFTER_CAP_MS = 2_000;

export const readRetryDelayMs = (attempt: number, random = Math.random) =>
  Math.floor(
    Math.max(0, Math.min(1, random())) *
      Math.min(
        READ_RETRY_MAX_DELAY_MS,
        READ_RETRY_BASE_DELAY_MS * 2 ** Math.max(0, attempt),
      ),
  );

/** Null means "do not replay"; otherwise the minimum wait the server asked for. */
const readRetryFloorMs = (error: unknown, status: number): number | null => {
  const serverDelay = retryAfterMs(error);
  if (serverDelay !== null && serverDelay > READ_RETRY_AFTER_CAP_MS) {
    return null;
  }
  // 503 (maintenance) and 429 (rate limit) are replayed only on the server's
  // own short Retry-After guidance (RFC 9110 §15.6.4, RFC 6585 §4).
  if (status === 503 || status === 429) return serverDelay;
  return retryableReadTransportFailure(error) ? serverDelay ?? 0 : null;
};

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
  // `roknNetworkRetryCount: Number.MAX_SAFE_INTEGER` is the legacy way to opt
  // out and keeps working; new callers pass `retry: false`.
  const retryCount =
    Number.isSafeInteger(rawRetryCount) && rawRetryCount >= 0
      ? rawRetryCount
      : 0;
  const retryDeadlineAt = Number(config?.roknNetworkRetryDeadlineAt || 0);
  const responseStatus = Number(response?.status || 0);
  const safeTransientReadFailure = retryableReadTransportFailure(error);
  const rejection = toApiRejection(error);
  const retryFloorMs =
    config && config.retry !== false && retryCount < READ_RETRY_LIMIT
      ? readRetryFloorMs(error, responseStatus)
      : null;

  // Cover both a socket lost during Wi-Fi/mobile-data hand-off and the brief
  // gateway responses returned while the origin wakes. Keep the retry budget
  // finite and never replay a mutation whose server result may be unknown.
  if (retryFloorMs !== null && (method === 'get' || method === 'head')) {
    const retryDelay = Math.max(retryFloorMs, readRetryDelayMs(retryCount));
    const remainingRetryBudget = retryDeadlineAt
      ? retryDeadlineAt - Date.now() - retryDelay
      : Number.POSITIVE_INFINITY;
    // A timeout applies to each Axios attempt, not to the logical read. The
    // request policy supplies one journey-wide deadline so a weak connection cannot
    // multiply 15 seconds by every origin-wake retry and strand first launch.
    if (remainingRetryBudget > 0) {
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
      await waitForReadRetry(
        retryDelay,
        config as RoknRequestConfig | undefined,
      );
      if (!retryDeadlineAt || Date.now() < retryDeadlineAt) {
        return publicRequest.request(retryConfig).then(
          value => value as never,
          retryError => Promise.reject(retryError),
        );
      }
    }
    // Exhausting the read budget is a terminal availability failure too.
    // Continue to diagnostics before returning the original error below.
  }

  if (
    response?.status === 401 &&
    config?.skipPersistedSessionInvalidation !== true
  ) {
    const rejectedToken = bearerTokenUsedByRequest(config);
    // A public guest request has no session to invalidate. Do not turn an
    // unexpected gateway 401 into a keychain read or Login navigation.
    if (!rejectedToken) return Promise.reject(rejection);
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
        await savePendingLoginReturnTo(returnTo).catch(
          reportBackgroundWriteFailure('auth_expiry_return_route'),
        );
      }
      // Retire this device's timers, but keep the learner's stored reminder
      // opt-in. An expired bearer is not an opt-out; push registration
      // re-registers from that preference on the next sign-in.
      cancelLearningReminders();
      await invalidateLocalPushDeviceRegistration().catch(
        reportBackgroundWriteFailure('auth_expiry_push_registration'),
      );
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
        return Promise.reject(rejection);
      }
      // A 401 asks for reauthentication; it is not account deletion. Keep the
      // owner's scoped progress, pending submissions and editor drafts. If a
      // different person signs in next, secureSession clears the previous
      // scope before committing the replacement profile.
      await rotateGuestStorageScope().catch(
        reportBackgroundWriteFailure('auth_expiry_storage_scope'),
      );
      // All awaited cleanup belongs to the expired bearer. If a provider
      // callback committed another session meanwhile, its synchronous Redux
      // state and navigation now own the app.
      if (extractApiToken(peekSecureSession().session)) {
        return Promise.reject(rejection);
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

  return Promise.reject(rejection);
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
