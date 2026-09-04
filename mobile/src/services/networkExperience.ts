import {formatArabicCount} from '../constants/arabicFormatting';
import {serverNowMs} from '../utils/serverClock';

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
  const response = asRecord(root.response);
  const directData = asRecord(root.data);
  const responseData = asRecord(response.data);
  const codes = [root.code, directData.code, responseData.code]
    .map(value => String(value || '').trim().toUpperCase())
    .filter(Boolean);
  const messages = [root.message, directData.message, responseData.message]
    .map(value => String(value || '').trim().toLowerCase())
    .filter(Boolean);
  const directStatus = Number(root.status);
  const responseStatus = Number(response.status);
  const status =
    Number.isFinite(directStatus) && directStatus > 0
      ? directStatus
      : Number.isFinite(responseStatus) && responseStatus > 0
      ? responseStatus
      : 0;
  return {root, codes, messages, status};
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

/** Transport-only recovery for safe reads; semantic and write retries stay domain-owned. */
export const retryableReadTransportFailure = (error: unknown): boolean => {
  const {status} = networkFailureContext(error);
  const kind = networkFailureKind(error);
  return (
    kind === 'offline' ||
    kind === 'timeout' ||
    [425, 502, 503, 504].includes(status)
  );
};

/** Only availability failures may borrow a last-known-good read snapshot. */
export const transientReadFailureAllowsCache = (error: unknown): boolean =>
  ['offline', 'timeout', 'rate_limited', 'maintenance', 'server'].includes(
    networkFailureKind(error),
  );

const retryAfterSeconds = (error: unknown) => {
  const root = asRecord(error);
  const nestedResponse = asRecord(root.response);
  const response = Object.keys(nestedResponse).length ? nestedResponse : root;
  const headers = asRecord(response.headers);
  const getter = headers.get;
  const raw =
    typeof getter === 'function'
      ? getter.call(response.headers, 'retry-after')
      : headers['retry-after'] ?? headers['Retry-After'];
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    return Math.max(0, Math.ceil(raw));
  }
  const value = String(raw || '').trim();
  if (/^\d+$/.test(value)) return Math.max(0, Number(value));
  const date = Date.parse(value);
  return Number.isFinite(date)
    ? Math.max(0, Math.ceil((date - serverNowMs()) / 1000))
    : 0;
};

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
      return 'تغيّرت البيانات أثناء فتح الصفحة\nحدّثها ثم حاول مرة أخرى';
    case 'validation':
      return 'راجع البيانات المطلوبة\nثم حاول مرة أخرى';
    case 'rate_limited': {
      const wait = retryAfterSeconds(error);
      if (wait > 0 && wait <= 90) {
        return `طلبات كثيرة في وقت قصير\nحاول بعد ${formatWaitSeconds(wait)}`;
      }
      if (wait > 90) {
        return `طلبات كثيرة في وقت قصير\nحاول بعد ${formatWaitMinutes(
          Math.ceil(wait / 60),
        )}`;
      }
      return 'طلبات كثيرة في وقت قصير\nحاول بعد قليل';
    }
    case 'maintenance':
      return 'نجري تحديثًا قصيرًا\nحاول بعد قليل';
    case 'contract':
      return `تعذّر قراءة ${subject}\nحدّث التطبيق إن استمرت المشكلة`;
    default:
      return `تعذّر تحميل ${subject} الآن\nحاول مرة أخرى من نفس المكان`;
  }
};
