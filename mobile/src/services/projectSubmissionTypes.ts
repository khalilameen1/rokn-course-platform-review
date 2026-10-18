import type {ProjectStatus, SelectedProjectFile} from '../components/VideoPlayer/types';
import {learnerDraftStorage} from './learnerDraftStorage';

export type PendingProjectSubmission = {
  projectId: string;
  accountScope: string;
  clientSubmissionId: string;
  fingerprint: string;
  selectedFiles?: SelectedProjectFile[];
  submissionText?: string;
  publicId?: string;
  pollAfterSeconds?: number;
  uploadAttempted?: boolean;
};

export type SubmissionSyncResult = {
  submissionStatus: ProjectStatus;
  accepted: boolean;
  canContinue: boolean;
  reviewFeedback?: string;
  canRetryReview?: boolean;
  reviewRetryEndpoint?: string;
  reviewFailureCategory?: string;
};

export type ProjectSubmissionOutcome = SubmissionSyncResult & {
  preserveDraft?: boolean;
};

export type ProjectSubmissionRetryOutcome = SubmissionSyncResult & {
  projectId: string;
};

/** v2 kept one key per project: `${prefix}:${scope}:${projectId}`. */
export const LEGACY_PROJECT_SUBMISSION_PREFIX =
  learnerDraftStorage.projectSubmissionLegacy.namespace;

export const PUBLIC_SUBMISSION_ID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export const canonicalSubmissionPublicId = (value: string) => {
  const id = String(value).trim().toLowerCase();
  if (!PUBLIC_SUBMISSION_ID_PATTERN.test(id)) {
    throw new Error('INVALID_SUBMISSION_ID');
  }
  return id;
};
