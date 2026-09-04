import * as Crypto from 'expo-crypto';
import * as WebBrowser from 'expo-web-browser';
import {Platform} from 'react-native';

import {sha256Base64Url} from '../utils/sha256';
import {serverNow} from '../utils/serverClock';
import {openAndroidAuthSession} from './androidAuthSession';
import {resumePendingSocialAuth} from './socialAuthCompletion';
import type {SocialAuthOptions} from './socialAuthContract';
import {safeAuthorizationUrl} from './socialAuthMethods';
import {
  deletePendingSocialAuthAttempt,
  savePendingSocialAuthAttempt,
  type PendingSocialAuthAttempt,
} from './secureSession';
import type {SocialAuthMethods, SocialProvider} from './socialAuthTypes';

type BrowserProvider = Exclude<SocialProvider, 'apple'>;

const createPkcePair = async () => {
  const verifier = `${Crypto.randomUUID()}${Crypto.randomUUID()}`.replace(
    /-/g,
    '',
  );
  return {verifier, challenge: sha256Base64Url(verifier)};
};

const encodeQuery = (values: Record<string, string>) =>
  Object.entries(values)
    .map(
      ([key, value]) =>
        `${encodeURIComponent(key)}=${encodeURIComponent(value)}`,
    )
    .join('&');

export const startBrowserSocialAuth = async (
  provider: BrowserProvider,
  methods: SocialAuthMethods,
  options: SocialAuthOptions,
) => {
  const startUrl = methods.authorizationUrls[provider];
  const resolvedStartUrl = startUrl
    ? safeAuthorizationUrl(provider, startUrl, methods.authorizationApiUrl)
    : '';
  if (!resolvedStartUrl) throw new Error('PROVIDER_NOT_CONFIGURED');

  let pkce: Awaited<ReturnType<typeof createPkcePair>>;
  try {
    pkce = await createPkcePair();
  } catch {
    throw new Error('LOGIN_SECURE_FLOW_UNAVAILABLE');
  }
  const returnUrl = 'rokn://auth';
  const separator = resolvedStartUrl.includes('?') ? '&' : '?';
  const authorizationUrl = `${resolvedStartUrl}${separator}${encodeQuery({
    return_to: returnUrl,
    code_challenge: pkce.challenge,
    code_challenge_method: 'S256',
  })}`;
  const attempt: PendingSocialAuthAttempt = {
    provider,
    verifier: pkce.verifier,
    challenge: pkce.challenge,
    flow: 'browser',
    startedAt: serverNow().toISOString(),
    purpose: options.purpose ?? 'login',
    authorizationApiUrl: methods.authorizationApiUrl.replace(/\/+$/, ''),
  };
  await savePendingSocialAuthAttempt(attempt);

  let result:
    | Awaited<ReturnType<typeof WebBrowser.openAuthSessionAsync>>
    | Awaited<ReturnType<typeof openAndroidAuthSession>>;
  try {
    result =
      Platform.OS === 'android'
        ? await openAndroidAuthSession(
            authorizationUrl,
            returnUrl,
            pkce.challenge,
          )
        : await WebBrowser.openAuthSessionAsync(authorizationUrl, returnUrl);
  } catch (error) {
    await deletePendingSocialAuthAttempt(attempt).catch(() => undefined);
    throw error;
  }

  if (result.type === 'cancel' || result.type === 'dismiss') {
    const recoverableAndroidReturn =
      result.type === 'cancel' &&
      'recoverable' in result &&
      result.recoverable === true;
    if (!recoverableAndroidReturn || (options.purpose ?? 'login') !== 'login') {
      await deletePendingSocialAuthAttempt(attempt);
    }
    if (recoverableAndroidReturn && (options.purpose ?? 'login') === 'login') {
      throw new Error('LOGIN_RESUMING');
    }
    throw new Error('LOGIN_CANCELLED');
  }
  if (result.type !== 'success') {
    await deletePendingSocialAuthAttempt(attempt);
    throw new Error('LOGIN_UNAVAILABLE');
  }

  const session = await resumePendingSocialAuth(result.url, options);
  if (!session) throw new Error('LOGIN_SESSION_INVALID');
  return session;
};
