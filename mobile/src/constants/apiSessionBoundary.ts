import type {AxiosRequestConfig} from 'axios';
import {extractApiToken} from './helpers';
import {loadSecureSession, peekSecureSession} from '../services/secureSession';

export type RoknRequestConfig = AxiosRequestConfig & {
  skipPersistedSessionInvalidation?: boolean;
  skipAuthorization?: boolean;
  optionalAuthorization?: boolean;
  roknNetworkRetryCount?: number;
  roknNetworkRetryDeadlineAt?: number;
  roknSessionToken?: string;
  roknSessionEpoch?: number;
  roknSessionBoundAtCall?: boolean;
  roknSessionNeutral?: boolean;
};

export const assertResponseStillBelongsToSession = async (
  config?: Record<string, unknown>,
) => {
  if (config?.roknSessionNeutral === true) return;
  const requestEpoch = Number(config?.roknSessionEpoch);
  const requestToken =
    typeof config?.roknSessionToken === 'string'
      ? config.roknSessionToken.trim()
      : '';
  const activeSnapshot = peekSecureSession();
  const guestRestoreSettledWithoutAnAccount =
    !requestToken &&
    activeSnapshot.ready &&
    !extractApiToken(activeSnapshot.session);
  if (
    Number.isSafeInteger(requestEpoch) &&
    activeSnapshot.epoch !== requestEpoch &&
    !guestRestoreSettledWithoutAnAccount
  ) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
  if (!requestToken) return;
  const activeToken = extractApiToken(
    activeSnapshot.ready
      ? activeSnapshot.session
      : await loadSecureSession().catch(() => null),
  );
  if (activeToken !== requestToken) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
};

type CapturedRequestConfig = RoknRequestConfig | Promise<RoknRequestConfig>;

const accountWriteBeforeSessionRestore = (config: RoknRequestConfig) =>
  Promise.reject(
    Object.assign(new Error('SESSION_NOT_READY_FOR_ACCOUNT_WRITE'), {
      code: 'SESSION_NOT_READY_FOR_ACCOUNT_WRITE',
      config,
    }),
  );

export const captureSessionAtApiCall = (
  source: RoknRequestConfig | undefined,
  method: string,
): CapturedRequestConfig => {
  const config: RoknRequestConfig = {...(source || {})};
  if (config.roknSessionBoundAtCall === true) return config;
  if (config.skipAuthorization === true) {
    return {
      ...config,
      roknSessionBoundAtCall: true,
      roknSessionNeutral: true,
      roknSessionEpoch: undefined,
      roknSessionToken: undefined,
    };
  }

  const snapshot = peekSecureSession();
  const normalizedMethod = method.toLowerCase();
  const isWrite = !['get', 'head', 'options'].includes(normalizedMethod);
  if (!snapshot.ready && isWrite) {
    return accountWriteBeforeSessionRestore(config);
  }

  const bindSnapshot = (settled: ReturnType<typeof peekSecureSession>) => ({
    ...config,
    roknSessionBoundAtCall: true,
    roknSessionNeutral: false,
    roknSessionEpoch: settled.epoch,
    roknSessionToken: extractApiToken(settled.session) || undefined,
  });

  if (snapshot.ready || config.optionalAuthorization === true) {
    return bindSnapshot(snapshot);
  }
  return loadSecureSession().then(() => bindSnapshot(peekSecureSession()));
};
