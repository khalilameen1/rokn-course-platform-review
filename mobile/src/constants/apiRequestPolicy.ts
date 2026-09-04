import type {InternalAxiosRequestConfig} from 'axios';
import {Platform} from 'react-native';
import appConfig from '../../app.json';
import {getInstallationId} from '../services/installationIdentity';
import {secureRandomUuid} from '../utils/secureRandom';
import {currentDeviceClass} from './deviceClass';
import {
  assertResponseStillBelongsToSession,
  captureSessionAtApiCall,
  type RoknRequestConfig,
} from './apiSessionBoundary';

export const DEFAULT_READ_RECOVERY_BUDGET_MS = 12_000;

const clampTimeoutToReadDeadline = (
  config: InternalAxiosRequestConfig & RoknRequestConfig,
) => {
  const deadlineAt = Number(config.roknNetworkRetryDeadlineAt || 0);
  if (!Number.isFinite(deadlineAt) || deadlineAt <= 0) return;
  const remaining = deadlineAt - Date.now();
  if (remaining <= 0) {
    throw Object.assign(new Error('timeout'), {
      code: 'ETIMEDOUT',
      config,
    });
  }
  const configuredTimeout = Number(config.timeout || 0);
  config.timeout = Math.max(
    1,
    Math.floor(
      configuredTimeout > 0
        ? Math.min(configuredTimeout, remaining)
        : remaining,
    ),
  );
};

const attachRequestIdentity = (config: InternalAxiosRequestConfig) => {
  if (config.headers.has('X-Request-Id')) return;
  try {
    config.headers.set('X-Request-Id', secureRandomUuid());
  } catch {
    // The backend creates the tracing id when native crypto is unavailable.
  }
};

export const responseConfig = async (config: InternalAxiosRequestConfig) => {
  const requestConfig = config as InternalAxiosRequestConfig &
    RoknRequestConfig;
  const method = String(requestConfig.method || 'get').toLowerCase();
  if (
    (method === 'get' || method === 'head') &&
    !Number(requestConfig.roknNetworkRetryDeadlineAt || 0)
  ) {
    requestConfig.roknNetworkRetryDeadlineAt =
      Date.now() + DEFAULT_READ_RECOVERY_BUDGET_MS;
  }
  clampTimeoutToReadDeadline(requestConfig);

  const alreadyBound =
    Number(requestConfig.roknNetworkRetryCount || 0) > 0 ||
    requestConfig.roknSessionBoundAtCall === true;
  if (alreadyBound) {
    await assertResponseStillBelongsToSession(
      requestConfig as unknown as Record<string, unknown>,
    );
  } else if (requestConfig.skipAuthorization === true) {
    requestConfig.roknSessionNeutral = true;
    requestConfig.roknSessionBoundAtCall = true;
  } else {
    Object.assign(
      requestConfig,
      await captureSessionAtApiCall(requestConfig, method),
    );
  }

  if (method === 'post' && !config.data) config.data = {};
  if (method === 'get' && !config.params) config.params = {};

  config.headers.set('Accept-Language', 'ar');
  attachRequestIdentity(config);
  config.headers.set('X-Rokn-Platform', Platform.OS);
  config.headers.set('X-Rokn-Device-Class', currentDeviceClass());
  const installationId = await getInstallationId();
  if (installationId) {
    config.headers.set('X-Rokn-Installation', installationId);
  }
  config.headers.set('X-Rokn-App-Version', appConfig.expo.version);
  config.headers.set(
    'X-Rokn-App-Build',
    String(
      Platform.OS === 'ios'
        ? appConfig.expo.ios.buildNumber
        : appConfig.expo.android.versionCode,
    ),
  );

  const apiToken = requestConfig.roknSessionToken;
  if (
    apiToken &&
    requestConfig.skipAuthorization !== true &&
    !config.headers.has('Authorization')
  ) {
    config.headers.set('Authorization', `Bearer ${apiToken}`);
  }

  // Native installation lookup above yields to the event loop. Recheck the
  // owner immediately before Axios dispatches the request.
  await assertResponseStillBelongsToSession(
    requestConfig as unknown as Record<string, unknown>,
  );
  clampTimeoutToReadDeadline(requestConfig);
  return config;
};

export const responseError = (error: unknown) => Promise.reject(error);
