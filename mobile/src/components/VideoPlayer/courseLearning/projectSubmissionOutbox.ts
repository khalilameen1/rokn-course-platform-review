import {requireProductFeature} from '../../../services/productFeatures';
import type {AccountSessionBoundary} from '../../../constants/helpers';
import type {SelectedProjectFile} from '../types';
import type {
  DurableOutboxDelivery,
  DurableOutboxItem,
} from '../../../services/durableOutbox';
import {invalidateLearningProgress} from '../../../services/query/learningInvalidation';
import {
  assertProjectSubmissionOwner,
  beginProjectSubmissionOperation,
  quiesceProjectSubmissionOwnership,
  runForegroundSubmission,
  runPendingSubmissionRetry,
  withProjectSubmissionLock,
  type ProjectSubmissionOperation,
} from '../../../services/projectSubmissionOwnership';
import {
  assertSubmissionRetryWindow,
  classifyBackgroundSubmissionFailure,
  clearPendingProjectSubmission,
  getOrCreatePendingProjectSubmission,
  pendingSubmissionMatchesInput,
  projectSubmissionOutbox,
  readPendingProjectSubmission,
} from '../../../services/projectSubmissionStore';
import {
  recoverSubmissionAcknowledgement,
  syncProjectSubmission,
} from './projectSubmissionTransport';
import type {
  PendingProjectSubmission,
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
  SubmissionSyncResult,
} from '../../../services/projectSubmissionTypes';

export type {
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
} from '../../../services/projectSubmissionTypes';

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

const reportTerminalCleanupFailure = () => {
  // Local cleanup cannot turn a received server decision into a failed
  // upload. The surviving outbox item keeps its original idempotency key.
  void import('../../../services/operationalTelemetry')
    .then(({reportClientError}) =>
      reportClientError(new Error('PROJECT_SUBMISSION_TERMINAL_CLEANUP'), {
        source: 'project_submission_terminal_cleanup',
      }),
    )
    .catch(() => undefined);
};

/** Pure mapping from the server's answer to what the course shows. */
const outcomeFromSync = (
  result: SubmissionSyncResult,
): ProjectSubmissionOutcome => {
  if (result.submissionStatus === 'passed') {
    return {
      ...result,
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
          ...result,
          submissionStatus: 'evaluating',
          accepted: true,
          canContinue: false,
        }
      : {
          ...result,
          submissionStatus: 'draft',
          accepted: false,
          canContinue: false,
        };
  }

  if (result.submissionStatus === 'review_unavailable') {
    return {...result, accepted: true, canContinue: false};
  }
  return {
    ...result,
    submissionStatus: 'needs_changes',
    accepted: true,
    canContinue: false,
  };
};

/** A server decision retires the outbox item; evaluation keeps it. */
const deliveryFor = (
  outcome: ProjectSubmissionOutcome,
): DurableOutboxDelivery<PendingProjectSubmission, ProjectSubmissionOutcome> => ({
  outcome:
    outcome.submissionStatus === 'evaluating' ||
    outcome.submissionStatus === 'draft'
      ? 'keep'
      : 'ack',
  value: outcome,
});

/** The deliver function of the submission adapter. */
const deliverSubmission =
  (operation: ProjectSubmissionOperation) =>
  async (item: DurableOutboxItem<PendingProjectSubmission>) => {
    const result = await syncProjectSubmission(item.payload, operation);
    assertProjectSubmissionOwner(operation);
    return deliveryFor(outcomeFromSync(result));
  };

const performForegroundSubmission = async (
  projectId: string,
  operation: ProjectSubmissionOperation,
  selectedFiles: SelectedProjectFile[],
  submissionText: string | undefined,
): Promise<ProjectSubmissionOutcome> =>
  withProjectSubmissionLock(projectId, operation, async () => {
    assertProjectSubmissionOwner(operation);
    await requireProductFeature('project_uploads');
    assertProjectSubmissionOwner(operation);

    const previousItem = await readPendingProjectSubmission(
      projectId,
      operation,
    );
    const previous = previousItem?.payload;
    if (previousItem && !previous?.publicId) {
      assertSubmissionRetryWindow(previousItem);
    }
    if (
      previous &&
      (previous.publicId || previous.uploadAttempted !== false) &&
      !pendingSubmissionMatchesInput(previous, selectedFiles, submissionText)
    ) {
      // Editing a draft does not settle an upload whose response was lost.
      // Resolve the old identity before replacing its durable retry payload.
      const recovered = await recoverSubmissionAcknowledgement(
        previous,
        operation,
      );
      assertProjectSubmissionOwner(operation);
      if (
        recovered.kind === 'found' &&
        recovered.result.submissionStatus === 'passed'
      ) {
        try {
          await clearPendingProjectSubmission(previous, operation);
        } catch {
          assertProjectSubmissionOwner(operation);
          reportTerminalCleanupFailure();
        }
        invalidateLearningProgress(operation.boundary.scope);
        return {...outcomeFromSync(recovered.result), preserveDraft: true};
      }
      if (
        recovered.kind !== 'missing' &&
        !(
          recovered.kind === 'found' &&
          recovered.result.submissionStatus === 'needs_changes'
        )
      ) {
        throw new Error('PROJECT_SUBMISSION_PREVIOUS_ATTEMPT_PENDING');
      }
      // An absent or rejected old attempt can be replaced. Require the local
      // removal to succeed before allocating a new identity for the new input.
      await clearPendingProjectSubmission(previous, operation);
    }

    const pending = await getOrCreatePendingProjectSubmission(
      projectId,
      operation,
      selectedFiles,
      submissionText,
    );
    // A learner tap is the explicit trigger. The outbox classification
    // retires a refused attempt and keeps transport failures and missing AI
    // consent under the same identity; the error still reaches the screen.
    const delivered = await projectSubmissionOutbox.deliver(
      pending,
      deliverSubmission(operation),
      {boundary: operation.boundary, resume: true},
    );
    assertProjectSubmissionOwner(operation);
    if (delivered.state !== 'delivered' || !delivered.value) {
      throw new Error('PROJECT_SUBMISSION_PREVIOUS_ATTEMPT_PENDING');
    }
    if (delivered.retirement === 'failed') reportTerminalCleanupFailure();
    if (delivered.value.submissionStatus === 'passed') {
      invalidateLearningProgress(operation.boundary.scope);
    }
    return delivered.value;
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
  const deliver = deliverSubmission(operation);
  let results;
  try {
    ({results} = await projectSubmissionOutbox.flush({
      boundary: operation.boundary,
      // Course opening and app resume are lifecycle triggers.
      ignoreBackoff: true,
      // The next course opening retries a failure silently; only a server
      // decision or a coded cooldown changes the record in the background.
      classify: classifyBackgroundSubmissionFailure,
      // Recovery and a learner tap are the same project operation. Sharing
      // the foreground flight prevents a resume retry from finishing,
      // clearing the outbox, then letting a queued tap submit a second time.
      deliver: async item =>
        deliveryFor(
          await runForegroundSubmission(
            item.payload.projectId,
            operation,
            () =>
              withProjectSubmissionLock(
                item.payload.projectId,
                operation,
                async () => (await deliver(item)).value!,
              ),
          ),
        ),
    }));
  } catch {
    // An account change retires this pass; the records stay for their owner.
    assertProjectSubmissionOwner(operation);
    return [];
  }
  const outcomes = results.flatMap(result =>
    result.value
      ? [{projectId: result.item.payload.projectId, ...result.value}]
      : [],
  );
  if (outcomes.some(outcome => outcome.submissionStatus === 'passed')) {
    invalidateLearningProgress(operation.boundary.scope);
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
