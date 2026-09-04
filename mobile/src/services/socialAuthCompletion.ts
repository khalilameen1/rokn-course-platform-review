import {Platform} from 'react-native';

import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {roknApiUrl} from '../constants/apiBaseUrl';
import {serverNowMs} from '../utils/serverClock';
import {getInstallationId} from './installationIdentity';
import {
  isSocialAuthCallbackUrl,
  socialAuthCompletionIsTerminal,
  socialAuthQueryValue,
  socialAuthResponseCode,
  socialAuthResponseStatus,
  socialAuthSessionFromResponse,
  validateSocialAuthSession,
  type SocialAuthOptions,
} from './socialAuthContract';
import {persistCompletedSocialLogin} from './socialAuthSession';
import {
  deletePendingSocialAuthAttempt,
  loadPendingSocialAuthAttempt,
  replacePendingSocialAuthAttempt,
  type PendingSocialAuthAttempt,
} from './secureSession';
import type {SocialAuthSession} from './socialAuthTypes';

const COMPLETION_BUDGET_MS = 20_000;
const completionFlights = new Map<string, Promise<SocialAuthSession>>();

const attemptKey = (pending: PendingSocialAuthAttempt) =>
  [
    pending.provider,
    pending.verifier,
    pending.startedAt,
    pending.purpose ?? 'login',
  ].join('|');

const runCompletion = (
  pending: PendingSocialAuthAttempt,
  operation: () => Promise<SocialAuthSession>,
) => {
  const key = attemptKey(pending);
  const current = completionFlights.get(key);
  if (current) return current;

  let flight: Promise<SocialAuthSession>;
  flight = operation().finally(() => {
    if (completionFlights.get(key) === flight) completionFlights.delete(key);
  });
  completionFlights.set(key, flight);
  return flight;
};

const completeBrowserCode = async (
  code: string,
  pending: PendingSocialAuthAttempt,
) => {
  const installationId = await getInstallationId();
  const body = {
    code,
    code_verifier: pending.verifier,
    device_os: Platform.OS,
    device_type: Platform.OS,
    ...(installationId ? {device_id: installationId} : {}),
  };
  const retryDelays = [0, 700, 1500, 3000];
  const deadlineAt = Date.now() + COMPLETION_BUDGET_MS;

  for (let index = 0; index < retryDelays.length; index += 1) {
    const delay = retryDelays[index];
    if (delay) await new Promise(resolve => setTimeout(resolve, delay));
    const remainingBudget = deadlineAt - Date.now();
    if (remainingBudget <= 0) throw new Error('LOGIN_TIMEOUT');

    try {
      return await publicRequest.post('social-auth/complete', body, {
        timeout: Math.max(1, Math.min(8_000, remainingBudget)),
        skipAuthorization: true,
        baseURL: `${(pending.authorizationApiUrl || roknApiUrl).replace(
          /\/+$/,
          '',
        )}/`,
      } as RoknRequestConfig);
    } catch (error) {
      const status = socialAuthResponseStatus(error);
      const retryable =
        status === 0 ||
        status === 408 ||
        status === 429 ||
        status >= 500 ||
        (status === 409 &&
          socialAuthResponseCode(error) === 'SOCIAL_LOGIN_IN_PROGRESS');
      if (
        !retryable ||
        index === retryDelays.length - 1 ||
        Date.now() >= deadlineAt
      ) {
        throw error;
      }
    }
  }
  throw new Error('LOGIN_UNAVAILABLE');
};

export const exchangeAppleSocialToken = (
  token: string,
  pending: PendingSocialAuthAttempt,
  options: SocialAuthOptions,
) =>
  runCompletion(pending, async () => {
    const installationId = await getInstallationId();
    const response = await publicRequest.post(
      'social-login',
      {
        provider: 'apple',
        token,
        nonce: pending.verifier,
        ...(pending.providerName ? {provider_name: pending.providerName} : {}),
        device_os: Platform.OS,
        device_type: Platform.OS,
        ...(installationId ? {device_id: installationId} : {}),
      },
      {skipAuthorization: true} as RoknRequestConfig,
    );
    const session = socialAuthSessionFromResponse(response?.data, 'apple');
    if ((options.purpose ?? 'login') === 'login') {
      if (!(await persistCompletedSocialLogin(pending, session))) {
        throw new Error('LOGIN_SESSION_INVALID');
      }
    } else {
      await deletePendingSocialAuthAttempt(pending);
    }
    return session;
  });

export const resumePendingSocialAuth = async (
  callbackUrl?: string | null,
  options: SocialAuthOptions = {},
): Promise<SocialAuthSession | null> => {
  const pending = await loadPendingSocialAuthAttempt();
  if (!pending) return null;

  const purpose = options.purpose ?? 'login';
  if (purpose !== (pending.purpose ?? 'login')) return null;

  if (pending.completedSession) {
    const completedAt = Date.parse(pending.startedAt);
    const age = serverNowMs() - completedAt;
    if (
      !Number.isFinite(completedAt) ||
      age < -60_000 ||
      age > 24 * 60 * 60 * 1000
    ) {
      await deletePendingSocialAuthAttempt(pending);
      return null;
    }
    const completed = validateSocialAuthSession(
      pending.completedSession,
      pending.provider,
    );
    if (purpose === 'login') {
      if (!(await persistCompletedSocialLogin(pending, completed))) return null;
    } else {
      await deletePendingSocialAuthAttempt(pending);
    }
    return completed;
  }

  const startedAt = Date.parse(pending.startedAt);
  const elapsed = serverNowMs() - startedAt;
  if (
    !Number.isFinite(startedAt) ||
    elapsed < -60_000 ||
    elapsed > 10 * 60 * 1000
  ) {
    await deletePendingSocialAuthAttempt(pending);
    return null;
  }

  if (
    pending.flow === 'native' &&
    pending.nativeToken &&
    pending.provider === 'apple'
  ) {
    try {
      return await exchangeAppleSocialToken(
        pending.nativeToken,
        pending,
        options,
      );
    } catch (error) {
      if (socialAuthCompletionIsTerminal(error)) {
        await deletePendingSocialAuthAttempt(pending);
      }
      throw error;
    }
  }

  const returnedUrl =
    callbackUrl && isSocialAuthCallbackUrl(callbackUrl)
      ? callbackUrl
      : pending.callbackUrl;
  if (!returnedUrl || !isSocialAuthCallbackUrl(returnedUrl)) return null;

  const returnedAttempt = socialAuthQueryValue(returnedUrl, 'attempt');
  if (
    pending.flow === 'native' ||
    (pending.flow === 'browser' &&
      (!pending.challenge || returnedAttempt !== pending.challenge)) ||
    (pending.flow === undefined &&
      pending.challenge &&
      returnedAttempt !== pending.challenge)
  ) {
    return null;
  }

  if (pending.callbackUrl !== returnedUrl) {
    const updated = await replacePendingSocialAuthAttempt(pending, {
      ...pending,
      callbackUrl: returnedUrl,
    });
    if (!updated) return null;
  }

  const returnedError = socialAuthQueryValue(returnedUrl, 'error');
  if (returnedError) {
    if (!(await deletePendingSocialAuthAttempt(pending))) return null;
    if (
      [
        'access_denied',
        'user_cancelled',
        'login_cancelled',
        'cancelled',
      ].includes(returnedError)
    ) {
      throw new Error('LOGIN_CANCELLED');
    }
    throw new Error(
      returnedError === 'provider_unavailable'
        ? 'LOGIN_UNAVAILABLE'
        : 'LOGIN_FAILED',
    );
  }

  const code = socialAuthQueryValue(returnedUrl, 'code');
  if (!code) {
    if (!(await deletePendingSocialAuthAttempt(pending))) return null;
    throw new Error('LOGIN_CODE_MISSING');
  }

  try {
    return await runCompletion(pending, async () => {
      const response = await completeBrowserCode(code, pending);
      const session = socialAuthSessionFromResponse(
        response?.data,
        pending.provider,
      );
      if (purpose === 'login') {
        if (
          !(await persistCompletedSocialLogin(
            {...pending, callbackUrl: returnedUrl},
            session,
          ))
        ) {
          throw new Error('LOGIN_SESSION_INVALID');
        }
      } else {
        await deletePendingSocialAuthAttempt(pending);
      }
      return session;
    });
  } catch (error) {
    if (socialAuthCompletionIsTerminal(error)) {
      await deletePendingSocialAuthAttempt(pending);
    }
    throw error;
  }
};
