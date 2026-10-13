import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {roknApiUrl} from '../constants/apiBaseUrl';
import {serverNowMs} from '../utils/serverClock';
import {settleWithin} from '../utils/settleWithin';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';

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
let cacheReadFlight: Promise<ProductFeatureSnapshot | null> | null = null;
let refreshFlight: Promise<ProductFeatureSnapshot | null> | null = null;
let runtimeGeneration = 0;
const persistSnapshot = createKeyedAsyncQueue();

const isFreshSnapshot = (snapshot: ProductFeatureSnapshot | null): boolean =>
  snapshot !== null && snapshot.expiresAt > serverNowMs();

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
  if (!cacheReadFlight) {
    const generation = runtimeGeneration;
    const read = (async () => {
      const stored = await AsyncStorage.getItem(SNAPSHOT_KEY).catch(() => null);
      if (generation !== runtimeGeneration) return null;
      // A late disk read must never replace flags already received remotely.
      if (!memorySnapshot) memorySnapshot = parsePersistedSnapshot(stored);
      return memorySnapshot;
    })();
    cacheReadFlight = settleWithin(read, null);
  }
  return cacheReadFlight;
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
    const generation = runtimeGeneration;
    const flight = (async () => {
      try {
        const response = await publicRequest.get<unknown>('product-features', {
          // Rollout uses the installation header, not an authenticated account.
          skipAuthorization: true,
          timeout: 6000,
        } as RoknRequestConfig);
        const envelope = isRecord(response.data) ? response.data : {};
        const raw = envelope.data || envelope;
        const snapshot = normalizeRemoteSnapshot(raw);
        if (!snapshot || generation !== runtimeGeneration) return null;
        memorySnapshot = snapshot;
        // Delivery owns the validated in-memory snapshot, not its optional
        // disk copy. Keep raw writes serialized after callers have continued.
        void persistSnapshot(SNAPSHOT_KEY, async () => {
          if (generation !== runtimeGeneration || !memorySnapshot) return;
          await AsyncStorage.setItem(
            SNAPSHOT_KEY,
            JSON.stringify(memorySnapshot),
          );
        }).catch(() => undefined);
        return snapshot;
      } catch {
        return null;
      }
    })();
    refreshFlight = flight;
    try {
      return await flight;
    } finally {
      if (refreshFlight === flight) refreshFlight = null;
    }
  };

export const isProductFeatureEnabled = async (
  feature: ProductFeatureKey,
): Promise<boolean> => {
  if (memorySnapshot && isFreshSnapshot(memorySnapshot)) {
    return memorySnapshot.flags[feature];
  }

  // Cold starts hydrate disk and network concurrently. Either fresh result
  // can answer; a cache miss must not end the pending remote permission read.
  const cached = loadSnapshot();
  const refreshed = refreshProductFeatures();
  await Promise.race([cached, refreshed]);
  if (!isFreshSnapshot(memorySnapshot)) {
    await Promise.all([cached, refreshed]);
  }
  if (memorySnapshot && isFreshSnapshot(memorySnapshot)) {
    return memorySnapshot.flags[feature];
  }

  return (REQUIRE_REMOTE_FLAGS ? SAFE_DEFAULTS : DEVELOPMENT_DEFAULTS)[feature];
};

export const requireProductFeature = async (feature: ProductFeatureKey) => {
  if (!(await isProductFeatureEnabled(feature))) {
    throw new ProductFeatureUnavailableError(feature);
  }
};

export const bootstrapProductFeatures = async () => {
  void loadSnapshot();
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
  runtimeGeneration += 1;
  memorySnapshot = null;
  cacheReadFlight = null;
  refreshFlight = null;
};

export const productFeatureSnapshotStorageKey = SNAPSHOT_KEY;
