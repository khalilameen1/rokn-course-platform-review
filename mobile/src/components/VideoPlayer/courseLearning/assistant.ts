import {publicRequest, type RoknRequestConfig} from '../../../constants/api';
import {isProductFeatureEnabled} from '../../../services/productFeatures';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {openExternalUrlOnce} from '../../../services/systemActions';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import {includesCourseAssistant} from '../courseEntitlements';
import type {ChatMessage, CourseLearningData, CourseReel} from '../types';
import {asArray, asRecord, valueAsBoolean, valueAsString} from './shared';

// The send endpoint only admits the durable turn; the provider work runs on
// its own queue.  A slow web response must not keep the composer frozen for a
// full provider timeout.  After this bound the client reconciles the same
// immutable request id through the turn endpoint, so there is no second debit.
const COURSE_CHAT_REQUEST_TIMEOUT_MS = 15_000;
// The chat hook already owns a bounded polling loop. Letting the shared GET
// interceptor replay every status read turns a short provider wait into
// several minutes during an origin outage and cannot improve correctness: the
// next poll reads the same durable turn. Keep each status probe cheap.
export const COURSE_CHAT_STATUS_TIMEOUT_MS = 3_500;
const assistantAttachmentOpenFlights = new Map<string, Promise<void>>();

export type CourseAssistantTurnResponse = {
  text: string;
  offline: boolean;
  blocked?: boolean;
  unavailable?: boolean;
  clientRequestId?: string;
  turnStatus?: ChatMessage['deliveryStatus'];
  code?: string;
  canRetry?: boolean;
  retryAfterSeconds?: number;
  pollWindowSeconds?: number;
  partial?: boolean;
};

const COURSE_CHAT_TURN_STATUSES = new Set([
  'queued',
  'sent',
  'streaming',
  'completed',
  'failed',
  'cancelled',
]);

const COURSE_CHAT_BLOCK_CODES = new Set([
  'chat_upgrade_required',
  'chat_plan_limit_reached',
  'course_not_available',
  'course_access_required',
  'chat_disabled_for_course',
]);

const mapCourseAssistantTurn = (
  payload: unknown,
  expectedClientRequestId?: string,
): CourseAssistantTurnResponse => {
  const responsePayload = asRecord(payload);
  const data = asRecord(responsePayload.data);
  const code = valueAsString(responsePayload.code).toLowerCase();
  const responseClientRequestId = valueAsString(data.client_request_id);
  if (
    expectedClientRequestId &&
    responseClientRequestId &&
    responseClientRequestId !== expectedClientRequestId
  ) {
    return {
      text: 'نتحقق من إجابتك الآن',
      offline: false,
      unavailable: false,
      clientRequestId: expectedClientRequestId,
      turnStatus: 'queued',
      code: 'chat_answer_in_progress',
      retryAfterSeconds: 1,
    };
  }

  const rawStatus = valueAsString(data.turn_status).toLowerCase();
  const blocked = COURSE_CHAT_BLOCK_CODES.has(code);
  const responseText =
    (blocked && code === 'chat_plan_limit_reached'
      ? 'استخدمت مساحة الأسئلة في فئتك الحالية\nيمكنك زيادتها بدفع فرق الفئة فقط'
      : cleanUnicodeText(
          valueAsString(data.message, valueAsString(data.reply)),
        )) || '';
  const turnStatus = COURSE_CHAT_TURN_STATUSES.has(rawStatus)
    ? (rawStatus as ChatMessage['deliveryStatus'])
    : code === 'chat_answer_in_progress'
    ? 'queued'
    : blocked || data.unavailable === true || code !== ''
    ? 'failed'
    : responseText
    ? 'completed'
    : 'failed';

  if (
    (turnStatus === 'completed' && !responseText) ||
    (!rawStatus && !code && !responseText)
  ) {
    return {
      text: 'لم تكتمل الإجابة\nحاول مرة أخرى',
      offline: false,
      unavailable: true,
      clientRequestId: expectedClientRequestId || responseClientRequestId,
      turnStatus: 'failed',
      code: 'chat_response_invalid',
    };
  }

  return {
    text:
      responseText ||
      (turnStatus === 'cancelled'
        ? 'تم إيقاف الرد'
        : turnStatus === 'failed'
        ? 'لم تكتمل الإجابة\nحاول مرة أخرى'
        : 'الرد قيد التجهيز\nسيظهر خلال لحظات'),
    offline: false,
    blocked,
    unavailable: !blocked && data.unavailable === true,
    clientRequestId: expectedClientRequestId || responseClientRequestId,
    turnStatus,
    code: code || (turnStatus === 'failed' ? 'chat_turn_failed' : undefined),
    canRetry: typeof data.can_retry === 'boolean' ? data.can_retry : undefined,
    retryAfterSeconds: Math.max(0, Number(data.retry_after_seconds) || 0),
    pollWindowSeconds: Math.max(0, Number(data.poll_window_seconds) || 0),
    partial: valueAsBoolean(data.partial),
  };
};

const requireServerCourseId = (value: unknown): string => {
  const courseId = String(value ?? '').trim();
  if (!/^\d+$/.test(courseId)) throw new Error('COURSE_ID_INVALID');
  return courseId;
};

export const courseIncludesAssistant = (
  course: Pick<CourseLearningData, 'accessType' | 'chatAvailable'>,
) => includesCourseAssistant(course);

export const loadCourseAssistantHistory = async (
  courseId: string,
  lessonId?: string,
): Promise<ChatMessage[]> => {
  const serverCourseId = requireServerCourseId(courseId);
  const response = await publicRequest.get('course-chat/messages', {
    params: {
      course_id: serverCourseId,
      lesson_id: lessonId,
      per_page: 20,
    },
  });
  return asArray<Record<string, unknown>>(
    asRecord(response?.data?.data).messages,
  ).flatMap(message => {
    const role = valueAsString(message.role);
    const status = valueAsString(message.delivery_status);
    const id = valueAsString(message.id);
    if (
      !id ||
      !['user', 'assistant'].includes(role) ||
      ![
        'queued',
        'sent',
        'streaming',
        'completed',
        'failed',
        'cancelled',
      ].includes(status)
    )
      return [];
    const createdAt = Date.parse(valueAsString(message.created_at));
    const canRetry =
      typeof message.can_retry === 'boolean' ? message.can_retry : undefined;
    const text =
      cleanUnicodeText(valueAsString(message.text)) ||
      (role === 'assistant' && status === 'failed'
        ? canRetry
          ? 'لم تكتمل الإجابة\nحاول مرة أخرى'
          : 'تعذّر تأكيد نتيجة الإجابة السابقة'
        : role === 'assistant' && status === 'cancelled'
        ? 'تم إيقاف الرد'
        : '');
    const attachments = asArray<Record<string, unknown>>(
      message.attachments,
    ).map(file => ({
      uri: '',
      name: cleanUnicodeText(valueAsString(file.name, 'مرفق'), false),
      type: valueAsString(file.mime_type, 'application/octet-stream'),
      size: Number(file.size_bytes) || undefined,
      uploadId: valueAsString(file.id),
      serverId: valueAsString(file.id),
      downloadUrl: valueAsString(file.download_url) || undefined,
      downloadExpiresAt:
        valueAsString(file.download_url_expires_at) || undefined,
    }));
    return [
      {
        id,
        role: role as ChatMessage['role'],
        text,
        createdAt: Number.isFinite(createdAt) ? createdAt : Date.now(),
        clientRequestId: valueAsString(message.client_request_id) || undefined,
        deliveryStatus: status as ChatMessage['deliveryStatus'],
        errorCode: valueAsString(message.error_code) || undefined,
        canRetry,
        retryAfterSeconds: Math.max(
          0,
          Number(message.retry_after_seconds) || 0,
        ),
        contextEligible: valueAsBoolean(message.context_eligible),
        attachments,
      },
    ];
  });
};

const openCourseAssistantAttachmentInternal = async (
  file: import('../types').ChatAttachmentDraft,
  boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
) => {
  let candidate = file;
  const expiresAt = Date.parse(String(candidate.downloadExpiresAt || ''));
  if (
    !candidate.downloadUrl ||
    !Number.isFinite(expiresAt) ||
    expiresAt <= Date.now() + 15000
  ) {
    if (!candidate.serverId) throw new Error('CHAT_ATTACHMENT_UNAVAILABLE');
    const response = await publicRequest.get(
      `ai-input-attachments/${encodeURIComponent(candidate.serverId)}`,
    );
    assertAccountSessionBoundary(boundary);
    const refreshed = asRecord(asRecord(response?.data).data);
    candidate = {
      ...candidate,
      downloadUrl: valueAsString(refreshed.download_url) || undefined,
      downloadExpiresAt:
        valueAsString(refreshed.download_url_expires_at) || undefined,
    };
  }
  if (!candidate.downloadUrl) throw new Error('CHAT_ATTACHMENT_UNAVAILABLE');
  assertAccountSessionBoundary(boundary);
  await openExternalUrlOnce(
    candidate.downloadUrl,
    undefined,
    `course-chat-attachment:${
      file.serverId || file.uploadId || file.downloadUrl || ''
    }`,
  );
};

export const openCourseAssistantAttachment = (
  file: import('../types').ChatAttachmentDraft,
) =>
  (async () => {
    const boundary = await captureAccountSessionBoundary();
    const attachmentKey = String(
      file.serverId || file.uploadId || file.downloadUrl || '',
    ).trim();
    if (!attachmentKey) {
      return Promise.reject(new Error('CHAT_ATTACHMENT_UNAVAILABLE'));
    }
    const key = `${boundary.scope}:${attachmentKey}`;
    const existing = assistantAttachmentOpenFlights.get(key);
    if (existing) return existing;
    const flight = openCourseAssistantAttachmentInternal(
      file,
      boundary,
    ).finally(() => {
      if (assistantAttachmentOpenFlights.get(key) === flight) {
        assistantAttachmentOpenFlights.delete(key);
      }
    });
    assistantAttachmentOpenFlights.set(key, flight);
    return flight;
  })();

export const pollCourseAssistantTurn = async (
  clientRequestId: string,
): Promise<CourseAssistantTurnResponse> => {
  let response: Awaited<ReturnType<typeof publicRequest.get>>;
  try {
    response = await publicRequest.get(
      `course-chat/turns/${encodeURIComponent(clientRequestId)}`,
      {
        timeout: COURSE_CHAT_STATUS_TIMEOUT_MS,
        // `onRejectedResponse` retries only when this counter is below the
        // shared ladder length. A status probe has its own outer retry loop,
        // so mark the inner ladder as already consumed.
        roknNetworkRetryCount: Number.MAX_SAFE_INTEGER,
        roknNetworkRetryDeadlineAt: Date.now() + COURSE_CHAT_STATUS_TIMEOUT_MS,
      } as RoknRequestConfig,
    );
  } catch (error: unknown) {
    const failure = asRecord(error);
    const errorResponse = asRecord(failure.response);
    const status = Number(errorResponse.status || failure.status || 0);
    if (status === 404 || status === 410) {
      return {
        text: 'لم يصل السؤال إلى Rokn AI\nأرسله مرة أخرى',
        offline: false,
        unavailable: true,
        clientRequestId,
        turnStatus: 'failed',
        code: 'chat_turn_not_found',
        canRetry: true,
      };
    }
    // A status read cannot invalidate a turn already accepted by the server.
    // Keep the same logical id and let the bounded polling loop recover after
    // a mobile-network hand-off instead of showing a false failed answer.
    return {
      text: 'الرد قيد التجهيز\nسيظهر عند عودة الاتصال',
      offline: true,
      unavailable: true,
      clientRequestId,
      turnStatus: 'queued',
      code: 'chat_answer_in_progress',
      retryAfterSeconds: 5,
    };
  }
  return mapCourseAssistantTurn(asRecord(response).data, clientRequestId);
};

export const cancelCourseAssistantTurn = async (
  clientRequestId: string,
): Promise<boolean> => {
  try {
    await publicRequest.delete(
      `course-chat/turns/${encodeURIComponent(clientRequestId)}`,
      {timeout: 12000},
    );
    return true;
  } catch {
    return false;
  }
};

export const askCourseAssistant = async ({
  course,
  reel,
  message,
  clientRequestId,
  onRequestStart,
  attachmentIds = [],
}: {
  course: CourseLearningData;
  reel?: CourseReel;
  message: string;
  clientRequestId?: string;
  onRequestStart?: () => void;
  attachmentIds?: string[];
}): Promise<CourseAssistantTurnResponse> => {
  if (!courseIncludesAssistant(course)) {
    return {
      text: 'Rokn AI غير مشمول في وصولك الحالي',
      offline: true,
      blocked: true,
      code: 'chat_upgrade_required',
    };
  }
  const courseId = requireServerCourseId(course.id);
  if (!(await isProductFeatureEnabled('ai_chat'))) {
    return {
      text: 'Rokn AI متوقف مؤقتًا للصيانة\nتقدمك محفوظ\nحاول لاحقًا',
      offline: true,
      unavailable: true,
      code: 'ai_feature_unavailable',
    };
  }
  try {
    onRequestStart?.();
    const response = await publicRequest.post(
      `courses/${courseId}/chat`,
      {
        message,
        client_request_id: clientRequestId,
        lesson_id: reel?.lessonId,
        attachment_ids: attachmentIds,
      },
      {timeout: COURSE_CHAT_REQUEST_TIMEOUT_MS},
    );
    return mapCourseAssistantTurn(response?.data, clientRequestId);
  } catch (error: unknown) {
    const failure = asRecord(error);
    const response = asRecord(failure.response);
    const status = Number(response.status || failure.status || 0);
    const errorCode = valueAsString(
      asRecord(failure.data).code,
      valueAsString(asRecord(response.data).code),
    ).toLowerCase();
    if (errorCode === 'chat_upgrade_required') {
      return {
        text: 'Rokn AI غير مشمول في المنحة\nيمكنك إضافته بالترقية',
        offline: false,
        blocked: true,
        code: errorCode,
      };
    }
    if (errorCode === 'chat_plan_limit_reached') {
      return {
        text: 'استخدمت مساحة الأسئلة في فئتك الحالية\nيمكنك زيادتها بدفع فرق الفئة فقط',
        offline: false,
        blocked: true,
        code: errorCode,
      };
    }
    if (
      [
        'course_not_available',
        'course_access_required',
        'chat_disabled_for_course',
      ].includes(errorCode)
    ) {
      return {
        text:
          errorCode === 'course_not_available'
            ? 'هذا الكورس غير متاح الآن'
            : errorCode === 'course_access_required'
            ? 'افتح الكورس أولًا لاستخدام Rokn AI'
            : 'Rokn AI غير متاح في هذا الكورس',
        offline: false,
        blocked: true,
        code: errorCode,
        clientRequestId,
        turnStatus: 'failed',
      };
    }
    if (errorCode === 'chat_daily_limit_reached') {
      return {
        text: 'اكتملت أسئلة اليوم\nيمكنك المتابعة غدًا',
        offline: false,
        unavailable: true,
        code: errorCode,
        clientRequestId,
        turnStatus: 'failed',
      };
    }
    if (errorCode === 'chat_rate_limited') {
      return {
        text: 'انتظر قليلًا\nثم أرسل سؤالك مرة أخرى',
        offline: false,
        unavailable: true,
        code: errorCode,
        clientRequestId,
        turnStatus: 'failed',
      };
    }

    // A timeout or server/gateway disconnect does not prove that the paid
    // turn was rejected. Keep the immutable request id and recover through
    // the status endpoint; resubmitting a fresh turn here could debit the
    // learner and call the provider twice for one visible question.
    if (clientRequestId && (status === 0 || status === 408 || status >= 500)) {
      return {
        text: 'نجهز إجابتك الآن\nستظهر خلال لحظات',
        offline: status === 0,
        unavailable: false,
        clientRequestId,
        turnStatus: 'queued',
        code: 'chat_answer_in_progress',
        retryAfterSeconds: 2,
      };
    }

    return {
      text: 'Rokn AI غير متاح الآن\nأكمل المقطع ومكانك محفوظ\nحاول لاحقًا',
      offline: true,
      unavailable: true,
      clientRequestId,
      code:
        errorCode ||
        (valueAsString(failure.code).toUpperCase() === 'ECONNABORTED'
          ? 'client_timeout'
          : 'network_unavailable'),
      turnStatus: 'failed',
    };
  }
};

export const uploadCourseAssistantAttachment = async ({
  courseId,
  file,
}: {
  courseId: string;
  file: import('../types').ChatAttachmentDraft;
}): Promise<string> => {
  const serverCourseId = requireServerCourseId(courseId);
  const body = new FormData();
  body.append('client_upload_id', file.uploadId);
  body.append('attachment', {
    uri: file.uri,
    name: file.name,
    type: file.type || 'application/octet-stream',
  } as unknown as Blob);
  const response = await publicRequest.post(
    `courses/${serverCourseId}/chat/attachments`,
    body,
    {headers: {'Content-Type': 'multipart/form-data'}, timeout: 45000},
  );
  const id = valueAsString(response?.data?.data?.id);
  if (!id) throw new Error('attachment_upload_failed');
  return id;
};
