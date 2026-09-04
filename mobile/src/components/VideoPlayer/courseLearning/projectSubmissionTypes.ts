import type {ProjectStatus, SelectedProjectFile} from '../types';

export type PendingProjectSubmission = {
  projectId: string;
  accountScope: string;
  clientSubmissionId: string;
  fingerprint: string;
  selectedFiles?: SelectedProjectFile[];
  submissionText?: string;
  publicId?: string;
  pollAfterSeconds?: number;
};

export type SubmissionSyncResult = {
  submissionStatus: ProjectStatus;
  accepted: boolean;
  canContinue: boolean;
};

export type ProjectSubmissionOutcome = SubmissionSyncResult;

export type ProjectSubmissionRetryOutcome = SubmissionSyncResult & {
  projectId: string;
};

export const PROJECT_SUBMISSION_PREFIX = '@rokn/project-submission/v2';

export const PUBLIC_SUBMISSION_ID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export const canonicalSubmissionPublicId = (value: string) => {
  const id = String(value).trim().toLowerCase();
  if (!PUBLIC_SUBMISSION_ID_PATTERN.test(id)) {
    throw new Error('INVALID_SUBMISSION_ID');
  }
  return id;
};
