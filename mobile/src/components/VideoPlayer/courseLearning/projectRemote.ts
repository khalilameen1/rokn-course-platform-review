import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {openExternalUrlOnce} from '../../../services/systemActions';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import type {
  ChatAttachmentDraft,
  ProjectFeedbackThread,
  ProjectReportStatus,
  ProjectStatus,
} from '../types';
import {
  asRecord,
  type DataRecord,
  valueAsBoolean,
  valueAsString,
} from './shared';
import {mapProjectFeedbackThread} from './projectFeedbackMapping';
import {reviewFeedbackForStatus} from './projectJourney';
import {publishCourseRevisionChange} from './playbackRevision';

const PUBLIC_ID =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const attachmentFlights = new Map<string, Promise<void>>();

const numericProjectId = (value: string) => {
  const id = String(value).trim();
  if (!/^\d+$/.test(id) || Number(id) <= 0)
    throw new Error('INVALID_PROJECT_ID');
  return id;
};

const publicId = (value: string, field: string) => {
  const id = String(value).trim().toLowerCase();
  if (!PUBLIC_ID.test(id)) throw new Error(`INVALID_${field}_ID`);
  return id;
};

const payloadFrom = (response: unknown): DataRecord => {
  // publicRequest returns AxiosResponse, not the API body. Project endpoints
  // put their business payload inside the body's explicit `data` envelope.
  const body = asRecord(asRecord(response).data);
  return asRecord(body.data);
};

export const parseProjectSubmissionStatus = (
  value: unknown,
  hasSubmission = false,
): ProjectStatus => {
  const status = valueAsString(value).trim().toLowerCase();
  if (
    status === 'draft' ||
    status === 'evaluating' ||
    status === 'review_unavailable' ||
    status === 'passed' ||
    status === 'needs_changes'
  ) {
    return status;
  }
  if (!hasSubmission && !status) return 'draft';
  throw new Error('PROJECT_SUBMISSION_CONTRACT_INVALID');
};

export const parseProjectReportStatus = (
  value: unknown,
): ProjectReportStatus => {
  const status = valueAsString(value).trim().toLowerCase();
  if (
    status === 'not_included' ||
    status === 'not_requested' ||
    status === 'queued' ||
    status === 'ready' ||
    status === 'failed'
  ) {
    return status;
  }
  throw new Error('PROJECT_REPORT_CONTRACT_INVALID');
};

export const loadProjectFeedbackThread = async (
  projectId: string,
  threadId?: string,
): Promise<ProjectFeedbackThread | null> => {
  const boundary = await captureAccountSessionBoundary();
  const response = threadId
    ? await publicRequest.get(
        `project-feedback-threads/${publicId(threadId, 'PROJECT_THREAD')}`,
      )
    : await publicRequest.get(`projects/${numericProjectId(projectId)}`);
  assertAccountSessionBoundary(boundary);
  const payload = payloadFrom(response);
  return mapProjectFeedbackThread(
    threadId ? payload : asRecord(payload.latest_submission).feedback_thread,
  );
};

export const loadProjectResolution = async (projectId: string) => {
  const boundary = await captureAccountSessionBoundary();
  try {
    const response = await publicRequest.get(
      `projects/${numericProjectId(projectId)}`,
    );
    assertAccountSessionBoundary(boundary);
    const submission = asRecord(payloadFrom(response).latest_submission);
    return projectResolutionFromSubmission(submission);
  } catch (error) {
    assertAccountSessionBoundary(boundary);
    // Staged publication archives project IDs too. Let the existing course
    // reload owner replace the map without resubmitting this accepted attempt.
    publishCourseRevisionChange(error);
    throw error;
  }
};

const projectResolutionFromSubmission = (submission: DataRecord) => {
  if (!submission.id) throw new Error('PROJECT_SUBMISSION_CONTRACT_INVALID');
  const status = parseProjectSubmissionStatus(
    submission.submission_status,
    true,
  );

  return {
    status,
    canSubmit: valueAsBoolean(submission.can_submit),
    canContinue: valueAsBoolean(submission.can_continue),
    feedbackLevel: (['report', 'enhanced'].includes(
      valueAsString(submission.feedback_level),
    )
      ? valueAsString(submission.feedback_level)
      : 'pass_only') as 'pass_only' | 'report' | 'enhanced',
    reportEnabled: valueAsBoolean(submission.report_enabled),
    reportStatus: parseProjectReportStatus(submission.report_status),
    replyEnabled: valueAsBoolean(submission.reply_enabled),
    reviewFeedback: reviewFeedbackForStatus(
      status,
      valueAsString(submission.feedback),
    ),
    canRetryReport: valueAsBoolean(submission.can_retry_report),
    canRetryReview: submission.can_retry_review === true,
    reviewRetryEndpoint:
      valueAsString(submission.review_retry_endpoint) || undefined,
    reviewFailureCategory:
      valueAsString(submission.review_failure_category) || undefined,
    reportRetryEndpoint:
      valueAsString(submission.report_retry_endpoint) || undefined,
    feedbackThread: mapProjectFeedbackThread(submission.feedback_thread),
  };
};

export type ProjectResolution = ReturnType<
  typeof projectResolutionFromSubmission
>;

export const retryProjectReview = async (
  endpoint: string,
): Promise<ProjectResolution> => {
  const route = endpoint.replace(/^\/?api\/v1\//, '');
  const match = /^project-submissions\/([^/]+)\/review\/retry$/.exec(route);
  if (!match) throw new Error('INVALID_PROJECT_REVIEW_RETRY_ENDPOINT');
  const submissionId = publicId(match[1], 'PROJECT_SUBMISSION');
  const boundary = await captureAccountSessionBoundary();
  const response = await publicRequest.post(route, undefined, {timeout: 30000});
  assertAccountSessionBoundary(boundary);
  const payload = payloadFrom(response);
  if (valueAsString(payload.id).toLowerCase() !== submissionId) {
    throw new Error('PROJECT_SUBMISSION_CONTRACT_INVALID');
  }
  return projectResolutionFromSubmission(payload);
};

export const retryProjectReport = async (
  endpoint: string,
): Promise<ProjectResolution> => {
  const route = endpoint.replace(/^\/?api\/v1\//, '');
  const match = /^project-submissions\/([^/]+)\/report\/retry$/.exec(route);
  if (!match) throw new Error('INVALID_PROJECT_REPORT_RETRY_ENDPOINT');
  const submissionId = publicId(match[1], 'PROJECT_SUBMISSION');
  const boundary = await captureAccountSessionBoundary();
  const response = await publicRequest.post(route);
  assertAccountSessionBoundary(boundary);
  const payload = payloadFrom(response);
  if (valueAsString(payload.id).toLowerCase() !== submissionId) {
    throw new Error('PROJECT_SUBMISSION_CONTRACT_INVALID');
  }
  return projectResolutionFromSubmission(payload);
};

export const watchProjectResolution = <T extends {status: ProjectStatus}>({
  projectId,
  resolve,
  onResolution,
  onExhausted,
  isActive = () => true,
  maxAttempts = Number.POSITIVE_INFINITY,
  initialDelayMs = 0,
}: {
  projectId: string;
  resolve: (projectId: string) => Promise<T | null>;
  onResolution: (resolution: T) => void;
  onExhausted?: () => void;
  isActive?: () => boolean;
  maxAttempts?: number;
  initialDelayMs?: number;
}): (() => void) => {
  let cancelled = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let attempt = 0;
  const jitter =
    Array.from(projectId).reduce(
      (sum, character) => sum + character.charCodeAt(0),
      0,
    ) % 31;

  const schedule = (delay: number) => {
    timer = setTimeout(
      () => void poll(),
      Math.round(delay * (0.85 + jitter / 100)),
    );
  };
  const poll = async () => {
    if (cancelled || !isActive()) return;
    attempt += 1;
    try {
      const resolution = await resolve(projectId);
      if (cancelled || !isActive()) return;
      if (resolution) {
        onResolution(resolution);
        if (
          resolution.status === 'passed' ||
          resolution.status === 'review_unavailable' ||
          resolution.status === 'needs_changes'
        ) {
          return;
        }
      }
    } catch (error) {
      if (cancelled || !isActive()) return;
      if (
        error instanceof Error &&
        [
          'PROJECT_SUBMISSION_CONTRACT_INVALID',
          'PROJECT_REPORT_CONTRACT_INVALID',
        ].includes(error.message)
      ) {
        onExhausted?.();
        return;
      }
    }
    if (cancelled || !isActive()) return;
    if (attempt >= maxAttempts) {
      onExhausted?.();
      return;
    }
    if (!cancelled && isActive()) {
      // A delayed queue or a temporary outage is not a project decision.
      // Keep the visible review fresh instead of silently abandoning it after
      // five minutes, but reduce background read traffic during long waits.
      schedule(
        attempt >= 30
          ? 30000
          : Math.min(12000, 2200 * Math.pow(1.4, Math.min(7, attempt - 1))),
      );
    }
  };

  if (initialDelayMs > 0) schedule(initialDelayMs);
  else void poll();
  return () => {
    cancelled = true;
    if (timer) clearTimeout(timer);
  };
};

const openAttachment = async (
  input: {
    projectId: string;
    threadId?: string;
    file: ChatAttachmentDraft;
  },
  boundary: AccountSessionBoundary,
) => {
  let candidate = input.file;
  const expiresAt = Date.parse(String(candidate.downloadExpiresAt || ''));
  if (
    !candidate.downloadUrl ||
    !Number.isFinite(expiresAt) ||
    expiresAt <= Date.now() + 15000
  ) {
    if (!candidate.serverId) throw new Error('PROJECT_ATTACHMENT_UNAVAILABLE');
    const response = await publicRequest.get(
      `ai-input-attachments/${publicId(candidate.serverId, 'ATTACHMENT')}`,
    );
    assertAccountSessionBoundary(boundary);
    const payload = payloadFrom(response);
    candidate = {
      ...candidate,
      downloadUrl: valueAsString(payload.download_url) || undefined,
      downloadExpiresAt:
        valueAsString(payload.download_url_expires_at) || undefined,
    };
  }
  if (!candidate.downloadUrl) throw new Error('PROJECT_ATTACHMENT_UNAVAILABLE');
  assertAccountSessionBoundary(boundary);
  await openExternalUrlOnce(
    candidate.downloadUrl,
    undefined,
    `project-input-attachment:${
      candidate.serverId || candidate.uploadId || candidate.downloadUrl
    }`,
  );
};

export const openProjectInputAttachment = (input: {
  projectId: string;
  threadId?: string;
  file: ChatAttachmentDraft;
}) =>
  (async () => {
    const boundary = await captureAccountSessionBoundary();
    const attachmentId = String(
      input.file.serverId ||
        input.file.uploadId ||
        input.file.downloadUrl ||
        '',
    ).trim();
    if (!attachmentId) throw new Error('PROJECT_ATTACHMENT_UNAVAILABLE');
    const key = [
      boundary.scope,
      input.projectId,
      input.threadId || 'submission',
      attachmentId,
    ].join(':');
    const existing = attachmentFlights.get(key);
    if (existing) return existing;
    const flight = openAttachment(input, boundary).finally(() => {
      if (attachmentFlights.get(key) === flight) attachmentFlights.delete(key);
    });
    attachmentFlights.set(key, flight);
    return flight;
  })();

export const sendProjectFeedbackMessage = async (
  threadId: string,
  message: string,
  clientRequestId = secureRandomUuid(),
  attachmentIds: string[] = [],
): Promise<ProjectFeedbackThread> => {
  const boundary = await captureAccountSessionBoundary();
  const response = await publicRequest.post(
    `project-feedback-threads/${publicId(threadId, 'PROJECT_THREAD')}/messages`,
    {
      message: cleanUnicodeText(message),
      client_request_id: clientRequestId,
      attachment_ids: attachmentIds,
    },
    {headers: {'Idempotency-Key': clientRequestId}, timeout: 30000},
  );
  assertAccountSessionBoundary(boundary);
  const thread = mapProjectFeedbackThread(payloadFrom(response));
  if (!thread) throw new Error('PROJECT_FEEDBACK_THREAD_UNAVAILABLE');
  return thread;
};

export const uploadProjectFeedbackAttachment = async (
  threadId: string,
  file: ChatAttachmentDraft,
): Promise<string> => {
  const boundary = await captureAccountSessionBoundary();
  const body = new FormData();
  body.append('client_upload_id', file.uploadId);
  body.append('attachment', {
    uri: file.uri,
    name: file.name,
    type: file.type,
  } as unknown as Blob);
  const response = await publicRequest.post(
    `project-feedback-threads/${publicId(
      threadId,
      'PROJECT_THREAD',
    )}/attachments`,
    body,
    {headers: {'Content-Type': 'multipart/form-data'}, timeout: 45000},
  );
  assertAccountSessionBoundary(boundary);
  const id = valueAsString(asRecord(payloadFrom(response)).id);
  if (!id) throw new Error('PROJECT_FEEDBACK_ATTACHMENT_UPLOAD_FAILED');
  return id;
};
