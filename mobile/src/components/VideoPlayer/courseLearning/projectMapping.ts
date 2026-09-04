import type {CourseProject, ProjectStatus} from '../types';
import {
  courseRecord,
  type CoursePayloadDto,
} from './coursePayload';
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
  const rawThread = courseRecord(submission.feedback_thread);
  const feedbackLevel = valueAsString(rawThread.feedback_level);
  const projectFeedback = courseRecord(content.project_feedback);
  const projectFeedbackLevel = valueAsString(
    hasSubmission ? submission.feedback_level : projectFeedback.level,
    'pass_only',
  );
  const reportEnabled = hasSubmission
    ? valueAsBoolean(submission.report_enabled)
    : valueAsBoolean(projectFeedback.report_enabled);
  const feedbackThread =
    reportEnabled &&
    ['report', 'enhanced'].includes(projectFeedbackLevel) &&
    rawThread.id &&
    ['report', 'enhanced'].includes(feedbackLevel)
      ? {
          id: valueAsString(rawThread.id),
          feedbackLevel: feedbackLevel as 'report' | 'enhanced',
          canReply: valueAsBoolean(rawThread.can_reply),
          status: valueAsString(rawThread.status, 'ready'),
          remainingMessages: Math.max(
            0,
            Number(rawThread.remaining_messages) || 0,
          ),
          messages: asArray<CoursePayloadDto>(rawThread.messages).flatMap(
            message => {
              const role = valueAsString(message.role);
              const status = valueAsString(message.status);
              if (
                !['assistant', 'user'].includes(role) ||
                ![
                  'queued',
                  'sent',
                  'streaming',
                  'completed',
                  'failed',
                  'cancelled',
                ].includes(status)
              ) {
                return [];
              }
              return [
                {
                  id: valueAsString(message.id),
                  clientRequestId:
                    valueAsString(message.client_request_id) || undefined,
                  role: role as 'assistant' | 'user',
                  status: status as
                    | 'queued'
                    | 'sent'
                    | 'streaming'
                    | 'completed'
                    | 'failed'
                    | 'cancelled',
                  text: valueAsString(message.text) || undefined,
                  createdAt: valueAsString(message.created_at) || undefined,
                  attachments: asArray<CoursePayloadDto>(
                    message.attachments,
                  ).map(file => ({
                    uri: '',
                    name: valueAsString(file.name, 'مرفق'),
                    type: valueAsString(
                      file.mime_type,
                      'application/octet-stream',
                    ),
                    size: Number(file.size_bytes) || undefined,
                    uploadId: valueAsString(file.id),
                    serverId: valueAsString(file.id),
                    downloadUrl: valueAsString(file.download_url) || undefined,
                    downloadExpiresAt:
                      valueAsString(file.download_url_expires_at) || undefined,
                  })),
                },
              ];
            },
          ),
        }
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
    reviewFeedback: valueAsString(submission.feedback) || undefined,
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
