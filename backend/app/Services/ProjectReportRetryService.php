<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateProjectFeedback;
use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\DurableJobDispatch;
use App\Support\ProjectReportRetryPolicy;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProjectReportRetryService
{
    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private CourseChatAccessService $courseAccess
    ) {
    }

    /** @return array{state: string, submission: ProjectSubmission} */
    public function request(ProjectSubmission $submission, User $user): array
    {
        if (!$this->isAvailable($submission, $user)) {
            return ['state' => 'unavailable', 'submission' => $submission];
        }

        $state = DB::transaction(function () use ($submission): string {
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->review_status !== ProjectSubmission::STATUS_PASSED) {
                return 'unsafe';
            }

            $metadata = is_array($locked->submission_metadata) ? $locked->submission_metadata : [];
            if ((string) data_get($metadata, 'ai_feedback.status') !== 'unavailable') {
                return 'not_terminal';
            }

            $reason = (string) data_get($metadata, 'ai_feedback.reason', '');
            $retryCount = (int) data_get($metadata, 'ai_feedback.retry_count', 0);
            $requestId = (string) data_get($metadata, 'ai_feedback.request_id', $locked->public_id);
            $event = AiUsageEvent::query()
                ->where('request_id', $requestId)
                ->where('feature', AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK)
                ->lockForUpdate()
                ->first();

            if (!ProjectReportRetryPolicy::allows(
                $reason,
                $retryCount,
                $event?->status,
                (string) data_get($event?->metadata, 'provider_call_state', ''),
                trim((string) data_get($event?->metadata, 'accepted_response', '')) !== ''
            )) {
                if ($retryCount >= 2) {
                    return 'exhausted';
                }

                return $event !== null && !in_array($event->status, ['completed', 'failed'], true)
                    ? 'not_terminal'
                    : 'unsafe';
            }

            if ($event?->status === 'failed') {
                $requestId = (string) Str::uuid();
            }

            $metadata['ai_feedback'] = [
                'status' => 'queued',
                'request_id' => $requestId,
                'retry_count' => $retryCount + 1,
                'retry_requested_at' => now()->toIso8601String(),
            ];
            $locked->forceFill(['submission_metadata' => $metadata])->save();

            return 'queued';
        }, 3);

        if ($state === 'queued') {
            try {
                DurableJobDispatch::afterCommit(new GenerateProjectFeedback((int) $submission->id));
            } catch (\Throwable $exception) {
                // The queued state is durable; scheduled recovery can dispatch
                // after a transient broker outage without another HTTP retry.
                report($exception);
            }
        }

        return ['state' => $state, 'submission' => $submission->fresh()];
    }

    private function isAvailable(ProjectSubmission $submission, User $user): bool
    {
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = data_get($snapshot, 'access.terms');
        $courseId = (int) data_get($snapshot, 'course_id', 0);
        $enrollmentId = (int) data_get($snapshot, 'access.enrollment_id', 0);
        $contract = $this->accessPlans->publicPayloadFromTerms(is_array($terms) ? $terms : []);
        $enrollment = $courseId > 0 && $enrollmentId > 0
            ? $this->courseAccess->activeCapturedEnrollmentFor((int) $user->id, $courseId, $enrollmentId)
            : null;

        return $submission->review_status === ProjectSubmission::STATUS_PASSED
            && (bool) $contract['project_report_enabled']
            && $enrollment !== null
            && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment);
    }
}
