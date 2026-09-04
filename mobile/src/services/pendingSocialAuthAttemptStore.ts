import {serializeSecureSessionMutation} from './secureSessionMutation';
import {
  PENDING_SOCIAL_AUTH_KEY,
  secureDeleteItem,
  secureGetItem,
  secureSetItem,
} from './secureSessionStorage';

export type PendingSocialAuthAttempt = {
  provider: 'google' | 'tiktok' | 'facebook' | 'apple';
  verifier: string;
  challenge?: string;
  flow?: 'browser' | 'native';
  startedAt: string;
  callbackUrl?: string;
  purpose?: 'login' | 'reauth';
  authorizationApiUrl?: string;
  nativeToken?: string;
  providerName?: string;
  completedSession?: unknown;
};

const sameAttempt = (
  current: Partial<PendingSocialAuthAttempt> | null,
  expected: PendingSocialAuthAttempt,
) =>
  Boolean(
    current &&
      current.provider === expected.provider &&
      current.verifier === expected.verifier &&
      current.startedAt === expected.startedAt &&
      (current.purpose ?? 'login') === (expected.purpose ?? 'login'),
  );

const readForMutation = async () => {
  const value = await secureGetItem(PENDING_SOCIAL_AUTH_KEY);
  if (!value) return null;
  try {
    return JSON.parse(value) as Partial<PendingSocialAuthAttempt>;
  } catch {
    return null;
  }
};

const deleteValueIfCurrent = (expectedValue: string) =>
  serializeSecureSessionMutation(async () => {
    if ((await secureGetItem(PENDING_SOCIAL_AUTH_KEY)) !== expectedValue) {
      return false;
    }
    await secureDeleteItem(PENDING_SOCIAL_AUTH_KEY);
    return true;
  });

export const savePendingSocialAuthAttempt = async (
  attempt: PendingSocialAuthAttempt,
) => {
  await serializeSecureSessionMutation(() =>
    secureSetItem(PENDING_SOCIAL_AUTH_KEY, JSON.stringify(attempt)),
  );
};

export const replacePendingSocialAuthAttempt = (
  expected: PendingSocialAuthAttempt,
  replacement: PendingSocialAuthAttempt,
) =>
  serializeSecureSessionMutation(async () => {
    const current = await readForMutation();
    if (!sameAttempt(current, expected)) return false;
    await secureSetItem(PENDING_SOCIAL_AUTH_KEY, JSON.stringify(replacement));
    return true;
  });

export const loadPendingSocialAuthAttempt = async () => {
  const value = await secureGetItem(PENDING_SOCIAL_AUTH_KEY);
  if (!value) return null;
  try {
    const attempt = JSON.parse(value) as Partial<PendingSocialAuthAttempt>;
    if (
      !['google', 'tiktok', 'facebook', 'apple'].includes(
        String(attempt.provider),
      ) ||
      typeof attempt.verifier !== 'string' ||
      !/^[A-Za-z0-9._~-]{43,128}$/.test(attempt.verifier) ||
      (attempt.challenge !== undefined &&
        !/^[A-Za-z0-9_-]{43,128}$/.test(attempt.challenge)) ||
      (attempt.flow !== undefined &&
        !['browser', 'native'].includes(attempt.flow)) ||
      (attempt.authorizationApiUrl !== undefined &&
        (attempt.flow !== 'browser' ||
          typeof attempt.authorizationApiUrl !== 'string' ||
          !/^https:\/\/[^/?#\s]+\/[^?#\s]+\/?$/i.test(
            attempt.authorizationApiUrl,
          ))) ||
      (attempt.nativeToken !== undefined &&
        (attempt.flow !== 'native' ||
          typeof attempt.nativeToken !== 'string' ||
          attempt.nativeToken.trim().length < 16 ||
          attempt.nativeToken.length > 16_384)) ||
      (attempt.providerName !== undefined &&
        (attempt.provider !== 'apple' ||
          typeof attempt.providerName !== 'string' ||
          attempt.providerName.length > 200)) ||
      typeof attempt.startedAt !== 'string' ||
      (attempt.purpose !== undefined &&
        !['login', 'reauth'].includes(attempt.purpose))
    ) {
      await deleteValueIfCurrent(value);
      return null;
    }
    return attempt as PendingSocialAuthAttempt;
  } catch {
    await deleteValueIfCurrent(value);
    return null;
  }
};

export const deletePendingSocialAuthAttempt = (
  expected?: PendingSocialAuthAttempt,
) =>
  serializeSecureSessionMutation(async () => {
    if (expected) {
      const current = await readForMutation();
      if (!sameAttempt(current, expected)) return false;
    }
    await secureDeleteItem(PENDING_SOCIAL_AUTH_KEY);
    return true;
  });
