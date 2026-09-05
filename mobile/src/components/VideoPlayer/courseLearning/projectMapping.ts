import type {CourseProject, ProjectStatus} from '../types';
import {courseRecord, type CoursePayloadDto} from './coursePayload';
import {
  parseProjectReportStatus,
  parseProjectSubmissionStatus,
} from './projects';
import {
  asArray,
  explicitBoolean,
  valueAsBoolean,
  valueAsString,
} from './shared';
import {mapProjectFeedbackThread} from './projectFeedbackMapping';
import {reviewFeedbackForStatus} from './projectJourney';

export const mapCourseProject = (
  section: CoursePayloadDto,
  moduleId: string,
): CourseProject | undefined => {
  const content = courseRecord(section.content);
  const lockReason = valueAsString(section.lock_reason).trim();
  const isLocked = valueAsBoolean(section.is_locked);
  if (
    lockReason === 'course_purchase_required' ||
    (!Object.keys(content).length && !isLocked)
  ) {
    return undefined;
  }

  const submission = courseRecord(content.latest_submission);
  const hasSubmission = Boolean(submission.id);
  const rawStatus: ProjectStatus = hasSubmission
    ? parseProjectSubmissionStatus(submission.submission_status, true)
    : 'draft';
  const projectFeedback = courseRecord(content.project_feedback);
  const projectFeedbackLevel = valueAsString(
    hasSubmission ? submission.feedback_level : projectFeedback.level,
    'pass_only',
  );
  const reportEnabled = hasSubmission
    ? valueAsBoolean(submission.report_enabled)
    : valueAsBoolean(projectFeedback.report_enabled);
  const feedbackThread =
    reportEnabled && ['report', 'enhanced'].includes(projectFeedbackLevel)
      ? mapProjectFeedbackThread(submission.feedback_thread) || undefined
      : undefined;

  return {
    id: valueAsString(section.content_id, `${moduleId}-project`),
    sectionId: valueAsString(section.id),
    moduleId,
    title: valueAsString(section.title, 'مشروع العبور'),
    requirements: valueAsString(content.requirements_text, ''),
    status: rawStatus,
    isGraduationProject: valueAsBoolean(content.is_graduation_project),
    isLocked,
    lockReason: lockReason || undefined,
    sectionOrder: Number.isFinite(Number(section.order))
      ? Number(section.order)
      : undefined,
    feedbackLevel: ['report', 'enhanced'].includes(projectFeedbackLevel)
      ? (projectFeedbackLevel as 'report' | 'enhanced')
      : 'pass_only',
    outputEnabled: valueAsBoolean(projectFeedback.output_enabled),
    reportEnabled,
    reportStatus: hasSubmission
      ? parseProjectReportStatus(submission.report_status)
      : reportEnabled
      ? 'not_requested'
      : 'not_included',
    replyEnabled: hasSubmission
      ? valueAsBoolean(submission.reply_enabled)
      : valueAsBoolean(projectFeedback.reply_enabled),
    canSubmit: hasSubmission ? valueAsBoolean(submission.can_submit) : true,
    canContinue: hasSubmission
      ? valueAsBoolean(submission.can_continue)
      : false,
    reviewFeedback: reviewFeedbackForStatus(
      rawStatus,
      valueAsString(submission.feedback),
    ),
    canRetryReport: explicitBoolean(submission.can_retry_report),
    reportRetryEndpoint:
      valueAsString(submission.report_retry_endpoint) || undefined,
    feedbackThread,
    submissionTextEnabled: valueAsBoolean(content.submission_text_enabled),
    submissionFilesEnabled: valueAsBoolean(content.submission_files_enabled),
    submissionMaxFiles: Math.min(
      5,
      Math.max(1, Number(content.submission_max_files) || 3),
    ),
    submissionAllowedMimeTypes: asArray<string>(
      content.submission_allowed_mime_types,
    )
      .map(value => String(value || '').toLowerCase())
      .filter(Boolean),
  };
};
