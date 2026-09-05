import {requireProductFeature} from '../../../services/productFeatures';
import type {AccountSessionBoundary} from '../../../constants/helpers';
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

type ProjectSubmissionRecoveryListener = (
  outcomes: readonly ProjectSubmissionRetryOutcome[],
) => void;

const recoveryListeners = new Map<
  ProjectSubmissionRecoveryListener,
  AccountSessionBoundary
>();
let latestRecovery:
  | {
      boundary: AccountSessionBoundary;
      outcomes: readonly ProjectSubmissionRetryOutcome[];
      publishedAt: number;
    }
  | undefined;
const RECOVERY_REPLAY_WINDOW_MS = 60_000;

const sameBoundary = (
  left: AccountSessionBoundary,
  right: AccountSessionBoundary,
) => left.scope === right.scope && left.epoch === right.epoch;

const publishRecoveryOutcomes = (
  outcomes: readonly ProjectSubmissionRetryOutcome[],
  boundary: AccountSessionBoundary,
) => {
  if (!outcomes.length) return;
  latestRecovery = {boundary, outcomes, publishedAt: Date.now()};
  recoveryListeners.forEach((ownerBoundary, listener) => {
    if (!sameBoundary(ownerBoundary, boundary)) return;
    try {
      listener(outcomes);
    } catch {
      // Presentation failures cannot invalidate a completed transport retry.
    }
  });
};

/**
 * The outbox owns transport recovery; the open course owns presentation.
 * Replay the last in-process result so a course that mounted one tick after
 * startup recovery still refreshes its map instead of keeping a stale draft.
 */
export const subscribeProjectSubmissionRecovery = (
  listener: ProjectSubmissionRecoveryListener,
  ownerBoundary: AccountSessionBoundary,
) => {
  recoveryListeners.set(listener, ownerBoundary);
  if (
    latestRecovery &&
    Date.now() - latestRecovery.publishedAt <= RECOVERY_REPLAY_WINDOW_MS &&
    sameBoundary(latestRecovery.boundary, ownerBoundary)
  ) {
    try {
      listener(latestRecovery.outcomes);
    } catch {
      // The listener owns its UI failure; the durable outbox stays recovered.
    }
  }
  return () => recoveryListeners.delete(listener);
};

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
  return runPendingSubmissionRetry(operation, async () => {
    const outcomes = await performPendingProjectSubmissionRetry(operation);
    assertProjectSubmissionOwner(operation);
    publishRecoveryOutcomes(outcomes, operation.boundary);
    return outcomes;
  });
};

export const quiesceProjectSubmissionRuntime = () => {
  latestRecovery = undefined;
  quiesceProjectSubmissionOwnership();
};
