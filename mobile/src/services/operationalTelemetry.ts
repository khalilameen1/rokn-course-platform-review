import {NativeModules, Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import appConfig from '../../app.json';
import {roknApiUrl} from '../constants/apiBaseUrl';
import {
  enqueueDurableOutbox,
  flushDurableOutbox,
  readDurableOutbox,
} from './durableOutbox';
import {sha256Hex} from '../utils/sha256';
import {
  captureSentryDiagnostic,
  requestCorrelationFor,
} from './sentryTelemetry';
import {extractApiToken, peekSecureSession} from './secureSession';

const endpoint = `${roknApiUrl}client-events`;
const TELEMETRY_OUTBOX_KEY = '@rokn/client-events-outbox/v1';
const TELEMETRY_HISTORY_KEY = '@rokn/client-events-history/v1';
const MAX_TELEMETRY_EVENTS = 24;
const MAX_DIAGNOSTIC_HISTORY = 8;
const DIAGNOSTIC_HISTORY_TTL_MS = 7 * 24 * 60 * 60 * 1000;
const TELEMETRY_REQUEST_TIMEOUT_MS = 8_000;

type ErrorContext = {
  componentStack?: string | null;
  source?: string;
  fatal?: boolean;
  endpoint?: string;
  requestId?: string;
  orderRef?: string;
};

type ClientEventPayload = {
  client_event_id: string;
  event_name: string;
  severity: 'error' | 'fatal';
  app_version: string;
  build_number?: number;
  platform: string;
  os_major?: number;
  device_tier: 'unknown';
  screen_key: string;
  error_code: string;
  error_fingerprint?: string;
  endpoint?: string;
  request_id?: string;
  occurred_at: string;
};

let recentFingerprintSource = '';
let recentAt = 0;
let historyTail: Promise<void> = Promise.resolve();

type DiagnosticHistoryEvent = {
  id: string;
  event: string;
  severity: 'error' | 'fatal';
  code: string;
  occurred_at: string;
};

const validHistoryEvent = (value: unknown): value is DiagnosticHistoryEvent => {
  if (!value || typeof value !== 'object') return false;
  const item = value as Record<string, unknown>;
  return (
    typeof item.id === 'string' &&
    /^[0-9a-f-]{16,64}$/i.test(item.id) &&
    typeof item.event === 'string' &&
    /^[a-z0-9._-]{1,64}$/.test(item.event) &&
    (item.severity === 'error' || item.severity === 'fatal') &&
    typeof item.code === 'string' &&
    /^[A-Z0-9._-]{1,64}$/.test(item.code) &&
    typeof item.occurred_at === 'string' &&
    Number.isFinite(Date.parse(item.occurred_at))
  );
};

const readDiagnosticHistory = async (): Promise<DiagnosticHistoryEvent[]> => {
  try {
    const parsed = JSON.parse(
      (await AsyncStorage.getItem(TELEMETRY_HISTORY_KEY)) || '[]',
    );
    if (!Array.isArray(parsed)) return [];
    const cutoff = Date.now() - DIAGNOSTIC_HISTORY_TTL_MS;
    return parsed
      .filter(validHistoryEvent)
      .filter(item => Date.parse(item.occurred_at) >= cutoff)
      .slice(-MAX_DIAGNOSTIC_HISTORY);
  } catch {
    return [];
  }
};

const rememberDiagnostic = (payload: ClientEventPayload) => {
  const operation = async () => {
    const current = await readDiagnosticHistory();
    const next = [
      ...current.filter(item => item.id !== payload.client_event_id),
      {
        id: payload.client_event_id,
        event: payload.event_name,
        severity: payload.severity,
        code: payload.error_code,
        occurred_at: payload.occurred_at,
      },
    ].slice(-MAX_DIAGNOSTIC_HISTORY);
    await AsyncStorage.setItem(TELEMETRY_HISTORY_KEY, JSON.stringify(next));
  };
  const result = historyTail.then(operation, operation);
  historyTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const eventNameFor = (source: string) => {
  if (source.includes('video')) return 'video_failure';
  if (source.includes('course_chat')) return 'course_chat_failure';
  if (source.includes('checkout') || source.includes('payment')) {
    return 'payment_flow_failure';
  }
  if (source.includes('project')) return 'project_upload_failure';
  if (source.includes('auth')) return 'authentication_failure';
  if (source.includes('api')) return 'api_failure';
  return 'app_crash';
};

const errorCodeFor = (error: Error, source: string) => {
  const message = String(error.message || '').toUpperCase();
  if (
    (source.includes('auth') || source.includes('course_chat')) &&
    /^[A-Z0-9][A-Z0-9._-]{0,63}$/.test(message)
  ) {
    return message;
  }
  if (message.startsWith('VIDEO_BUFFER_TIMEOUT')) return 'VIDEO_BUFFER_TIMEOUT';
  if (message.startsWith('VIDEO_PLAYBACK')) return 'VIDEO_PLAYBACK';
  if (message.startsWith('PAYMENT_STATUS_TIMEOUT')) {
    return 'PAYMENT_STATUS_TIMEOUT';
  }
  const normalized = `${source}_${error.name || 'ERROR'}`
    .toUpperCase()
    .replace(/[^A-Z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64);
  return normalized || 'UNKNOWN_ERROR';
};

const fingerprintFor = (error: Error, source: string) => {
  const stackLocation = String(error.stack?.split('\n')[1] || '')
    .replace(/https?:\/\/\S+/gi, '<url>')
    .replace(/[A-Za-z0-9_-]{24,}/g, '<id>')
    .replace(/:\d+:\d+/g, ':#:#')
    .slice(0, 240);
  return `${source}|${error.name}|${errorCodeFor(
    error,
    source,
  )}|${stackLocation}`;
};

const osMajor = () => {
  const parsed = Number.parseInt(String(Platform.Version).split('.')[0], 10);
  return Number.isFinite(parsed) && parsed > 0 && parsed <= 255
    ? parsed
    : undefined;
};

/**
 * PII-free diagnostics use an allowlisted taxonomy and one-way fingerprint.
 * Free-form messages, URLs, stack traces, and session data are excluded;
 * reporting neither blocks nor retries a product action.
 */
export const reportClientError = (error: Error, context: ErrorContext = {}) => {
  if (__DEV__) return Promise.resolve();

  const source = context.source || 'javascript';
  const fingerprintSource = fingerprintFor(error, source);
  const now = Date.now();
  if (
    fingerprintSource === recentFingerprintSource &&
    now - recentAt < 60_000
  ) {
    return Promise.resolve();
  }
  recentFingerprintSource = fingerprintSource;
  recentAt = now;

  const buildNumber =
    Platform.OS === 'ios'
      ? Number(appConfig.expo.ios?.buildNumber)
      : Number(appConfig.expo.android?.versionCode);
  const eventId = Crypto.randomUUID();
  const correlation = requestCorrelationFor(error, context);
  const payload: ClientEventPayload = {
    client_event_id: eventId,
    event_name: eventNameFor(source),
    severity: context.fatal ? 'fatal' : 'error',
    app_version: appConfig.expo.version,
    ...(Number.isInteger(buildNumber) && buildNumber > 0
      ? {build_number: buildNumber}
      : {}),
    platform: Platform.OS,
    ...(osMajor() ? {os_major: osMajor()} : {}),
    device_tier: 'unknown',
    screen_key: source
      .toLowerCase()
      .replace(/[^a-z0-9._-]+/g, '_')
      .slice(0, 64),
    error_code: errorCodeFor(error, source),
    ...(correlation.endpoint ? {endpoint: correlation.endpoint} : {}),
    ...(correlation.requestId ? {request_id: correlation.requestId} : {}),
    occurred_at: new Date(now).toISOString(),
  };

  captureSentryDiagnostic(error, {
    clientEventId: eventId,
    eventName: payload.event_name,
    errorCode: payload.error_code,
    source,
    fatal: Boolean(context.fatal),
    endpoint: correlation.endpoint,
    requestId: correlation.requestId,
    orderRef: correlation.orderRef,
  });

  const task = (async () => {
    const errorFingerprint = sha256Hex(fingerprintSource);
    const durablePayload = {...payload, error_fingerprint: errorFingerprint};
    await rememberDiagnostic(durablePayload);
    await enqueueDurableOutbox({
      storageKey: TELEMETRY_OUTBOX_KEY,
      id: eventId,
      payload: durablePayload,
      maxItems: MAX_TELEMETRY_EVENTS,
    });
    await flushOperationalTelemetry();
  })().catch(() => {
    // Diagnostics are not an availability dependency.
  });
  void task;
  return task;
};

const deliverClientEvent = async (
  payload: ClientEventPayload,
): Promise<'ack' | 'retry' | 'drop'> => {
  const controller = new AbortController();
  const timeout = setTimeout(
    () => controller.abort(),
    TELEMETRY_REQUEST_TIMEOUT_MS,
  );
  try {
    const token = extractApiToken(peekSecureSession().session);
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(token ? {Authorization: `Bearer ${token}`} : {}),
      },
      body: JSON.stringify(payload),
      signal: controller.signal,
    });
    const status = Number(response.status || 0);
    if (response.ok || (status >= 200 && status < 300)) return 'ack';
    return status >= 400 && status < 500 ? 'drop' : 'retry';
  } catch {
    return 'retry';
  } finally {
    clearTimeout(timeout);
  }
};

export const flushOperationalTelemetry = () =>
  flushDurableOutbox<ClientEventPayload>({
    storageKey: TELEMETRY_OUTBOX_KEY,
    deliver: deliverClientEvent,
    maxBatch: 8,
    maxItems: MAX_TELEMETRY_EVENTS,
  });

type NativeExitEvent = {
  event_id?: string;
  error_code?: string;
  error_fingerprint?: string;
  occurred_at?: string;
};

export const bootstrapOperationalDiagnostics = async () => {
  const nativeDiagnostics = NativeModules?.RoknDiagnostics as
    | {
        consumePendingExitEvent?: () => Promise<NativeExitEvent | null>;
        acknowledgePendingExitEvent?: (eventId: string) => Promise<boolean>;
      }
    | undefined;
  try {
    const nativeEvent = await nativeDiagnostics?.consumePendingExitEvent?.();
    if (
      nativeEvent?.event_id &&
      /^[0-9a-f-]{36}$/i.test(nativeEvent.event_id) &&
      nativeEvent.error_code &&
      /^[A-Z0-9._-]{1,64}$/.test(nativeEvent.error_code) &&
      nativeEvent.error_fingerprint &&
      /^[a-f0-9]{64}$/.test(nativeEvent.error_fingerprint) &&
      nativeEvent.occurred_at &&
      Number.isFinite(Date.parse(nativeEvent.occurred_at))
    ) {
      const payload: ClientEventPayload = {
        client_event_id: nativeEvent.event_id,
        event_name: 'app_crash',
        severity: 'fatal',
        app_version: appConfig.expo.version,
        platform: Platform.OS,
        ...(osMajor() ? {os_major: osMajor()} : {}),
        device_tier: 'unknown',
        screen_key: 'native_exit',
        error_code: nativeEvent.error_code,
        error_fingerprint: nativeEvent.error_fingerprint,
        occurred_at: nativeEvent.occurred_at,
      };
      await rememberDiagnostic(payload);
      await enqueueDurableOutbox({
        storageKey: TELEMETRY_OUTBOX_KEY,
        id: payload.client_event_id,
        payload,
        maxItems: MAX_TELEMETRY_EVENTS,
      });
      await nativeDiagnostics
        ?.acknowledgePendingExitEvent?.(nativeEvent.event_id)
        .catch(() => false);
    }
  } catch {
    // Invalid native diagnostics do not affect application startup.
  }
  await flushOperationalTelemetry();
};

export const getOperationalDiagnosticsSnapshot = async () => {
  const history = await readDiagnosticHistory();
  const pending = await readDurableOutbox<ClientEventPayload>(
    TELEMETRY_OUTBOX_KEY,
  );
  const pendingById = new Map(pending.map(item => [item.id, item]));
  return history
    .map(item => ({
      event: item.event,
      severity: item.severity,
      code: item.code,
      occurred_at: item.occurred_at,
      attempts: pendingById.get(item.id)?.attempts || 0,
    }))
    .reverse();
};

export const operationalTelemetryQueueKey = TELEMETRY_OUTBOX_KEY;
export const operationalTelemetryHistoryKey = TELEMETRY_HISTORY_KEY;

type GlobalErrorHandler = (error: Error, isFatal?: boolean) => void;
type ErrorUtilsApi = {
  getGlobalHandler: () => GlobalErrorHandler | undefined;
  setGlobalHandler: (handler: GlobalErrorHandler) => void;
};

export const installGlobalErrorReporting = () => {
  const errorUtils = (globalThis as unknown as {ErrorUtils?: ErrorUtilsApi})
    .ErrorUtils;
  if (!errorUtils?.getGlobalHandler || !errorUtils?.setGlobalHandler) return;
  const previousHandler = errorUtils.getGlobalHandler();
  errorUtils.setGlobalHandler((error: Error, isFatal?: boolean) => {
    reportClientError(error, {source: 'global', fatal: Boolean(isFatal)});
    previousHandler?.(error, isFatal);
  });
};
