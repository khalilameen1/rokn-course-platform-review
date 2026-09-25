import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  copyProjectSubmissionDraft,
  saveProjectSubmissionDraft,
  type ProjectDraftCopyResult,
  type ProjectSubmissionDraft,
} from '../../../services/projectSubmissionDraft';
import {asRecord} from '../courseLearning/shared';
import {requestProjectRevisionConfirmation} from '../courseLearning/projectRemote';

export type DraftRevision = {
  response: unknown;
  currentProjectId: string | null;
};
export type DraftReplacementConfirmation = {
  projectId: string;
  snapshot: string;
};
type PreparedDestination =
  | {kind: 'ready'}
  | Extract<ProjectDraftCopyResult, {kind: 'conflict'}>;

export const projectDraftRevision = (
  error: unknown,
  sourceProjectId: string,
): DraftRevision | null => {
  const response = asRecord(asRecord(error).response || error);
  const envelope = asRecord(response.data);
  const data = asRecord(envelope.data);
  const positiveId = (value: unknown) =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
  if (
    response.status !== 409 ||
    envelope.code !== 'course_revision_changed' ||
    !positiveId(data.source_project_id) ||
    String(data.source_project_id) !== sourceProjectId ||
    !positiveId(data.course_id) ||
    !positiveId(data.published_revision) ||
    (data.current_project_id !== null &&
      !positiveId(data.current_project_id)) ||
    (data.current_project_id !== null &&
      !positiveId(data.current_section_id)) ||
    String(data.current_project_id) === sourceProjectId
  )
    return null;
  return {
    response,
    currentProjectId:
      data.current_project_id === null ? null : String(data.current_project_id),
  };
};

// Re-read the original project before each move, including after confirmation.
// A second publish can retire a destination while its confirmation is open.
export const resolveLatestProjectDraftRevision = async (
  sourceProjectId: string,
  boundary: AccountSessionBoundary,
): Promise<DraftRevision> => {
  assertAccountSessionBoundary(boundary);
  let response: unknown;
  try {
    response = await requestProjectRevisionConfirmation(sourceProjectId);
  } catch (error) {
    response = error;
  }
  assertAccountSessionBoundary(boundary);
  const revision = projectDraftRevision(response, sourceProjectId);
  if (!revision) throw new Error('PROJECT_REVISION_UNAVAILABLE');
  return revision;
};

// Prepare durable work only. The current UI visit owns confirmation/navigation.
export const prepareProjectDraftDestination = async ({
  sourceProjectId,
  revision,
  snapshot,
  boundary,
  confirmation,
}: {
  sourceProjectId: string;
  revision: DraftRevision;
  snapshot: Omit<ProjectSubmissionDraft, 'updatedAt'>;
  boundary: AccountSessionBoundary;
  confirmation?: DraftReplacementConfirmation;
}): Promise<PreparedDestination> => {
  assertAccountSessionBoundary(boundary);
  const draft = {...snapshot, updatedAt: Date.now()};
  if (!revision.currentProjectId) {
    await saveProjectSubmissionDraft(sourceProjectId, draft, boundary);
    assertAccountSessionBoundary(boundary);
    return {kind: 'ready'};
  }
  const result = await copyProjectSubmissionDraft(
    sourceProjectId,
    revision.currentProjectId,
    draft,
    boundary,
    confirmation?.projectId === revision.currentProjectId
      ? confirmation.snapshot
      : undefined,
  );
  assertAccountSessionBoundary(boundary);
  return result.kind === 'conflict' ? result : {kind: 'ready'};
};
