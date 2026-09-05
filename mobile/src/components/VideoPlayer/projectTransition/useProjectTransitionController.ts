import {useAppForegroundState} from '../../../hooks/useAppActiveState';
import type {ProjectSubmissionOutcome} from '../courseLearningApi';
import type {CourseProject, SelectedProjectFile} from '../types';
import {resolveProjectReportViewState} from '../courseLearning/projectJourney';
import {useProjectFeedback} from './useProjectFeedback';
import {useProjectResolution} from './useProjectResolution';
import {useProjectSubmission} from './useProjectSubmission';

type ControllerInput = {
  active: boolean;
  project: CourseProject;
  onSubmit: (
    files: SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
};

/**
 * Compose the three independent parts of a crossing project.
 *
 * Submission owns the local editor and upload attempt. Resolution owns the
 * server decision and report lifecycle. Feedback owns only the optional
 * report conversation. This hook contains no second copy of any of them.
 */
export const useProjectTransitionController = ({
  active,
  project,
  onSubmit,
}: ControllerInput) => {
  const appIsActive = useAppForegroundState();
  const resolution = useProjectResolution({active, appIsActive, project});
  const submission = useProjectSubmission({
    appIsActive,
    project,
    status: resolution.status,
    submissionAllowed: resolution.contract.canSubmit,
    onSubmit,
    onOutcome: resolution.applySubmissionOutcome,
  });
  const feedback = useProjectFeedback({
    active,
    appIsActive,
    projectId: project.id,
    seedThread: resolution.feedbackThread,
    feedbackLevel: resolution.contract.feedbackLevel,
    replyEnabled: resolution.contract.replyEnabled,
    reportStatus: resolution.reportStatus,
  });
  const reportViewState = resolveProjectReportViewState({
    projectStatus: resolution.status,
    reportEnabled: resolution.contract.reportEnabled,
    reportStatus: resolution.reportStatus,
    hydrating: feedback.hydrating,
    thread: feedback.thread,
    retryAvailable: resolution.reportRetryAvailable,
  });

  return {
    canReplyToFeedback: feedback.canReply,
    canContinue: resolution.contract.canContinue,
    changeFeedbackDraft: feedback.changeDraft,
    changeSubmissionNote: submission.changeNote,
    chooseProjectFile: submission.chooseProjectFile,
    editRetry: submission.editRetry,
    feedbackAttachments: feedback.attachments,
    feedbackDraft: feedback.draft,
    feedbackError: feedback.error,
    feedbackLevel: resolution.contract.feedbackLevel,
    feedbackPending: feedback.pending,
    feedbackSending: feedback.sending,
    feedbackThread: feedback.thread,
    fileTypesLabel: submission.fileTypesLabel,
    fileSubmissionEnabled: submission.fileSubmissionEnabled,
    filePickerDisabled: submission.filePickerDisabled,
    journeyState: submission.journeyState,
    normalizedFeedbackDraft: feedback.normalizedDraft,
    pickFeedbackAttachments: feedback.pickAttachments,
    removeFeedbackAttachment: feedback.removeAttachment,
    removeSubmissionFile: submission.removeSubmissionFile,
    reportRetrying: resolution.reportRetrying,
    reportViewState,
    retryFeedbackMessage: feedback.retryMessage,
    retryReport: resolution.retryReport,
    reviewFeedback: resolution.reviewFeedback,
    sendFeedback: feedback.send,
    selectedFiles: submission.selectedFiles,
    submissionAllowed: submission.submissionAllowed,
    submissionDraftSaveError: submission.draftSaveError,
    submissionMaximumFiles: submission.maximumFiles,
    submissionNote: submission.note,
    submissionSending: submission.sending,
    submit: submission.submit,
    submitDisabled: submission.submitDisabled,
    syncNote: submission.syncNote,
    textSubmissionEnabled: submission.textSubmissionEnabled,
  };
};

export type ProjectTransitionController = ReturnType<
  typeof useProjectTransitionController
>;
