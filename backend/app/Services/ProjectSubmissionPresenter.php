<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiEntitlementUsage;
use App\Models\AiInputAttachment;
use App\Models\AiUsageEvent;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Support\ProjectReportRetryPolicy;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\ProjectSubmissionLifecycle;
use Illuminate\Support\Facades\URL;

final readonly class ProjectSubmissionPresenter
{
    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private ProjectFeedbackThreadService $feedbackThreads,
        private AiFailurePolicy $failurePolicy
    ) {
    }

    /** @return array<string,mixed> */
    public function present(ProjectSubmission $submission, bool $includeThreadTranscript = true): array
    {
        $submission->loadMissing(['aiInputAttachments', 'feedbackThread.enrollment']);
        $metadata = is_array($submission->submission_metadata)
            ? $submission->submission_metadata
            : [];
        $outcome = $submission->reviewOutcome();
        $reviewStatus = (string) $outcome['status'];
        $contract = $this->contract($submission);
        $reportEnabled = (bool) $contract['project_report_enabled'];
        $replyEnabled = (bool) $contract['project_thread_reply_enabled'];
        $thread = $submission->feedbackThread;
        $reportStatus = ProjectSubmissionLifecycle::reportStatus(
            $reportEnabled,
            $thread?->status,
            data_get($metadata, 'ai_feedback.status'),
            $reviewStatus
        );
        $threadPayload = null;
        if ($thread && $reportEnabled) {
            $threadPayload = $includeThreadTranscript
                ? $this->feedbackThreads->payload($thread)
                : $this->threadSummary($thread, $contract, $reportStatus);
        }
        $canRetryReport = $this->canRetryInitialReport($submission, $reportStatus);
        $reportFailure = $reportStatus === ProjectSubmissionLifecycle::REPORT_FAILED
            ? $this->failurePolicy->describe((string) data_get($metadata, 'ai_feedback.reason'))
            : null;
        $expiresAt = now()->addMinutes(30);

        return [
            'id' => (string) $submission->public_id,
            'project_id' => (int) $submission->project_id,
            'submission_status' => ProjectSubmissionLifecycle::submissionStatus($reviewStatus),
            'can_submit' => $reviewStatus === ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
            'can_continue' => $reviewStatus === ProjectSubmission::STATUS_PASSED,
            'assessment_type' => $outcome['assessment_type'],
            'skill_verified' => $outcome['skill_verified'],
            'feedback_level' => (string) $contract['project_feedback_level'],
            'report_enabled' => $reportEnabled,
            'report_status' => $reportStatus,
            'can_retry_report' => $canRetryReport,
            'report_failure_category' => $reportFailure['category'] ?? null,
            'report_retry_after_seconds' => $canRetryReport
                ? ($reportFailure['retry_after_seconds'] ?? 3)
                : 0,
            'report_retry_endpoint' => $canRetryReport
                ? "/api/v1/project-submissions/{$submission->public_id}/report/retry"
                : null,
            'reply_enabled' => $replyEnabled,
            'can_reply' => (bool) ($threadPayload['can_reply'] ?? false),
            'feedback' => $outcome['feedback'],
            'attachments' => $submission->aiInputAttachments->map(
                fn (AiInputAttachment $attachment): array => [
                    'id' => (string) $attachment->public_id,
                    'name' => (string) $attachment->original_file_name,
                    'mime_type' => (string) $attachment->mime_type,
                    'size_bytes' => (int) $attachment->size_bytes,
                    'download_url' => URL::temporarySignedRoute(
                        'api.project-input-attachments.download',
                        $expiresAt,
                        [
                            'attachment' => $attachment->public_id,
                            'user' => $attachment->user_id,
                        ]
                    ),
                    'download_url_expires_at' => $expiresAt->toIso8601String(),
                ]
            )->values()->all(),
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'reviewed_at' => $outcome['reviewed_at']?->toIso8601String(),
            'poll_after_seconds' => (
                $reviewStatus === ProjectSubmission::STATUS_PENDING
                || $reportStatus === ProjectSubmissionLifecycle::REPORT_QUEUED
            ) ? 3 : null,
            'feedback_thread' => $threadPayload,
        ];
    }

    /** @return array<string,mixed> */
    private function contract(ProjectSubmission $submission): array
    {
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = data_get($snapshot, 'access.terms');

        return $this->accessPlans->publicPayloadFromTerms(is_array($terms) ? $terms : []);
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    private function threadSummary(
        ProjectFeedbackThread $thread,
        array $contract,
        string $reportStatus
    ): array
    {
        return [
            'id' => (string) $thread->public_id,
            'feedback_level' => (string) $contract['project_feedback_level'],
            'can_reply' => $this->feedbackThreads->activeReplyContract($thread) !== null,
            'reply_enabled' => (bool) $contract['project_thread_reply_enabled'],
            'status' => (string) $thread->status,
            'report_status' => $reportStatus,
            'remaining_messages' => 0,
            'messages' => [],
        ];
    }

    private function canRetryInitialReport(ProjectSubmission $submission, string $reportStatus): bool
    {
        if ($reportStatus !== ProjectSubmissionLifecycle::REPORT_FAILED) {
            return false;
        }
        $metadata = is_array($submission->submission_metadata)
            ? $submission->submission_metadata
            : [];
        $requestId = (string) data_get($metadata, 'ai_feedback.request_id', $submission->public_id);
        $event = AiUsageEvent::query()
            ->where('request_id', $requestId)
            ->where('feature', AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK)
            ->first();

        return ProjectReportRetryPolicy::allows(
            (string) data_get($metadata, 'ai_feedback.reason', ''),
            (int) data_get($metadata, 'ai_feedback.retry_count', 0),
            $event?->status,
            (string) data_get($event?->metadata, 'provider_call_state', ''),
            trim((string) data_get($event?->metadata, 'accepted_response', '')) !== ''
        );
    }
}
