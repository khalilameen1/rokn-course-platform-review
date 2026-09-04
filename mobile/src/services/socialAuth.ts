import * as WebBrowser from 'expo-web-browser';

import {startAppleSocialAuth} from './socialAuthApple';
import {startBrowserSocialAuth} from './socialAuthBrowser';
import {resumePendingSocialAuth} from './socialAuthCompletion';
import type {SocialAuthOptions} from './socialAuthContract';
import {getDeviceSocialAuthMethods} from './socialAuthDiscovery';
import type {
  SocialAuthMethods,
  SocialAuthSession,
  SocialProvider,
} from './socialAuthTypes';

export type {
  SocialAuthMethods,
  SocialAuthSession,
  SocialProvider,
} from './socialAuthTypes';
export {resumePendingSocialAuth};

WebBrowser.maybeCompleteAuthSession();

export const getSocialAuthMethods = getDeviceSocialAuthMethods;

let activeStart:
  | {
      key: string;
      promise: Promise<SocialAuthSession>;
    }
  | undefined;

/**
 * Stable facade for UI callers. One provider window owns the process at a
 * time; an identical duplicate observer joins it, while a competing provider
 * cannot overwrite the encrypted attempt underneath the first callback.
 */
export const signInWithSocialProvider = (
  provider: SocialProvider,
  preloadedMethods?: SocialAuthMethods,
  options: SocialAuthOptions = {},
) => {
  const key = `${options.purpose ?? 'login'}:${provider}`;
  if (activeStart) {
    if (activeStart.key === key) return activeStart.promise;
    return Promise.reject(new Error('SOCIAL_LOGIN_IN_PROGRESS'));
  }

  let promise: Promise<SocialAuthSession>;
  promise = (async () => {
    const methods = preloadedMethods ?? (await getSocialAuthMethods());
    if (!methods.providers.includes(provider)) {
      throw new Error('PROVIDER_NOT_CONFIGURED');
    }
    return provider === 'apple'
      ? startAppleSocialAuth(options)
      : startBrowserSocialAuth(provider, methods, options);
  })().finally(() => {
    if (activeStart?.promise === promise) activeStart = undefined;
  });
  activeStart = {key, promise};
  return promise;
};
