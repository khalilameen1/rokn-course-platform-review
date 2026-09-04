import type {SocialAuthSession, SocialProvider} from './socialAuthTypes';

export type SocialAuthPurpose = 'login' | 'reauth';

export type SocialAuthOptions = {
  purpose?: SocialAuthPurpose;
};

export const nonEmptySocialAuthString = (value: unknown) =>
  typeof value === 'string' && value.trim() ? value.trim() : '';

export const socialAuthRecord = (
  value: unknown,
): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

export const socialAuthQueryValue = (url: string, key: string) => {
  const query = url.split('?')[1]?.split('#')[0] || '';
  for (const part of query.split('&')) {
    const [rawKey, ...rawValue] = part.split('=');
    try {
      if (decodeURIComponent(rawKey || '') === key) {
        return decodeURIComponent(rawValue.join('=') || '');
      }
    } catch {
      return '';
    }
  }
  return '';
};

export const isSocialAuthCallbackUrl = (value: string) =>
  value === 'rokn://auth' || value.startsWith('rokn://auth?');

export const socialAuthResponseStatus = (error: unknown) => {
  const root = socialAuthRecord(error);
  const response = socialAuthRecord(root?.response) ?? root;
  return Number(response?.status || 0);
};

export const socialAuthResponseCode = (error: unknown) => {
  const root = socialAuthRecord(error);
  const response = socialAuthRecord(root?.response) ?? root;
  const data = socialAuthRecord(response?.data);
  return nonEmptySocialAuthString(data?.code).toUpperCase();
};

export const socialAuthCompletionIsTerminal = (error: unknown) => {
  const status = socialAuthResponseStatus(error);
  return (
    status >= 400 &&
    status < 500 &&
    status !== 429 &&
    !(
      status === 409 &&
      socialAuthResponseCode(error) === 'SOCIAL_LOGIN_IN_PROGRESS'
    )
  );
};

export const validateSocialAuthSession = (
  value: unknown,
  provider: SocialProvider,
): SocialAuthSession => {
  const session = socialAuthRecord(value);
  const profile = socialAuthRecord(session?.user);
  const apiToken = nonEmptySocialAuthString(session?.api_token);
  const name = nonEmptySocialAuthString(profile?.name);
  const accountId = profile?.id;
  const sessionProvider = nonEmptySocialAuthString(profile?.social_provider);

  if (
    !session ||
    !profile ||
    !apiToken ||
    !name ||
    accountId === null ||
    accountId === undefined ||
    String(accountId).trim() === '' ||
    sessionProvider !== provider
  ) {
    throw new Error('LOGIN_SESSION_INVALID');
  }

  return {
    ...session,
    api_token: apiToken,
    user: {
      ...profile,
      name,
      email: nonEmptySocialAuthString(profile.email) || null,
      social_provider: provider,
    },
  };
};

export const socialAuthSessionFromResponse = (
  payload: unknown,
  provider: SocialProvider,
) => {
  const envelope = socialAuthRecord(payload);
  if (envelope?.success !== true || Number(envelope.status) !== 200) {
    throw new Error('LOGIN_SESSION_INVALID');
  }
  return validateSocialAuthSession(envelope.data, provider);
};
