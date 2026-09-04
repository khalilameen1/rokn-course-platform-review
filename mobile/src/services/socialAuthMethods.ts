import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  publicRequest,
  type RoknRequestConfig,
} from '../constants/api';
import {roknApiUrl} from '../constants/apiBaseUrl';
import {
  networkFailureKind,
  transientReadFailureAllowsCache,
} from './networkExperience';
import {settleWithin} from '../utils/settleWithin';
import {
  resolveSocialAuthStartUrl,
  type BrowserSocialProvider,
} from './socialAuthUrlPolicy';
import type {SocialAuthMethods, SocialProvider} from './socialAuthTypes';

const CACHE_KEY = '@rokn/social-auth-methods/v1';
const CACHE_TTL_MS = 24 * 60 * 60 * 1000;
const READ_BUDGET_MS = DEFAULT_READ_RECOVERY_BUDGET_MS;
const CACHE_READ_BUDGET_MS = 350;

const isSocialProvider = (value: string): value is SocialProvider =>
  ['google', 'tiktok', 'facebook', 'apple'].includes(value);

const nonEmptyString = (value: unknown) =>
  typeof value === 'string' && value.trim() ? value.trim() : '';

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

export const safeAuthorizationUrl = (
  provider: SocialProvider,
  value: unknown,
  advertisedApiUrl?: unknown,
) =>
  provider === 'apple'
    ? ''
    : resolveSocialAuthStartUrl(
        value,
        roknApiUrl,
        provider as BrowserSocialProvider,
        advertisedApiUrl,
      );

const authorizationUrls = (
  value: unknown,
  advertisedApiUrl?: unknown,
): Partial<Record<SocialProvider, string>> => {
  const record = asRecord(value);
  if (!record) return {};
  return Object.fromEntries(
    Object.entries(record).flatMap(([provider, url]) => {
      if (
        !isSocialProvider(provider) ||
        provider === 'apple' ||
        typeof url !== 'string'
      ) {
        return [];
      }
      try {
        const resolved = safeAuthorizationUrl(
          provider,
          url,
          advertisedApiUrl,
        );
        return resolved ? [[provider, resolved]] : [];
      } catch {
        return [];
      }
    }),
  ) as Partial<Record<SocialProvider, string>>;
};

const fromResponse = (value: unknown): SocialAuthMethods => {
  const envelope = asRecord(value);
  const methods = asRecord(envelope?.data);
  if (
    !methods ||
    envelope?.success !== true ||
    Number(envelope.status) !== 200 ||
    !Array.isArray(methods.providers) ||
    methods.providers.some(provider => !isSocialProvider(String(provider)))
  ) {
    throw new Error('SOCIAL_AUTH_METHODS_CONTRACT_INVALID');
  }
  const authorizationApiUrl = nonEmptyString(methods.authorization_api_url);
  if (!authorizationApiUrl) {
    throw new Error('SOCIAL_AUTH_METHODS_CONTRACT_INVALID');
  }
  const urls = authorizationUrls(
    methods.authorization_urls,
    authorizationApiUrl,
  );
  const declaredProviders = Array.from(
    new Set(methods.providers.map(String)),
  ) as SocialProvider[];
  const configuredProviders = declaredProviders.filter(
    provider => provider === 'apple' || Boolean(urls[provider]),
  );
  if (configuredProviders.length !== declaredProviders.length) {
    throw new Error('SOCIAL_AUTH_METHODS_CONTRACT_INVALID');
  }
  const requestedRecommendation = nonEmptyString(methods.recommended_provider);
  const recommendedProvider =
    isSocialProvider(requestedRecommendation) &&
    configuredProviders.includes(requestedRecommendation)
      ? requestedRecommendation
      : configuredProviders[0] ?? null;
  const recommendationText = nonEmptyString(methods.recommendation_badge);
  const welcomeBonus = Number(methods.welcome_bonus_coins);
  return {
    providers: configuredProviders,
    authorizationUrls: urls,
    authorizationApiUrl,
    welcomeBonus:
      Number.isSafeInteger(welcomeBonus) && welcomeBonus > 0
        ? welcomeBonus
        : null,
    recommendedProvider,
    recommendationText: recommendationText || null,
  };
};

const fromCache = (value: unknown): SocialAuthMethods => {
  const methods = asRecord(value);
  if (
    !methods ||
    !Array.isArray(methods.providers) ||
    methods.providers.some(provider => !isSocialProvider(String(provider))) ||
    !nonEmptyString(methods.authorizationApiUrl)
  ) {
    throw new Error('SOCIAL_AUTH_METHODS_CONTRACT_INVALID');
  }
  const providers = Array.from(
    new Set(methods.providers.map(String)),
  ) as SocialProvider[];
  const authorizationApiUrl = nonEmptyString(methods.authorizationApiUrl);
  const urls = authorizationUrls(methods.authorizationUrls, authorizationApiUrl);
  if (
    providers.some(provider => provider !== 'apple' && !urls[provider])
  ) {
    throw new Error('SOCIAL_AUTH_METHODS_CONTRACT_INVALID');
  }
  const requestedRecommendation = nonEmptyString(methods.recommendedProvider);
  const welcomeBonus = Number(methods.welcomeBonus);
  return {
    providers,
    authorizationUrls: urls,
    authorizationApiUrl,
    welcomeBonus:
      Number.isSafeInteger(welcomeBonus) && welcomeBonus > 0
        ? welcomeBonus
        : null,
    recommendedProvider:
      isSocialProvider(requestedRecommendation) &&
      providers.includes(requestedRecommendation)
        ? requestedRecommendation
        : providers[0] ?? null,
    recommendationText: nonEmptyString(methods.recommendationText) || null,
  };
};

const readCache = async (): Promise<SocialAuthMethods | null> => {
  try {
    const raw = await AsyncStorage.getItem(CACHE_KEY);
    const cached = raw ? asRecord(JSON.parse(raw)) : null;
    const savedAt = Number(cached?.savedAt);
    const age = Date.now() - savedAt;
    if (
      cached?.apiUrl !== roknApiUrl ||
      !Number.isFinite(savedAt) ||
      age < -5 * 60 * 1000 ||
      age > CACHE_TTL_MS
    ) {
      return null;
    }
    const methods = fromCache(cached?.methods);
    return methods.providers.length ? methods : null;
  } catch {
    return null;
  }
};

const readCacheWithinBudget = () =>
  settleWithin(readCache(), null, CACHE_READ_BUDGET_MS);

const writeCache = (methods: SocialAuthMethods) => {
  // The live discovery response is authoritative. Cache persistence only
  // helps a later launch and must not delay the current login sheet.
  void Promise.resolve()
    .then(() =>
      methods.providers.length
        ? AsyncStorage.setItem(
            CACHE_KEY,
            JSON.stringify({savedAt: Date.now(), apiUrl: roknApiUrl, methods}),
          )
        : AsyncStorage.removeItem(CACHE_KEY),
    )
    .catch(() => undefined);
};

const removeCache = () => {
  void Promise.resolve()
    .then(() => AsyncStorage.removeItem(CACHE_KEY))
    .catch(() => undefined);
};

export const getSocialAuthMethods = async (): Promise<SocialAuthMethods> => {
  try {
    const methodsResponse = await publicRequest.get<unknown>('auth-methods', {
      skipAuthorization: true,
      timeout: 8_000,
      roknNetworkRetryDeadlineAt: Date.now() + READ_BUDGET_MS,
    } as RoknRequestConfig);
    const methods = fromResponse(methodsResponse.data);
    writeCache(methods);
    return methods;
  } catch (error) {
    if (transientReadFailureAllowsCache(error)) {
      const cached = await readCacheWithinBudget();
      if (cached) return cached;
    } else if (networkFailureKind(error) === 'contract') {
      // Invalid active contracts must not be concealed by old provider data,
      // but local cleanup is still outside the current discovery result.
      removeCache();
    }
    throw error;
  }
};
