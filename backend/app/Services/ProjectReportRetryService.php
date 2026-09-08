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
        private CourseChatAccessService $courseAccess,
        private PaidAiCallExecutionService $paidCalls
    ) {
    }

    public function canRetry(ProjectSubmission $submission): bool
    {
        $user = $submission->user;

        return $user !== null
            && $this->decision($submission, $user)['state'] === 'allowed';
    }

    /** @return array{state: string, submission: ProjectSubmission} */
    public function request(ProjectSubmission $submission, User $user): array
    {
        $state = DB::transaction(function () use ($submission, $user): string {
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $decision = $this->decision($locked, $user, lockEvent: true);
            if ($decision['state'] !== 'allowed') {
                return $decision['state'];
            }
            $event = $decision['event'];
            $metadata = is_array($locked->submission_metadata) ? $locked->submission_metadata : [];
            $retryCount = (int) data_get($metadata, 'ai_feedback.retry_count', 0);
            $requestId = (string) data_get($metadata, 'ai_feedback.request_id', $locked->public_id);
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

    /** @return array{state: string, event: ?AiUsageEvent} */
    private function decision(ProjectSubmission $submission, User $user, bool $lockEvent = false): array
    {
        if (!$this->isAvailable($submission, $user)) {
            return ['state' => 'unavailable', 'event' => null];
        }
        $metadata = is_array($submission->submission_metadata) ? $submission->submission_metadata : [];
        if ((string) data_get($metadata, 'ai_feedback.status') !== 'unavailable') {
            return ['state' => 'not_terminal', 'event' => null];
        }
        $retryCount = (int) data_get($metadata, 'ai_feedback.retry_count', 0);
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $event = AiUsageEvent::query()
            ->where('request_id', (string) data_get($metadata, 'ai_feedback.request_id', $submission->public_id))
            ->where('feature', AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK)
            ->where('user_id', $submission->user_id)
            ->where('enrollment_id', (int) data_get($snapshot, 'access.enrollment_id'))
            ->when($lockEvent, fn ($query) => $query->lockForUpdate())
            ->first();
        $allowed = ProjectReportRetryPolicy::allows(
            (string) data_get($metadata, 'ai_feedback.reason', ''),
            $retryCount,
            $event?->status,
            (string) data_get($event?->metadata, 'provider_call_state', ''),
            trim((string) data_get($event?->metadata, 'accepted_response', '')) !== '',
            inputsPurged: trim((string) data_get($metadata, 'files_purged_at', '')) !== '',
            hasLandedResponse: $this->paidCalls->landedResult($event) !== null
        );
        if ($allowed) {
            return ['state' => 'allowed', 'event' => $event];
        }
        if ($retryCount >= 2) {
            return ['state' => 'exhausted', 'event' => $event];
        }
        $state = $event !== null && !in_array($event->status, ['completed', 'failed'], true)
            ? 'not_terminal'
            : 'unsafe';

        return ['state' => $state, 'event' => $event];
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
