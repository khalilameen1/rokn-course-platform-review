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
      const uploadedFiles = pending.selectedFiles;
      pending.selectedFiles = [];
      await savePendingProjectSubmission(pending, operation, uploadedFiles);
      await removePendingProjectFiles({
        ...pending,
        selectedFiles: uploadedFiles,
      });
    }
    return pollProjectSubmission(pending, operation);
  }

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
    if (retryableProjectSubmissionFailure(error)) {
      return {
        submissionStatus: 'evaluating',
        accepted: false,
        canContinue: false,
      };
    }
    throw error;
  }

  const payload = unwrapResponseData(response);
  const immediateResult = parseSubmissionResult(payload);
  if (
    immediateResult.submissionStatus === 'passed' ||
    immediateResult.submissionStatus === 'needs_changes'
  ) {
    return immediateResult;
  }

  const publicId = valueAsString(payload.id);
  if (!PUBLIC_SUBMISSION_ID_PATTERN.test(publicId)) {
    return {
      submissionStatus: 'evaluating',
      accepted: false,
      canContinue: false,
    };
  }
  pending.publicId = publicId.toLowerCase();
  pending.pollAfterSeconds = Number(payload.poll_after_seconds) || 1;
  const uploadedFiles = pending.selectedFiles || [];
  pending.selectedFiles = [];
  await savePendingProjectSubmission(pending, operation, uploadedFiles);
  await removePendingProjectFiles({...pending, selectedFiles: uploadedFiles});
  assertProjectSubmissionOwner(operation);
  return pollProjectSubmission(pending, operation);
};

export const syncProjectSubmission = (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
) =>
  runSubmissionSync(pending.clientSubmissionId, operation, () =>
    performProjectSubmissionSync(pending, operation),
  );
