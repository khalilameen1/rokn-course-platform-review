import {publicRequest} from '../constants/api';
import {getItem, saveItem} from '../constants/helpers';
import {isServerTimestampFresh, serverNowMs} from '../utils/serverClock';
import {trustedUpdateUrl} from './appVersionPolicy';
import {payload as apiPayload} from './api/common';

export type PublicAppSettings = {
  contract_version?: number;
  revision?: string;
  android_app_url?: unknown;
  ios_app_url?: unknown;
  direct_android_app_url?: unknown;
  support_whatsapp_url?: unknown;
  social_media?: {
    facebook?: unknown;
    youtube?: unknown;
    instagram?: unknown;
    tiktok?: unknown;
    whatsapp?: unknown;
    telegram?: unknown;
  };
};

let cachedSettings: PublicAppSettings | null = null;
let cachedAt = 0;
let pendingRequest: Promise<PublicAppSettings> | null = null;

const CACHE_TTL_MS = 60 * 1000;
const MAX_STALE_MS = 24 * 60 * 60 * 1000;
const CACHE_STORAGE_KEY = '@rokn/public-app-settings/v2/ar';

type StoredPublicAppSettings = {
  settings: PublicAppSettings;
  savedAt: number;
};

const readStoredSettings = async () => {
  const stored = await getItem<StoredPublicAppSettings>(
    CACHE_STORAGE_KEY,
  );
  if (
    !stored?.settings ||
    typeof stored.settings !== 'object' ||
    Array.isArray(stored.settings) ||
    !Number.isFinite(stored.savedAt)
  ) {
    return null;
  }
  return stored;
};

const safeHttpsUrl = (value: unknown) => {
  const raw = String(value ?? '').trim();
  if (!/^https:\/\//i.test(raw) || /https:\/\/[^/]*@/i.test(raw)) return '';
  try {
    return new URL(raw).protocol === 'https:' ? raw : '';
  } catch {
    return '';
  }
};

const safeWhatsAppUrl = (value: unknown) => {
  const url = safeHttpsUrl(value);
  if (!url) return '';
  try {
    const parsed = new URL(url);
    return /^(?:www\.)?wa\.me$/i.test(parsed.hostname) ? url : '';
  } catch {
    return '';
  }
};

const normalizeSettings = (value: unknown): PublicAppSettings | null => {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const raw = value as Record<string, unknown>;
  const social =
    raw.social_media &&
    typeof raw.social_media === 'object' &&
    !Array.isArray(raw.social_media)
      ? (raw.social_media as Record<string, unknown>)
      : {};
  return {
    contract_version: Number.isInteger(raw.contract_version)
      ? Number(raw.contract_version)
      : undefined,
    revision:
      typeof raw.revision === 'string' && raw.revision.length <= 128
        ? raw.revision
        : undefined,
    android_app_url: trustedUpdateUrl(raw.android_app_url, 'play') || undefined,
    ios_app_url:
      trustedUpdateUrl(raw.ios_app_url, 'appstore') || undefined,
    direct_android_app_url:
      trustedUpdateUrl(raw.direct_android_app_url, 'direct') || undefined,
    support_whatsapp_url:
      safeWhatsAppUrl(raw.support_whatsapp_url) || undefined,
    social_media: {
      facebook: safeHttpsUrl(social.facebook) || undefined,
      youtube: safeHttpsUrl(social.youtube) || undefined,
      instagram: safeHttpsUrl(social.instagram) || undefined,
      tiktok: safeHttpsUrl(social.tiktok) || undefined,
      whatsapp: safeWhatsAppUrl(social.whatsapp) || undefined,
      telegram: safeHttpsUrl(social.telegram) || undefined,
    },
  };
};

export const getPublicAppSettings = async (): Promise<PublicAppSettings> => {
  if (
    cachedSettings &&
    isServerTimestampFresh(cachedAt, CACHE_TTL_MS)
  ) {
    return cachedSettings;
  }
  if (pendingRequest) return pendingRequest;

  const request: Promise<PublicAppSettings> = readStoredSettings()
    .then(async stored => {
      if (stored && isServerTimestampFresh(stored.savedAt, CACHE_TTL_MS)) {
        const normalizedStored = normalizeSettings(stored.settings);
        if (normalizedStored) {
          cachedSettings = normalizedStored;
          cachedAt = stored.savedAt;
          return normalizedStored;
        }
      }
      try {
        const response = await publicRequest.get('settings');
        const responsePayload = apiPayload<unknown>(response);
        const settings = Array.isArray(responsePayload)
          ? responsePayload[0]
          : responsePayload;
        if (
          !settings ||
          typeof settings !== 'object' ||
          Array.isArray(settings)
        ) {
          throw new Error('PUBLIC_SETTINGS_CONTRACT_INVALID');
        }
        const normalized = normalizeSettings(settings);
        if (!normalized) throw new Error('PUBLIC_SETTINGS_CONTRACT_INVALID');
        cachedSettings = normalized;
        cachedAt = serverNowMs();
        await saveItem(CACHE_STORAGE_KEY, {
          settings: normalized,
          savedAt: cachedAt,
        });
        return normalized;
      } catch (error) {
        const storedSettings = normalizeSettings(stored?.settings);
        if (
          storedSettings &&
          stored &&
          isServerTimestampFresh(stored.savedAt, MAX_STALE_MS)
        ) {
          cachedSettings = storedSettings;
          cachedAt = stored.savedAt;
          return storedSettings;
        }
        throw error;
      }
    })
    .finally(() => {
      if (pendingRequest === request) pendingRequest = null;
    });

  pendingRequest = request;
  return request;
};

export const safeDashboardUrl = (value: unknown) => {
  return safeHttpsUrl(value);
};
