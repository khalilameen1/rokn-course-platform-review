import {requireProductFeature} from '../../../services/productFeatures';
import type {SelectedProjectFile} from '../types';
import {
  assertProjectSubmissionOwner,
  beginProjectSubmissionOperation,
  quiesceProjectSubmissionOwnership,
  runForegroundSubmission,
  runPendingSubmissionRetry,
  withProjectSubmissionLock,
  type ProjectSubmissionOperation,
} from './projectSubmissionOwnership';
import {
  clearPendingProjectSubmission,
  getOrCreatePendingProjectSubmission,
  listPendingProjectSubmissions,
} from './projectSubmissionStore';
import {
  retryableProjectSubmissionFailure,
  syncProjectSubmission,
} from './projectSubmissionTransport';
import type {
  PendingProjectSubmission,
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
  SubmissionSyncResult,
} from './projectSubmissionTypes';

export type {
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
} from './projectSubmissionTypes';

const outcomeFromSync = async (
  result: SubmissionSyncResult,
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
): Promise<ProjectSubmissionOutcome> => {
  assertProjectSubmissionOwner(operation);
  if (result.submissionStatus === 'passed') {
    await clearPendingProjectSubmission(pending, operation);
    return {
      submissionStatus: 'passed',
      accepted: true,
      canContinue: result.canContinue,
    };
  }

  if (result.submissionStatus === 'evaluating') {
    // The durable outbox is transport state, not a project decision. Keep the
    // editor visible until the server has accepted a real submission.
    return result.accepted
      ? {
          submissionStatus: 'evaluating',
          accepted: true,
          canContinue: false,
        }
      : {
          submissionStatus: 'draft',
          accepted: false,
          canContinue: false,
        };
  }

  await clearPendingProjectSubmission(pending, operation);
  return {
    submissionStatus: 'needs_changes',
    accepted: true,
    canContinue: false,
  };
};

const performForegroundSubmission = async (
  projectId: string,
  operation: ProjectSubmissionOperation,
  selectedFiles: SelectedProjectFile[],
  submissionText: string | undefined,
) =>
  withProjectSubmissionLock(projectId, operation, async () => {
    assertProjectSubmissionOwner(operation);
    await requireProductFeature('project_uploads');
    assertProjectSubmissionOwner(operation);

    const pending = await getOrCreatePendingProjectSubmission(
      projectId,
      operation,
      selectedFiles,
      submissionText,
    );
    try {
      const result = await syncProjectSubmission(pending, operation);
      return await outcomeFromSync(result, pending, operation);
    } catch (error) {
      assertProjectSubmissionOwner(operation);
      if (!retryableProjectSubmissionFailure(error)) {
        await clearPendingProjectSubmission(pending, operation);
      }
      throw error;
    }
  });

export const submitProjectAttempt = async (
  projectId: string,
  selectedInput?: SelectedProjectFile | SelectedProjectFile[] | null,
  submissionText?: string,
): Promise<ProjectSubmissionOutcome> => {
  const selectedFiles = Array.isArray(selectedInput)
    ? selectedInput
    : selectedInput
    ? [selectedInput]
    : [];
  const operation = await beginProjectSubmissionOperation();
  return runForegroundSubmission(projectId, operation, () =>
    performForegroundSubmission(
      projectId,
      operation,
      selectedFiles,
      submissionText,
    ),
  );
};

const performPendingProjectSubmissionRetry = async (
  operation: ProjectSubmissionOperation,
): Promise<ProjectSubmissionRetryOutcome[]> => {
  const outcomes: ProjectSubmissionRetryOutcome[] = [];
  const entries = await listPendingProjectSubmissions(operation);
  for (const {value: pending} of entries) {
    assertProjectSubmissionOwner(operation);
    try {
      // Recovery and a learner tap are the same project operation. Sharing the
      // foreground flight prevents a resume retry from finishing, clearing the
      // outbox, then letting the queued tap create a second submission.
      const result = await runForegroundSubmission(
        pending.projectId,
        operation,
        () =>
          withProjectSubmissionLock(pending.projectId, operation, async () =>
            outcomeFromSync(
              await syncProjectSubmission(pending, operation),
              pending,
              operation,
            ),
          ),
      );
      assertProjectSubmissionOwner(operation);
      outcomes.push({projectId: pending.projectId, ...result});
    } catch {
      try {
        assertProjectSubmissionOwner(operation);
      } catch {
        return outcomes;
      }
      // The next course opening retries a transport failure silently.
    }
  }
  return outcomes;
};

export const retryPendingProjectSubmissions = async (): Promise<
  ProjectSubmissionRetryOutcome[]
> => {
  const operation = await beginProjectSubmissionOperation();
  return runPendingSubmissionRetry(operation, () =>
    performPendingProjectSubmissionRetry(operation),
  );
};

export const quiesceProjectSubmissionRuntime = () => {
  quiesceProjectSubmissionOwnership();
};
