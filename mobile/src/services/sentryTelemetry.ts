import * as Sentry from '@sentry/react-native';
import {Platform} from 'react-native';
import appConfig from '../../app.json';

const dsn = String(process.env.EXPO_PUBLIC_SENTRY_DSN || '').trim();
const configuredEnvironment = String(
  process.env.EXPO_PUBLIC_SENTRY_ENVIRONMENT || '',
)
  .trim()
  .toLowerCase();
const environment = /^[a-z0-9._-]{1,32}$/.test(configuredEnvironment)
  ? configuredEnvironment
  : __DEV__
    ? 'development'
    : 'production';
const buildNumber =
  Platform.OS === 'ios'
    ? appConfig.expo.ios.buildNumber
    : appConfig.expo.android.versionCode;
const release = `com.rokn@${appConfig.expo.version}+${buildNumber}`;

const safeText = (value: unknown) =>
  String(value || '')
    .replace(/Bearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer <redacted>')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>')
    .replace(/https?:\/\/[^\s?#]+(?:[?#][^\s]*)?/gi, '<url>')
    .replace(/[A-Za-z0-9_-]{40,}/g, '<token>')
    .slice(0, 240);

const safeStack = (value: unknown) =>
  String(value || '')
    .replace(/Bearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer <redacted>')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>')
    .replace(/(https?:\/\/[^\s?#]+)[?#][^\s]*/gi, '$1')
    .replace(/[A-Za-z0-9_-]{80,}/g, '<token>')
    .slice(0, 8_000);

const safeRequestId = (value: unknown) => {
  const candidate = String(value || '').trim();
  return /^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/.test(candidate)
    ? candidate
    : undefined;
};

const safeOrderRef = (value: unknown) => {
  const candidate = String(value || '').trim();
  return /^[A-Za-z0-9_-]{8,100}$/.test(candidate) ? candidate : undefined;
};

const endpointWords = new Set([
  'api',
  'v1',
  'auth',
  'login',
  'courses',
  'course',
  'details',
  'wallet',
  'payments',
  'payment',
  'checkout',
  'status',
  'projects',
  'submissions',
  'portfolio',
  'media',
  'certificates',
  'notifications',
  'saved-folders',
  'lessons',
  'progress',
  'chat',
  'course-chat',
  'turns',
  'reconcile',
  'messages',
  'profile',
  'settings',
]);

const safeEndpoint = (value: unknown) => {
  const candidate = String(value || '').trim();
  if (!candidate) return undefined;
  let path = candidate.split(/[?#]/, 1)[0];
  try {
    path = new URL(candidate, 'https://rokn.app').pathname;
  } catch {
    // Axios may hold a relative URL. The query was already removed above.
  }
  const segments = path
    .split('/')
    .filter(Boolean)
    .slice(0, 6)
    .map(segment => {
      const normalized = segment.toLowerCase();
      return endpointWords.has(normalized) ? normalized : ':value';
    });
  return segments.length ? `/${segments.join('/')}` : undefined;
};

const readHeader = (headers: unknown, name: string): unknown => {
  if (!headers || typeof headers !== 'object') return undefined;
  const value = headers as Record<string, unknown> & {
    get?: (headerName: string) => unknown;
  };
  if (typeof value.get === 'function') return value.get(name);
  const exact = Object.keys(value).find(
    key => key.toLowerCase() === name.toLowerCase(),
  );
  return exact ? value[exact] : undefined;
};

export type RequestCorrelation = {
  endpoint?: string;
  requestId?: string;
  orderRef?: string;
};

export const requestCorrelationFor = (
  error: Error,
  supplied: RequestCorrelation = {},
) => {
  const shaped = error as Error & {
    config?: {url?: unknown; headers?: unknown};
    response?: {headers?: unknown};
  };
  return {
    endpoint: safeEndpoint(supplied.endpoint || shaped.config?.url),
    requestId: safeRequestId(
      supplied.requestId ||
        readHeader(shaped.response?.headers, 'x-request-id') ||
        readHeader(shaped.config?.headers, 'x-request-id'),
    ),
    orderRef: safeOrderRef(supplied.orderRef),
  };
};

let initialized = false;

export const initializeSentry = () => {
  if (initialized || !/^https:\/\//i.test(dsn)) return;
  initialized = true;
  Sentry.init({
    dsn,
    environment,
    release,
    sendDefaultPii: false,
    enableCaptureFailedRequests: false,
    maxBreadcrumbs: 0,
    integrations: defaults => [
      ...defaults.filter(item => item.name !== 'ReactNativeErrorHandlers'),
      Sentry.reactNativeErrorHandlersIntegration({
        onerror: false,
        onunhandledrejection: true,
        patchGlobalPromise: true,
      }),
    ],
    beforeSend: event => {
      delete event.request;
      delete event.extra;
      event.user = event.user?.id ? {id: String(event.user.id)} : undefined;
      if (event.message) event.message = safeText(event.message);
      for (const value of event.exception?.values || []) {
        if (value.value) value.value = safeText(value.value);
      }
      return event;
    },
  });
};

export const setSentryUserId = (value: unknown) => {
  if (!initialized) return;
  const id = String(value ?? '').trim();
  Sentry.setUser(id && id.length <= 128 ? {id} : null);
};

type SentryDiagnostic = {
  clientEventId: string;
  eventName: string;
  errorCode: string;
  source: string;
  fatal: boolean;
  endpoint?: string;
  requestId?: string;
  orderRef?: string;
};

/** The existing operational reporter owns JS error capture and dedupe. */
export const captureSentryDiagnostic = (
  error: Error,
  diagnostic: SentryDiagnostic,
) => {
  if (!initialized) return;
  const correlation = requestCorrelationFor(error, diagnostic);
  const safeError = new Error(diagnostic.errorCode);
  safeError.name = /^[A-Za-z][A-Za-z0-9_.-]{0,63}$/.test(error.name)
    ? error.name
    : 'Error';
  // Preserve frames and line numbers for source-map resolution. Truncating a
  // stack like learner-facing text leaves Sentry with the symptom but not the
  // failing callsite.
  const originalFrames = error.stack
    ? safeStack(error.stack).split('\n').slice(1).join('\n')
    : '';
  safeError.stack = originalFrames
    ? `${safeError.name}: ${diagnostic.errorCode}\n${originalFrames}`
    : undefined;
  Sentry.withScope(scope => {
    scope.setLevel(diagnostic.fatal ? 'fatal' : 'error');
    scope.setTag('event_name', diagnostic.eventName);
    scope.setTag('source', diagnostic.source.slice(0, 64));
    scope.setTag('error_code', diagnostic.errorCode);
    scope.setTag('client_event_id', diagnostic.clientEventId);
    if (correlation.endpoint) scope.setTag('endpoint', correlation.endpoint);
    if (correlation.requestId) {
      scope.setTag('request_id', correlation.requestId);
    }
    if (correlation.orderRef) scope.setTag('order_ref', correlation.orderRef);
    Sentry.captureException(safeError);
  });
};
