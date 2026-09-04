import * as AppleAuthentication from 'expo-apple-authentication';
import * as Crypto from 'expo-crypto';
import {Platform} from 'react-native';

import {sha256Hex} from '../utils/sha256';
import {serverNowMs} from '../utils/serverClock';
import {exchangeAppleSocialToken} from './socialAuthCompletion';
import {
  socialAuthCompletionIsTerminal,
  socialAuthRecord,
  type SocialAuthOptions,
} from './socialAuthContract';
import {
  deletePendingSocialAuthAttempt,
  replacePendingSocialAuthAttempt,
  savePendingSocialAuthAttempt,
  type PendingSocialAuthAttempt,
} from './secureSession';

export const appleSocialAuthAvailable = async () =>
  Platform.OS === 'ios' &&
  (await AppleAuthentication.isAvailableAsync().catch(() => false));

const createAppleNonce = async () => {
  const bytes = await Crypto.getRandomBytesAsync(32);
  const raw = Array.from(bytes, byte =>
    byte.toString(16).padStart(2, '0'),
  ).join('');
  const digest = sha256Hex(raw);
  if (!/^[a-f0-9]{64}$/.test(raw) || !/^[a-f0-9]{64}$/.test(digest)) {
    throw new Error('APPLE_NONCE_GENERATION_FAILED');
  }
  return {raw, digest};
};

export const startAppleSocialAuth = async (options: SocialAuthOptions) => {
  if (!(await appleSocialAuthAvailable())) {
    throw new Error('PROVIDER_NOT_CONFIGURED');
  }

  let ownedAttempt: PendingSocialAuthAttempt | null = null;
  try {
    const nonce = await createAppleNonce();
    const attempt: PendingSocialAuthAttempt = {
      provider: 'apple',
      verifier: nonce.raw,
      flow: 'native',
      startedAt: new Date(serverNowMs()).toISOString(),
      purpose: options.purpose ?? 'login',
    };
    ownedAttempt = attempt;
    await savePendingSocialAuthAttempt(attempt);
    const credential = await AppleAuthentication.signInAsync({
      requestedScopes: [
        AppleAuthentication.AppleAuthenticationScope.FULL_NAME,
        AppleAuthentication.AppleAuthenticationScope.EMAIL,
      ],
      nonce: nonce.digest,
    });
    if (!credential.identityToken) throw new Error('LOGIN_SESSION_INVALID');

    const providerName = [
      credential.fullName?.givenName,
      credential.fullName?.familyName,
    ]
      .filter(Boolean)
      .join(' ')
      .trim();
    const recoverableAttempt: PendingSocialAuthAttempt = {
      ...attempt,
      nativeToken: credential.identityToken,
      ...(providerName ? {providerName} : {}),
    };
    if (!(await replacePendingSocialAuthAttempt(attempt, recoverableAttempt))) {
      throw new Error('LOGIN_SESSION_INVALID');
    }
    ownedAttempt = recoverableAttempt;
    return await exchangeAppleSocialToken(
      credential.identityToken,
      recoverableAttempt,
      options,
    );
  } catch (error: unknown) {
    if (socialAuthRecord(error)?.code === 'ERR_REQUEST_CANCELED') {
      if (ownedAttempt) {
        await deletePendingSocialAuthAttempt(ownedAttempt).catch(
          () => undefined,
        );
      }
      throw new Error('LOGIN_CANCELLED');
    }
    const localTerminal =
      error instanceof Error &&
      ['APPLE_NONCE_GENERATION_FAILED', 'LOGIN_SESSION_INVALID'].includes(
        error.message,
      );
    if (
      ownedAttempt &&
      (localTerminal || socialAuthCompletionIsTerminal(error))
    ) {
      await deletePendingSocialAuthAttempt(ownedAttempt).catch(() => undefined);
    }
    throw error;
  }
};
