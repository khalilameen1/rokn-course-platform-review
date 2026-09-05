import type {
  ProjectFeedbackThread,
  ProjectReportStatus,
  ProjectStatus,
} from '../types';

export type ProjectJourneyState =
  | 'details'
  | 'draft'
  | 'submitting'
  | 'reviewing'
  | 'passed'
  | 'needs_changes';

export const reviewFeedbackForStatus = (
  status: ProjectStatus,
  feedback?: string,
): string | undefined => {
  if (status !== 'needs_changes') return undefined;
  const value = String(feedback || '').trim();
  return value || undefined;
};

/**
 * The server status describes the accepted submission. Draft hydration,
 * editing and the request flight are local UI states. Resolve them once so a
 * screen cannot simultaneously render a retry CTA and a submission form, or
 * call an in-flight upload "under review" before it has been accepted.
 */
export const resolveProjectJourneyState = ({
  status,
  draftReady,
  submitting,
  editingRetry,
}: {
  status: ProjectStatus;
  draftReady: boolean;
  submitting: boolean;
  editingRetry: boolean;
}): ProjectJourneyState => {
  if (submitting) return 'submitting';
  if (status === 'passed') return 'passed';
  if (status === 'evaluating') return 'reviewing';
  if (status === 'needs_changes' && !editingRetry) return 'needs_changes';
  return draftReady ? 'draft' : 'details';
};

export type ProjectReportViewState =
  | 'hidden'
  | 'preparing'
  | 'loading'
  | 'ready'
  | 'failed'
  | 'failed_retryable';

/**
 * Keep the report surface mutually exclusive. A passed project with a paid
 * report must never silently disappear because one part of its payload is
 * missing, nor render an empty thread while the report is still being made.
 */
export const resolveProjectReportViewState = ({
  projectStatus,
  reportEnabled,
  reportStatus,
  hydrating,
  thread,
  retryAvailable,
}: {
  projectStatus: ProjectStatus;
  reportEnabled: boolean;
  reportStatus: ProjectReportStatus;
  hydrating: boolean;
  thread?: ProjectFeedbackThread;
  retryAvailable: boolean;
}): ProjectReportViewState => {
  if (projectStatus !== 'passed' || !reportEnabled) return 'hidden';
  if (hydrating) return 'loading';
  if (reportStatus === 'queued') return 'preparing';
  if (reportStatus === 'ready') {
    return thread?.messages.length ? 'ready' : 'failed';
  }
  if (reportStatus === 'failed') {
    return retryAvailable ? 'failed_retryable' : 'failed';
  }
  // A report-enabled passed submission cannot legitimately remain
  // not_requested/not_included. Surface the broken contract instead of
  // pretending this tier has no report.
  return 'failed';
};
