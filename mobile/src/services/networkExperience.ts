import {formatArabicCount} from '../constants/arabicFormatting';
import {serverNowMs} from '../utils/serverClock';
import {httpFailureOf} from '../constants/apiHttpError';
import {learnerCopy} from '../constants/learnerCopy';

export type NetworkFailureKind =
  | 'cancelled'
  | 'offline'
  | 'timeout'
  | 'unauthenticated'
  | 'forbidden'
  | 'missing'
  | 'conflict'
  | 'validation'
  | 'rate_limited'
  | 'maintenance'
  | 'server'
  | 'contract'
  | 'unknown';

const asRecord = (value: unknown): Record<string, unknown> =>
  typeof value === 'object' && value !== null
    ? (value as Record<string, unknown>)
    : {};

const networkFailureContext = (error: unknown) => {
  const root = asRecord(error);
  const http = httpFailureOf(error);
  const directData = asRecord(root.data);
  const codes = [root.code, directData.code, http.data.code]
    .map(value => String(value || '').trim().toUpperCase())
    .filter(Boolean);
  const messages = [root.message, directData.message, http.data.message]
    .map(value => String(value || '').trim().toLowerCase())
    .filter(Boolean);
  return {root, codes, messages, status: http.status};
};

const classifyNetworkFailure = (
  error: unknown,
  depth: number,
): NetworkFailureKind => {
  const {root, codes, messages, status} = networkFailureContext(error);
  const diagnostic = [...codes, ...messages].join(' ').toUpperCase();
  if (
    codes.some(code => code === 'ERR_CANCELED' || code === 'ABORT_ERR') ||
    /(?:^|\s)ACCOUNT_(?:CHANGED_DURING_REQUEST|SESSION_CHANGED)(?:\s|$)/.test(
      diagnostic,
    ) ||
    (!status &&
      messages.some(
        message =>
          message.includes('canceled') ||
          message.includes('cancelled') ||
          message.includes('aborted'),
      ))
  ) {
    return 'cancelled';
  }
  if (
    status === 408 ||
    (!status &&
      (codes.some(code =>
        ['ECONNABORTED', 'ETIMEDOUT', 'ESOCKETTIMEDOUT'].includes(code),
      ) ||
        messages.some(message => message.includes('timeout'))))
  ) {
    return 'timeout';
  }
  if (
    !status &&
    (codes.some(code =>
      [
        'ERR_NETWORK',
        'ERR_INTERNET_DISCONNECTED',
        'ENETDOWN',
        'ENETUNREACH',
        'EHOSTUNREACH',
        'ECONNRESET',
      ].includes(code),
    ) ||
      messages.some(message =>
        /network|internet|connection/.test(message),
      ))
  ) {
    return 'offline';
  }
  if (
    diagnostic.includes('CONTRACT_INVALID') ||
    diagnostic.includes('API_CONTRACT_INVALID')
  ) {
    return 'contract';
  }
  if (status === 401) return 'unauthenticated';
  if (status === 403) return 'forbidden';
  if (status === 404 || status === 410) return 'missing';
  if (status === 409) return 'conflict';
  if (status === 422) return 'validation';
  if (status === 429) return 'rate_limited';
  if (status === 503) return 'maintenance';
  if (status === 425) return 'server';
  if (status >= 500) return 'server';
  if (depth < 2 && root.cause && root.cause !== error) {
    const causedBy = classifyNetworkFailure(root.cause, depth + 1);
    if (causedBy !== 'unknown') return causedBy;
  }
  return 'unknown';
};

export const networkFailureKind = (error: unknown): NetworkFailureKind =>
  classifyNetworkFailure(error, 0);

/**
 * Transport-only recovery for safe reads; semantic and write retries stay
 * domain-owned. 503 is deliberately absent: Rokn answers maintenance/recovery
 * with 503 + `Retry-After: 300`, so a blind replay only adds load. The shared
 * client replays a 503/429 only when its Retry-After is short (see api.ts).
 */
export const retryableReadTransportFailure = (error: unknown): boolean => {
  const {status} = networkFailureContext(error);
  const kind = networkFailureKind(error);
  return (
    kind === 'offline' || kind === 'timeout' || [425, 502, 504].includes(status)
  );
};

/** Only availability failures may borrow a last-known-good read snapshot. */
export const transientReadFailureAllowsCache = (error: unknown): boolean =>
  ['offline', 'timeout', 'rate_limited', 'maintenance', 'server'].includes(
    networkFailureKind(error),
  );

/**
 * RFC 9110 §10.2.3 Retry-After: either delay-seconds or an HTTP-date. Dates
 * are compared with the server-corrected clock. Returns null when absent or
 * unparseable so callers can tell "no guidance" from "retry now".
 */
export const retryAfterMs = (error: unknown): number | null => {
  const headers = httpFailureOf(error).headers;
  const record = asRecord(headers);
  const getter = record.get;
  const raw =
    typeof getter === 'function'
      ? getter.call(headers, 'retry-after')
      : record['retry-after'] ?? record['Retry-After'];
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    return Math.max(0, Math.ceil(raw)) * 1000;
  }
  const value = String(raw ?? '').trim();
  if (!value) return null;
  if (/^\d+$/.test(value)) return Number(value) * 1000;
  const date = Date.parse(value);
  return Number.isFinite(date) ? Math.max(0, date - serverNowMs()) : null;
};

const retryAfterSeconds = (error: unknown) =>
  Math.ceil((retryAfterMs(error) ?? 0) / 1000);

const formatWaitSeconds = (value: number) =>
  formatArabicCount(value, {
    one: 'ثانية',
    two: 'ثانيتين',
    few: 'ثوانٍ',
    many: 'ثانية',
    other: 'ثانية',
  });

const formatWaitMinutes = (value: number) =>
  formatArabicCount(value, {
    one: 'دقيقة',
    two: 'دقيقتين',
    few: 'دقائق',
    many: 'دقيقة',
    other: 'دقيقة',
  });

export const friendlyNetworkMessage = (error: unknown, subject = 'المحتوى') => {
  switch (networkFailureKind(error)) {
    case 'cancelled':
      return '';
    case 'offline':
      return `لا يوجد اتصال الآن\nتحقق من الإنترنت ثم افتح ${subject} مرة أخرى`;
    case 'timeout':
      return `الاتصال بطيء\nحاول تحميل ${subject} مرة أخرى`;
    case 'server':
      return `الخدمة مشغولة الآن\nحاول فتح ${subject} بعد لحظات`;
    case 'unauthenticated':
      return 'انتهى تسجيل الدخول\nسجّل الدخول من جديد';
    case 'forbidden':
      return 'هذا المحتوى غير متاح لحسابك';
    case 'missing':
      return `${subject} غير متاح الآن`;
    case 'conflict':
      return `تغيّرت البيانات أثناء فتح الصفحة\nحدّثها و${learnerCopy.retry}`;
    case 'validation':
      return `راجع البيانات المطلوبة\nو${learnerCopy.retry}`;
    case 'rate_limited': {
      const wait = retryAfterSeconds(error);
      if (wait > 0 && wait <= 90) {
        return `طلبات كثيرة في وقت قصير\n${learnerCopy.retry} بعد ${formatWaitSeconds(wait)}`;
      }
      if (wait > 90) {
        return `طلبات كثيرة في وقت قصير\n${learnerCopy.retry} بعد ${formatWaitMinutes(
          Math.ceil(wait / 60),
        )}`;
      }
      return `طلبات كثيرة في وقت قصير\n${learnerCopy.retryLater}`;
    }
    case 'maintenance':
      return `نجري تحديثًا قصيرًا\n${learnerCopy.retryLater}`;
    case 'contract':
      return `تعذّر قراءة ${subject}\nحدّث التطبيق إن استمرت المشكلة`;
    default:
      return `تعذّر تحميل ${subject} الآن\n${learnerCopy.retry} من نفس المكان`;
  }
};
