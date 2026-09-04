import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../constants/api';
import {roknApiUrl} from '../constants/apiBaseUrl';
import {serverNowMs} from '../utils/serverClock';

export type ProductFeatureKey =
  | 'checkout'
  | 'playback'
  | 'project_uploads'
  | 'ai_chat';

type ProductFeatureSnapshot = {
  version: string;
  expiresAt: number;
  retrievedAt: number;
  flags: Record<ProductFeatureKey, boolean>;
};

export class ProductFeatureUnavailableError extends Error {
  readonly code: string;
  readonly feature: ProductFeatureKey;

  constructor(feature: ProductFeatureKey) {
    super(`FEATURE_${feature.toUpperCase()}_DISABLED`);
    this.name = 'ProductFeatureUnavailableError';
    this.feature = feature;
    this.code = `FEATURE_${feature.toUpperCase()}_DISABLED`;
  }
}

const FEATURE_KEYS: ProductFeatureKey[] = [
  'checkout',
  'playback',
  'project_uploads',
  'ai_chat',
];
const SNAPSHOT_KEY = `@rokn/product-feature-snapshot/v3:${encodeURIComponent(
  roknApiUrl,
)}`;
const MAX_SNAPSHOT_TTL_MS = 15 * 60 * 1000;
const REQUIRE_REMOTE_FLAGS =
  process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS === '1' ||
  process.env.EXPO_PUBLIC_BUILD_PROFILE === 'production';

// Control-plane outages leave paid learning available while release builds
// disables new financial, upload, and AI mutations.
const SAFE_DEFAULTS: Record<ProductFeatureKey, boolean> = {
  checkout: false,
  playback: true,
  project_uploads: false,
  ai_chat: false,
};
const DEVELOPMENT_DEFAULTS: Record<ProductFeatureKey, boolean> = {
  checkout: true,
  playback: true,
  project_uploads: true,
  ai_chat: true,
};

let memorySnapshot: ProductFeatureSnapshot | null = null;
let refreshFlight: Promise<ProductFeatureSnapshot | null> | null = null;

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const flagsAreValid = (
  value: unknown,
): value is Record<ProductFeatureKey, boolean> =>
  isRecord(value) && FEATURE_KEYS.every(key => typeof value[key] === 'boolean');

const parsePersistedSnapshot = (value: string | null) => {
  if (!value) return null;
  try {
    const parsed: unknown = JSON.parse(value);
    if (
      !isRecord(parsed) ||
      typeof parsed.version !== 'string' ||
      !parsed.version ||
      parsed.version.length > 128 ||
      !Number.isFinite(parsed.expiresAt) ||
      !Number.isFinite(parsed.retrievedAt) ||
      !flagsAreValid(parsed.flags)
    ) {
      return null;
    }
    return {
      version: parsed.version,
      expiresAt: Number(parsed.expiresAt),
      retrievedAt: Number(parsed.retrievedAt),
      flags: parsed.flags,
    };
  } catch {
    return null;
  }
};

const loadSnapshot = async () => {
  if (memorySnapshot) return memorySnapshot;
  const stored = await AsyncStorage.getItem(SNAPSHOT_KEY).catch(() => null);
  memorySnapshot = parsePersistedSnapshot(stored);
  return memorySnapshot;
};

const normalizeRemoteSnapshot = (
  raw: unknown,
): ProductFeatureSnapshot | null => {
  if (
    !isRecord(raw) ||
    typeof raw.version !== 'string' ||
    !raw.version ||
    raw.version.length > 128 ||
    !flagsAreValid(raw?.flags)
  ) {
    return null;
  }
  const remoteExpiry = Date.parse(String(raw.expires_at || ''));
  if (!Number.isFinite(remoteExpiry)) return null;
  const retrievedAt = serverNowMs();
  const expiresAt = Math.min(
    Math.max(retrievedAt, remoteExpiry),
    retrievedAt + MAX_SNAPSHOT_TTL_MS,
  );
  const flags = raw.flags;
  return {
    version: raw.version,
    retrievedAt,
    expiresAt,
    flags: FEATURE_KEYS.reduce(
      (snapshotFlags, key) => ({...snapshotFlags, [key]: flags[key]}),
      {} as Record<ProductFeatureKey, boolean>,
    ),
  };
};

export const refreshProductFeatures =
  async (): Promise<ProductFeatureSnapshot | null> => {
    if (refreshFlight) return refreshFlight;
    refreshFlight = (async () => {
      try {
        const response = await publicRequest.get<unknown>('product-features', {
          timeout: 6000,
        });
        const envelope = isRecord(response.data) ? response.data : {};
        const raw = envelope.data || envelope;
        const snapshot = normalizeRemoteSnapshot(raw);
        if (!snapshot) return null;
        memorySnapshot = snapshot;
        await AsyncStorage.setItem(
          SNAPSHOT_KEY,
          JSON.stringify(snapshot),
        ).catch(() => undefined);
        return snapshot;
      } catch {
        return null;
      }
    })();
    try {
      return await refreshFlight;
    } finally {
      refreshFlight = null;
    }
  };

export const isProductFeatureEnabled = async (
  feature: ProductFeatureKey,
): Promise<boolean> => {
  const cached = await loadSnapshot();
  if (cached && cached.expiresAt > serverNowMs()) {
    return cached.flags[feature];
  }

  const refreshed = await refreshProductFeatures();
  if (refreshed && refreshed.expiresAt > serverNowMs()) {
    return refreshed.flags[feature];
  }

  return (REQUIRE_REMOTE_FLAGS ? SAFE_DEFAULTS : DEVELOPMENT_DEFAULTS)[feature];
};

export const requireProductFeature = async (feature: ProductFeatureKey) => {
  if (!(await isProductFeatureEnabled(feature))) {
    throw new ProductFeatureUnavailableError(feature);
  }
};

export const bootstrapProductFeatures = async () => {
  await loadSnapshot();
  await refreshProductFeatures();
};

export const getProductFeatureDiagnosticsSnapshot = async () => {
  const snapshot = await loadSnapshot();
  return {
    source:
      snapshot && snapshot.expiresAt > serverNowMs()
        ? ('remote' as const)
        : REQUIRE_REMOTE_FLAGS
        ? ('safe-default' as const)
        : ('development-default' as const),
    version: snapshot?.version || null,
    expiresAt: snapshot?.expiresAt
      ? new Date(snapshot.expiresAt).toISOString()
      : null,
    flags:
      snapshot && snapshot.expiresAt > serverNowMs()
        ? snapshot.flags
        : REQUIRE_REMOTE_FLAGS
        ? SAFE_DEFAULTS
        : DEVELOPMENT_DEFAULTS,
  };
};

export const resetProductFeaturesForTests = () => {
  memorySnapshot = null;
  refreshFlight = null;
};

export const productFeatureSnapshotStorageKey = SNAPSHOT_KEY;
