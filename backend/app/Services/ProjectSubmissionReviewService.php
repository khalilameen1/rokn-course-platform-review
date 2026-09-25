<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateProjectFeedback;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\DurableJobDispatch;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Records a review decision and its progression/report handoff, never learner uploads. */
final class ProjectSubmissionReviewService
{
    public function __construct(
        private readonly InternalSignalService $internalSignals,
        private readonly LearningAchievementSignalService $achievementSignals,
        private readonly CourseEntitlementService $courseAccess,
        private readonly CourseAccessPlanService $accessPlans,
        private readonly CourseRevisionResolver $revisionResolver,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly ProjectSubmissionFileRetentionService $fileRetention,
        private readonly CourseCompletionService $courseCompletion
    ) {
    }

    public function applyEvaluationOutcome(
        ProjectSubmission $submission,
        string $requestId,
        bool $passed,
        string $feedback
    ): ProjectSubmission {
        $reviewed = DB::transaction(function () use ($submission, $requestId, $passed, $feedback): ProjectSubmission {
            $active = User::query()->whereKey($submission->user_id)->where('active', true)->lockForUpdate()->exists();
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if (
                !$active
                || $locked->review_status !== ProjectSubmission::STATUS_PENDING
                || data_get($locked->submission_metadata, 'evaluation.request_id') !== $requestId
            ) {
                return $locked;
            }
            // A late worker cannot revoke progression granted by an earlier attempt.
            $passed = $passed || $this->hasPassedProject((int) $locked->user_id, (int) $locked->project_id);
            $metadata = (array) $locked->submission_metadata;
            $metadata['evaluation'] = array_merge((array) $metadata['evaluation'], [
                'status' => 'ready',
                'decision' => $passed ? 'relevant_effort' : 'needs_changes',
                'completed_at' => now()->toIso8601String(),
                'retry_safe' => false,
            ]);
            unset($metadata['evaluation']['reason'], $metadata['evaluation']['failed_at']);
            $locked->forceFill(['submission_metadata' => $metadata, 'auto_pass_at' => null])->save();
            return $this->applyReviewOutcome($locked, $passed, 'relevance_review', $feedback);
        }, 3);
        if ($reviewed->review_status === ProjectSubmission::STATUS_PASSED) {
            $this->completeReportHandoff($reviewed);
        }
        $this->fileRetention->purgeIfEligible($reviewed);
        return $reviewed->fresh();
    }

    public function reviewByStaff(
        ProjectSubmission $submission,
        User $reviewer,
        bool $passed,
        ?string $feedback = null
    ): ProjectSubmission {
        $reviewerRole = Str::lower((string) $reviewer->role);
        if (!(bool) $reviewer->active || !in_array($reviewerRole, ['admin', 'moderator'], true)) {
            throw new AuthorizationException('Only an active dashboard reviewer can review project submissions.');
        }

        $reviewed = DB::transaction(function () use ($submission, $reviewer, $passed, $feedback): ProjectSubmission {
            // Serialize a human decision with account deletion at the same
            // aggregate-owner boundary used by learner submission. A form
            // left open for a deleted account must not restore scrubbed text,
            // progress or feedback records.
            $learner = User::query()
                ->whereKey($submission->user_id)
                ->lockForUpdate()
                ->first();
            if (!$learner) {
                throw ValidationException::withMessages([
                    'submission' => ['تم حذف حساب الطالب، لذلك لم يُسجل قرار جديد.'],
                ]);
            }
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $latestAttemptId = ProjectSubmission::query()
                ->where('user_id', $locked->user_id)
                ->where('project_id', $locked->project_id)
                ->max('id');
            if ((int) $latestAttemptId !== (int) $locked->id) {
                throw ValidationException::withMessages([
                    'submission' => ['هذه محاولة قديمة. راجع أحدث محاولة للطالب قبل تسجيل القرار.'],
                ]);
            }
            $isGracefulFallback = $locked->review_status === ProjectSubmission::STATUS_PASSED
                && $locked->review_source === 'graceful_fallback';
            if (
                $locked->review_status !== ProjectSubmission::STATUS_PENDING
                && !$isGracefulFallback
            ) {
                throw ValidationException::withMessages([
                    'submission' => ['تمت مراجعة هذه المحاولة بالفعل، لذلك لم يتغير القرار المسجل.'],
                ]);
            }

            $wasAlreadyPassed = $this->hasPassedProject(
                (int) $locked->user_id,
                (int) $locked->project_id
            );
            if (!$passed && $wasAlreadyPassed) {
                throw ValidationException::withMessages([
                    'submission' => ['لا يمكن سحب حق الطالب في الاستكمال بعد قبوله تلقائيًا. يمكن اعتماد جودة العمل يدويًا عند القبول.'],
                ]);
            }

            $reviewFeedback = trim((string) $feedback);
            if ($reviewFeedback === '') {
                $reviewFeedback = $passed
                    ? 'راجع فريق ركن المحاولة وقبلها'
                    : 'راجع فريق ركن المحاولة وطلب إعادة إرسالها';
            }

            return $this->applyReviewOutcome(
                $locked,
                $passed,
                'admin_manual',
                $reviewFeedback,
                $reviewer
            );
        });

        $this->completeReportHandoff($reviewed);

        return $reviewed;
    }

    private function applyReviewOutcome(
        ProjectSubmission $locked,
        bool $passed,
        string $source,
        string $feedback,
        ?User $reviewer = null
    ): ProjectSubmission {
        $status = $passed
            ? ProjectSubmission::STATUS_PASSED
            : ProjectSubmission::STATUS_NEEDS_RESUBMISSION;
        $isParticipationAcceptance = $passed && $source === 'graceful_fallback';
        // The forgiving fallback grants progression only. A numeric score is
        // evidence of assessment, so it is reserved for a human review.
        $score = $source === 'relevance_review' ? null : ($passed ? ($isParticipationAcceptance ? null : 100) : 0);
        $reviewedAt = now();
        $metadata = is_array($locked->submission_metadata)
            ? $locked->submission_metadata
            : [];
        $metadata['assessment_type'] = $isParticipationAcceptance
            ? 'participation'
            : ($source === 'admin_manual' ? 'human_review' : ($source === 'relevance_review' ? 'relevance_review' : 'effort_guard'));
        $metadata['skill_verified'] = $passed && $source === 'admin_manual';
        $metadata['progression_credit'] = $passed;
        if ($passed
            && $this->submissionReportWasIncluded($locked)
            && data_get($metadata, 'ai_feedback.status') !== 'ready') {
            // Persist intent before the queue dispatch so a lost enqueue can
            // be recovered. The job terminally classifies pass-only plans.
            $metadata['ai_feedback'] = $this->submissionIncludesProjectReport($locked)
                ? [
                    'status' => 'queued',
                    'queued_at' => $reviewedAt->toIso8601String(),
                ]
                : [
                    'status' => 'unavailable',
                    'reason' => 'report_not_included',
                    'request_id' => (string) $locked->public_id,
                    'retry_count' => 0,
                    'failed_at' => $reviewedAt->toIso8601String(),
                ];
        }

        $locked->update([
            'review_status' => $status,
            'review_source' => $source,
            'score' => $score,
            'feedback' => $feedback,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $reviewer?->id,
            'submission_metadata' => $metadata,
        ]);

        $currentProjectId = $this->revisionResolver->currentEntityId(
            Project::class,
            (int) $locked->project_id
        );

        $projectSection = CourseSection::query()
            ->where('sectionable_type', Project::class)
            ->where('sectionable_id', $currentProjectId ?: $locked->project_id)
            ->first();
        $this->internalSignals->record(
            'project.review.notification',
            "submission:{$locked->public_id}:status:{$status}",
            [
                'submission_id' => (int) $locked->id,
                'user_id' => (int) $locked->user_id,
                'project_id' => (int) ($currentProjectId ?: $locked->project_id),
                'course_id' => (int) (
                    $projectSection?->course_id
                    ?? data_get($locked->evaluation_snapshot, 'course_id', 0)
                ),
                'status' => $status,
            ],
            ProjectSubmission::class,
            (int) $locked->id
        );

        if (!$passed) {
            return $locked->fresh();
        }

        $this->achievementSignals->record(
            'project.passed.first_reward',
            "user:{$locked->user_id}:project:{$locked->project_id}",
            [
                'user_id' => (int) $locked->user_id,
                'project_id' => (int) $locked->project_id,
            ],
            ProjectSubmission::class,
            (int) $locked->id
        );

        if (!$projectSection) {
            return $locked->fresh();
        }

        $this->courseCompletion->recordPassedProject(
            (int) $locked->user_id,
            $projectSection
        );

        return $locked->fresh();
    }

    private function queueFeedback(int $submissionId): void
    {
        try {
            DurableJobDispatch::afterCommit(new GenerateProjectFeedback($submissionId));
        } catch (\Throwable $exception) {
            // The committed queued marker is the durable handoff. Recovery
            // will enqueue it when the broker returns, so a passed project
            // must not look failed merely because that first enqueue failed.
            report($exception);
        }
    }

    private function submissionIncludesProjectReport(ProjectSubmission $submission): bool
    {
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = $snapshot ? data_get($snapshot, 'access.terms') : null;

        if (!is_array($terms)) {
            return false;
        }

        $courseId = (int) data_get($snapshot, 'course_id', 0);
        $enrollmentId = (int) data_get($snapshot, 'access.enrollment_id', 0);
        if ($courseId <= 0 || $enrollmentId <= 0) {
            return false;
        }

        $enrollment = $this->courseAccess->activeCapturedEnrollmentFor(
            (int) $submission->user_id,
            $courseId,
            $enrollmentId
        );

        return $enrollment !== null
            && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment)
            && (bool) $this->accessPlans->publicPayloadFromTerms($terms)['project_report_enabled'];
    }

    private function submissionReportWasIncluded(ProjectSubmission $submission): bool
    {
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = $snapshot ? data_get($snapshot, 'access.terms') : null;

        return is_array($terms)
            && (bool) $this->accessPlans
                ->publicPayloadFromTerms($terms)['project_report_enabled'];
    }

    private function completeReportHandoff(ProjectSubmission $submission): void
    {
        if (
            $submission->review_status === ProjectSubmission::STATUS_PASSED
            && $this->submissionIncludesProjectReport($submission)
        ) {
            // Feedback is a paid enhancement, never a gate. Queue/provider
            // failures cannot revoke the already granted progression.
            $this->queueFeedback((int) $submission->id);
            return;
        }

        $terminalReportFailure = data_get(
            $submission->submission_metadata,
            'ai_feedback.reason'
        ) === 'report_not_included';
        $this->fileRetention->purgeIfEligible($submission, $terminalReportFailure);
    }

    private function hasPassedProject(int $userId, int $projectId): bool
    {
        $currentProjectId = $this->revisionResolver
            ->currentLearnerEntityMap(Project::class, [$projectId])[$projectId] ?? $projectId;

        return $this->revisionReads
            ->passedProjectIds($userId, [$currentProjectId])
            ->contains($currentProjectId);
    }
}
