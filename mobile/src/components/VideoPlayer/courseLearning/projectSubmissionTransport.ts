import {publicRequest} from '../../../constants/api';
import {
  asRecord,
  type DataRecord,
  valueAsBoolean,
  valueAsString,
} from './shared';
import {parseProjectSubmissionStatus} from './projectRemote';
import {
  assertProjectSubmissionOwner,
  runSubmissionSync,
  type ProjectSubmissionOperation,
} from './projectSubmissionOwnership';
import {
  removePendingProjectFiles,
  savePendingProjectSubmission,
} from './projectSubmissionStore';
import {
  canonicalSubmissionPublicId,
  PUBLIC_SUBMISSION_ID_PATTERN,
  type PendingProjectSubmission,
  type SubmissionSyncResult,
} from './projectSubmissionTypes';

const makeSubmissionForm = (pending: PendingProjectSubmission) => {
  const form = new FormData();
  if (pending.submissionText) {
    form.append('submission_text', pending.submissionText);
  }
  pending.selectedFiles?.forEach(file =>
    form.append('submission_files[]', {
      uri: file.uri,
      name: file.name,
      type: file.type,
    } as unknown as Blob),
  );
  form.append('client_submission_id', pending.clientSubmissionId);
  return form;
};

const unwrapResponseData = (response: unknown): DataRecord => {
  const root = asRecord(response);
  const data = asRecord(root.data);
  return asRecord(data.data || root.data || response);
};

const parseSubmissionResult = (payload: DataRecord): SubmissionSyncResult => {
  const reviewFeedback = valueAsString(payload.feedback).trim();
  return {
    submissionStatus: parseProjectSubmissionStatus(
      payload.submission_status,
      true,
    ),
    accepted: true,
    canContinue: valueAsBoolean(payload.can_continue),
    ...('can_retry_review' in payload
      ? {
          canRetryReview: payload.can_retry_review === true,
          reviewRetryEndpoint:
            valueAsString(payload.review_retry_endpoint) || undefined,
          reviewFailureCategory:
            valueAsString(payload.review_failure_category) || undefined,
        }
      : {}),
    ...(reviewFeedback ? {reviewFeedback} : {}),
  };
};

const waitFor = (milliseconds: number) =>
  new Promise<void>(resolve => setTimeout(resolve, milliseconds));

const requestStatus = (error: unknown): number | null => {
  if (!error || typeof error !== 'object') return null;
  const candidate = error as {
    status?: unknown;
    response?: {status?: unknown};
  };
  const status = Number(candidate.status ?? candidate.response?.status);
  return Number.isFinite(status) && status > 0 ? status : null;
};

export const retryableProjectSubmissionFailure = (error: unknown) => {
  const status = requestStatus(error);
  return status === null || status === 408 || status === 429 || status >= 500;
};

const reportUncertainSubmission = (
  source: 'unknown_upload' | 'invalid_ack',
) => {
  void import('../../../services/operationalTelemetry')
    .then(({reportClientError}) =>
      reportClientError(
        new Error(`PROJECT_SUBMISSION_${source.toUpperCase()}`),
        {
          source: `project_submission_${source}`,
        },
      ),
    )
    .catch(() => undefined);
};

const unconfirmedSubmission = (): SubmissionSyncResult => ({
  submissionStatus: 'evaluating',
  accepted: false,
  canContinue: false,
});

export const assertSubmissionRetryWindow = (
  pending: PendingProjectSubmission,
) => {
  const seconds = Math.ceil(
    (Number(pending.retryAfterAt || 0) - Date.now()) / 1000,
  );
  if (seconds > 0) {
    throw Object.assign(new Error('PROJECT_SUBMISSION_RATE_LIMITED'), {
      status: 429,
      retryAfterSeconds: seconds,
    });
  }
};

const submissionRetryAfterSeconds = (error: unknown) => {
  const root = asRecord(error);
  const response = asRecord(root.response || error);
  const headers = asRecord(response.headers);
  const raw =
    typeof headers.get === 'function'
      ? headers.get.call(response.headers, 'retry-after')
      : headers['retry-after'] ?? headers['Retry-After'];
  const seconds = Number(raw);
  if (raw !== undefined && Number.isFinite(seconds) && seconds > 0)
    return Math.ceil(seconds);
  const date = Date.parse(String(raw || ''));
  return Number.isFinite(date) && date > Date.now()
    ? Math.ceil((date - Date.now()) / 1000)
    : 60;
};

const pollProjectSubmission = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
  attempts = 5,
): Promise<SubmissionSyncResult> => {
  if (!pending.publicId) {
    return {
      submissionStatus: 'draft',
      accepted: false,
      canContinue: false,
    };
  }

  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (attempt > 0) {
      const delay = Math.min(
        3500,
        Math.max(700, Number(pending.pollAfterSeconds || 1) * 1000),
      );
      await waitFor(delay);
    }
    try {
      assertProjectSubmissionOwner(operation);
      const response = await publicRequest.get(
        `project-submissions/${canonicalSubmissionPublicId(pending.publicId)}`,
        {timeout: 12000},
      );
      assertProjectSubmissionOwner(operation);
      const result = parseSubmissionResult(unwrapResponseData(response));
      if (
        result.submissionStatus === 'passed' ||
        result.submissionStatus === 'review_unavailable' ||
        result.submissionStatus === 'needs_changes'
      ) {
        return result;
      }
    } catch (error) {
      assertProjectSubmissionOwner(operation);
      if (
        error instanceof Error &&
        error.message === 'PROJECT_SUBMISSION_CONTRACT_INVALID'
      ) {
        throw error;
      }
      return {
        submissionStatus: 'evaluating',
        accepted: true,
        canContinue: false,
      };
    }
  }
  return {
    submissionStatus: 'evaluating',
    accepted: true,
    canContinue: false,
  };
};

const persistAcceptedSubmission = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
) => {
  const uploadedFiles = pending.selectedFiles || [];
  try {
    // Keep the old durable files and idempotency key until the server identity
    // is committed locally. A failed write must not delete the retry payload.
    await savePendingProjectSubmission(
      {...pending, selectedFiles: []},
      operation,
      uploadedFiles,
    );
    pending.selectedFiles = [];
    await removePendingProjectFiles({...pending, selectedFiles: uploadedFiles});
  } catch {
    assertProjectSubmissionOwner(operation);
    void import('../../../services/operationalTelemetry')
      .then(({reportClientError}) =>
        reportClientError(new Error('PROJECT_SUBMISSION_ACKNOWLEDGEMENT'), {
          source: 'project_submission_acknowledgement',
        }),
      )
      .catch(() => undefined);
  }
  assertProjectSubmissionOwner(operation);
};

export const recoverSubmissionAcknowledgement = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
  waitForCommit = false,
): Promise<
  | {kind: 'found'; result: SubmissionSyncResult}
  | {kind: 'missing' | 'unavailable'}
> => {
  // A response can be lost before the server transaction commits. Bound the
  // complete read recovery, including short 404 waits, to one read budget.
  const deadline = Date.now() + 12000;
  const attempts = waitForCommit ? 3 : 1;
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (attempt)
      await waitFor(Math.max(0, Math.min(1000, deadline - Date.now())));
    assertProjectSubmissionOwner(operation);
    const remaining = deadline - Date.now();
    if (remaining <= 0) return {kind: 'unavailable'};
    let payload: DataRecord;
    try {
      const response = await publicRequest.get(
        `projects/${pending.projectId}/submissions/lookup`,
        {
          params: {client_submission_id: pending.clientSubmissionId},
          timeout: remaining,
        },
      );
      assertProjectSubmissionOwner(operation);
      payload = unwrapResponseData(response);
    } catch (error) {
      assertProjectSubmissionOwner(operation);
      if (requestStatus(error) !== 404) return {kind: 'unavailable'};
      if (attempt + 1 === attempts) return {kind: 'missing'};
      continue;
    }

    // Never substitute the latest project attempt or another owner's result
    // for this outbox identity, even when its status happens to be terminal.
    const id = valueAsString(payload.id);
    if (
      payload.client_submission_id !== pending.clientSubmissionId ||
      String(payload.project_id) !== pending.projectId ||
      !PUBLIC_SUBMISSION_ID_PATTERN.test(id)
    ) {
      reportUncertainSubmission('invalid_ack');
      return {kind: 'unavailable'};
    }
    let result: SubmissionSyncResult;
    try {
      result = parseSubmissionResult(payload);
      if (result.submissionStatus === 'draft') {
        throw new Error('PROJECT_SUBMISSION_CONTRACT_INVALID');
      }
    } catch {
      reportUncertainSubmission('invalid_ack');
      return {kind: 'unavailable'};
    }
    pending.publicId = id.toLowerCase();
    pending.pollAfterSeconds = Number(payload.poll_after_seconds) || 1;
    await persistAcceptedSubmission(pending, operation);
    // The course watcher owns any remaining review wait. Identity recovery
    // must not append a second polling budget to the bounded lookup itself.
    return {kind: 'found', result};
  }
  return {kind: 'missing'};
};

const resolveUncertainSubmission = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
  source: 'unknown_upload' | 'invalid_ack',
) => {
  reportUncertainSubmission(source);
  const recovered = await recoverSubmissionAcknowledgement(
    pending,
    operation,
    true,
  );
  return recovered.kind === 'found'
    ? recovered.result
    : unconfirmedSubmission();
};

const performProjectSubmissionSync = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
): Promise<SubmissionSyncResult> => {
  assertProjectSubmissionOwner(operation);
  if (pending.accountScope !== operation.boundary.scope) {
    throw new Error('PROJECT_SUBMISSION_SCOPE_MISMATCH');
  }
  if (pending.publicId) {
    if (pending.selectedFiles?.length) {
      await persistAcceptedSubmission(pending, operation);
    }
    return pollProjectSubmission(pending, operation);
  }
  assertSubmissionRetryWindow(pending);

  // Legacy outboxes have no marker and may already exist on the server. A
  // failed lookup is not permission to replay their multipart upload.
  if (pending.uploadAttempted !== false) {
    const recovered = await recoverSubmissionAcknowledgement(
      pending,
      operation,
    );
    if (recovered.kind === 'found') return recovered.result;
    if (recovered.kind === 'unavailable') return unconfirmedSubmission();
  }

  pending.uploadAttempted = true;
  await savePendingProjectSubmission(
    pending,
    operation,
    pending.selectedFiles || [],
  );
  assertProjectSubmissionOwner(operation);

  let response: unknown;
  try {
    assertProjectSubmissionOwner(operation);
    response = await publicRequest.post(
      `projects/${pending.projectId}/submissions`,
      makeSubmissionForm(pending),
      {
        headers: {
          'Content-Type': 'multipart/form-data',
          'Idempotency-Key': pending.clientSubmissionId,
        },
        timeout: 30000,
      },
    );
    assertProjectSubmissionOwner(operation);
  } catch (error) {
    assertProjectSubmissionOwner(operation);
    if (requestStatus(error) === 429) {
      // This POST was explicitly refused, not lost in transit. Persist the
      // server's cooldown so taps, resume and process restart cannot bypass it.
      pending.retryAfterAt =
        Date.now() + submissionRetryAfterSeconds(error) * 1000;
      await savePendingProjectSubmission(
        pending,
        operation,
        pending.selectedFiles || [],
      );
      assertProjectSubmissionOwner(operation);
      assertSubmissionRetryWindow(pending);
    }
    if (retryableProjectSubmissionFailure(error)) {
      return resolveUncertainSubmission(pending, operation, 'unknown_upload');
    }
    throw error;
  }

  const payload = unwrapResponseData(response);
  let immediateResult: SubmissionSyncResult;
  try {
    immediateResult = parseSubmissionResult(payload);
  } catch {
    return resolveUncertainSubmission(pending, operation, 'invalid_ack');
  }
  if (
    immediateResult.submissionStatus === 'passed' ||
    immediateResult.submissionStatus === 'review_unavailable' ||
    immediateResult.submissionStatus === 'needs_changes'
  ) {
    return immediateResult;
  }

  const publicId = valueAsString(payload.id);
  if (!PUBLIC_SUBMISSION_ID_PATTERN.test(publicId)) {
    return resolveUncertainSubmission(pending, operation, 'invalid_ack');
  }
  pending.publicId = publicId.toLowerCase();
  pending.pollAfterSeconds = Number(payload.poll_after_seconds) || 1;
  await persistAcceptedSubmission(pending, operation);
  return pollProjectSubmission(pending, operation);
};

export const syncProjectSubmission = (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
) =>
  runSubmissionSync(pending.clientSubmissionId, operation, () =>
    performProjectSubmissionSync(pending, operation),
  );
