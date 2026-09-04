import {Platform, Share} from 'react-native';

import appConfig from '../../app.json';
import {
  CAN_START_EXTERNAL_CHECKOUT,
  DISTRIBUTION_CHANNEL,
  IS_APP_STORE_DISTRIBUTION,
  IS_PLAY_DISTRIBUTION,
  IS_STORE_DISTRIBUTION,
} from '../constants/distribution';
import {getOperationalDiagnosticsSnapshot} from './operationalTelemetry';
import {getProductFeatureDiagnosticsSnapshot} from './productFeatures';

const MAX_OPERATIONAL_EVENTS = 8;

const SAFE_EVENT_NAMES = [
  'app_crash',
  'video_failure',
  'payment_flow_failure',
  'project_upload_failure',
  'authentication_failure',
  'api_failure',
] as const;

type SafeEventName = (typeof SAFE_EVENT_NAMES)[number];
type SafeSeverity = 'error' | 'fatal';

const EVENT_NAMES = new Set<string>(SAFE_EVENT_NAMES);

const SPECIFIC_ERROR_CODES = new Set([
  'VIDEO_BUFFER_TIMEOUT',
  'VIDEO_PLAYBACK',
  'PAYMENT_STATUS_TIMEOUT',
]);

const GENERIC_ERROR_CODE: Record<SafeEventName, string> = {
  app_crash: 'APP_ERROR',
  video_failure: 'VIDEO_ERROR',
  payment_flow_failure: 'PAYMENT_ERROR',
  project_upload_failure: 'PROJECT_ERROR',
  authentication_failure: 'AUTHENTICATION_ERROR',
  api_failure: 'API_ERROR',
};

export type DebugBundleOperationalEvent = {
  event: SafeEventName;
  severity: SafeSeverity;
  code: string;
  occurred_at: string;
  attempts: number;
};

export type DebugBundle = {
  schema_version: 1;
  generated_at: string;
  app: {
    version: string;
    build_number?: number;
    platform: 'android' | 'ios' | 'web' | 'other';
    os_major?: number;
    distribution_channel: typeof DISTRIBUTION_CHANNEL;
  };
  feature_flags: {
    external_checkout_enabled: boolean;
    play_distribution: boolean;
    app_store_distribution: boolean;
    store_distribution: boolean;
  };
  product_controls: {
    source: 'remote' | 'safe-default' | 'development-default';
    version?: string;
    expires_at?: string;
    flags: {
      checkout: boolean;
      playback: boolean;
      project_uploads: boolean;
      ai_chat: boolean;
    };
  };
  operational_events: DebugBundleOperationalEvent[];
};

type DebugBundleOptions = {
  now?: Date;
  readOperationalEvents?: () => Promise<unknown>;
  readProductFeatures?: () => Promise<unknown>;
};

const safeProductControls = async (
  reader: () => Promise<unknown>,
): Promise<DebugBundle['product_controls']> => {
  const fallback: DebugBundle['product_controls'] = {
    source: 'safe-default',
    flags: {
      checkout: false,
      playback: true,
      project_uploads: false,
      ai_chat: false,
    },
  };
  try {
    const value = await reader();
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      return fallback;
    }
    const raw = value as Record<string, unknown>;
    const source = String(raw.source);
    const flags =
      raw.flags && typeof raw.flags === 'object' && !Array.isArray(raw.flags)
        ? (raw.flags as Record<string, unknown>)
        : null;
    const keys = [
      'checkout',
      'playback',
      'project_uploads',
      'ai_chat',
    ] as const;
    if (
      (source !== 'remote' &&
        source !== 'safe-default' &&
        source !== 'development-default') ||
      !flags ||
      !keys.every(key => typeof flags[key] === 'boolean')
    ) {
      return fallback;
    }
    const version = String(raw.version || '').trim();
    const expiry = new Date(String(raw.expiresAt || ''));
    return {
      source,
      ...(version && /^[a-z0-9._-]{1,128}$/i.test(version) ? {version} : {}),
      ...(Number.isFinite(expiry.getTime())
        ? {expires_at: expiry.toISOString()}
        : {}),
      flags: {
        checkout: flags.checkout as boolean,
        playback: flags.playback as boolean,
        project_uploads: flags.project_uploads as boolean,
        ai_chat: flags.ai_chat as boolean,
      },
    };
  } catch {
    return fallback;
  }
};

const safePlatform = (): DebugBundle['app']['platform'] => {
  if (
    Platform.OS === 'android' ||
    Platform.OS === 'ios' ||
    Platform.OS === 'web'
  ) {
    return Platform.OS;
  }
  return 'other';
};

const safePositiveInteger = (value: unknown, maximum: number) => {
  const parsed = Number.parseInt(String(value), 10);
  return Number.isInteger(parsed) && parsed > 0 && parsed <= maximum
    ? parsed
    : undefined;
};

const safeAppVersion = (value: unknown) => {
  const version = String(value || '').trim();
  return /^\d+\.\d+\.\d+(?:[-+][a-z0-9.-]+)?$/i.test(version)
    ? version
    : 'unknown';
};

const safeBuildNumber = () => {
  if (Platform.OS === 'android') {
    return safePositiveInteger(
      appConfig.expo.android?.versionCode,
      2_147_483_647,
    );
  }
  if (Platform.OS === 'ios') {
    return safePositiveInteger(appConfig.expo.ios?.buildNumber, 2_147_483_647);
  }
  return undefined;
};

const safeErrorCode = (value: unknown, event: SafeEventName) => {
  const code = String(value || '')
    .trim()
    .toUpperCase();
  return SPECIFIC_ERROR_CODES.has(code) ? code : GENERIC_ERROR_CODE[event];
};

const sanitizeOperationalEvent = (
  value: unknown,
): DebugBundleOperationalEvent | undefined => {
  if (!value || typeof value !== 'object') return undefined;
  const candidate = value as Record<string, unknown>;
  if (!EVENT_NAMES.has(String(candidate.event))) return undefined;

  const event = String(candidate.event) as SafeEventName;
  const severity = String(candidate.severity);
  if (severity !== 'error' && severity !== 'fatal') return undefined;

  const occurredAt = new Date(String(candidate.occurred_at || ''));
  if (!Number.isFinite(occurredAt.getTime())) return undefined;

  const attempts = Number(candidate.attempts);
  return {
    event,
    severity,
    code: safeErrorCode(candidate.code, event),
    occurred_at: occurredAt.toISOString(),
    attempts:
      Number.isInteger(attempts) && attempts >= 0 ? Math.min(attempts, 99) : 0,
  };
};

const readSanitizedOperationalEvents = async (
  reader: () => Promise<unknown>,
) => {
  try {
    const raw = await reader();
    if (!Array.isArray(raw)) return [];
    return raw
      .map(sanitizeOperationalEvent)
      .filter((event): event is DebugBundleOperationalEvent => Boolean(event))
      .sort(
        (left, right) =>
          Date.parse(right.occurred_at) - Date.parse(left.occurred_at),
      )
      .slice(0, MAX_OPERATIONAL_EVENTS);
  } catch {
    return [];
  }
};

/** Builds a support bundle from the fields listed in this module. */
export const createDebugBundle = async (
  options: DebugBundleOptions = {},
): Promise<DebugBundle> => {
  const platform = safePlatform();
  const buildNumber = safeBuildNumber();
  const generatedAt = options.now || new Date();
  const operationalEvents = await readSanitizedOperationalEvents(
    options.readOperationalEvents || getOperationalDiagnosticsSnapshot,
  );
  const productControls = await safeProductControls(
    options.readProductFeatures || getProductFeatureDiagnosticsSnapshot,
  );

  return {
    schema_version: 1,
    generated_at: generatedAt.toISOString(),
    app: {
      version: safeAppVersion(appConfig.expo.version),
      ...(buildNumber ? {build_number: buildNumber} : {}),
      platform,
      ...(safePositiveInteger(Platform.Version, 255)
        ? {os_major: safePositiveInteger(Platform.Version, 255)}
        : {}),
      distribution_channel: DISTRIBUTION_CHANNEL,
    },
    feature_flags: {
      external_checkout_enabled: CAN_START_EXTERNAL_CHECKOUT,
      play_distribution: IS_PLAY_DISTRIBUTION,
      app_store_distribution: IS_APP_STORE_DISTRIBUTION,
      store_distribution: IS_STORE_DISTRIBUTION,
    },
    product_controls: productControls,
    operational_events: operationalEvents,
  };
};

export const formatDebugBundle = (bundle: DebugBundle) =>
  JSON.stringify(bundle, null, 2);

export const shareDebugBundle = async () => {
  const bundle = await createDebugBundle();
  await Share.share({
    title: 'معلومات دعم ركن',
    message: formatDebugBundle(bundle),
  });
  return bundle;
};
