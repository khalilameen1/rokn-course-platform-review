<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CourseCompleted;
use App\Jobs\SendAiUsageThresholdAlert;
use App\Jobs\SendFinancialAnomalyAlert;
use App\Listeners\AwardCourseCompletionReward;
use App\Listeners\AwardLevelBadge;
use App\Models\InternalSignal;
use App\Models\Course;
use App\Models\Project;
use App\Models\User;
use App\Models\ProjectSubmission;
use App\Models\CourseSection;
use App\Support\RoknAppLink;

final readonly class InternalSignalHandler
{
    public function __construct(
        private AwardLevelBadge $badges,
        private AwardCourseCompletionReward $rewards,
        private LearningRewardService $learningRewards,
        private AiPlatformUsageMonitor $aiUsage,
        private CourseAccessPlanService $accessPlans,
        private InternalSignalService $internalSignals,
        private CurriculumCompletionService $curriculumCompletion,
        private CourseStagedAuthoringService $stagedAuthoring
    ) {
    }

    public function handle(InternalSignal $signal): void
    {
        $payload = is_array($signal->payload) ? $signal->payload : [];
        switch ($signal->type) {
            case 'course.completed':
                $this->courseCompleted($payload);
                return;
            case 'course.completed.badge':
                $this->badges->handle($this->courseEvent($payload));
                return;
            case 'course.completed.reward':
                $this->rewards->handle($this->courseEvent($payload));
                return;
            case 'financial_anomaly.opened':
                $this->fanOutFinancialAlert($payload);
                return;
            case 'project.passed.first_reward':
                $this->projectPassedReward($payload);
                return;
            case 'project.review.notification':
                $this->projectReviewNotification($payload);
                return;
            case 'financial_anomaly.alert_admin':
                (new SendFinancialAnomalyAlert(
                    (int) ($payload['anomaly_id'] ?? 0),
                    (int) ($payload['admin_id'] ?? 0),
                    (string) ($payload['occurrence'] ?? '')
                ))->handle();
                return;
            case 'ai_usage.settled':
                $this->aiUsage->record((int) ($payload['event_id'] ?? 0));
                return;
            case 'ai_usage.threshold':
                $this->fanOutAiAlert($payload);
                return;
            case 'ai_usage.threshold_admin':
                (new SendAiUsageThresholdAlert(
                    (string) ($payload['metric'] ?? ''),
                    (string) ($payload['period'] ?? ''),
                    max(0, (int) ($payload['actual'] ?? 0)),
                    max(0, (int) ($payload['threshold'] ?? 0)),
                    (int) ($payload['admin_id'] ?? 0)
                ))->handle();
                return;
            case 'course.attachments.grant':
                $this->grantCourseAttachments($payload);
                return;
            default:
                throw new \UnexpectedValueException(
                    'Unknown internal signal type: ' . $signal->type
                );
        }
    }

    private function courseCompleted(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $courseId = (int) ($payload['course_id'] ?? 0);
        $revision = max(0, (int) ($payload['curriculum_revision'] ?? 0));
        if ($revision === 0) {
            $revision = (int) ($this->curriculumCompletion->markCompleted(
                $userId,
                $courseId
            ) ?? 0);
            if ($revision === 0) {
                return;
            }
        }
        $revisionIdentity = $revision > 0 ? ":revision:{$revision}" : '';
        $effectPayload = ['user_id' => $userId, 'course_id' => $courseId];
        if ($revision > 0) {
            $effectPayload['curriculum_revision'] = $revision;
        }
        foreach (['badge', 'reward'] as $effect) {
            $payloadForEffect = $effectPayload;
            if ($effect === 'reward' && array_key_exists('reward_contract', $payload)) {
                $payloadForEffect['reward_contract'] = $payload['reward_contract'];
            }
            $this->internalSignals->record(
                'course.completed.' . $effect,
                "user:{$userId}:course:{$courseId}{$revisionIdentity}:effect:{$effect}",
                $payloadForEffect,
                'course_enrollment',
                "{$userId}:{$courseId}"
            );
        }
    }

    private function courseEvent(array $payload): CourseCompleted
    {
        return new CourseCompleted(
            (int) ($payload['user_id'] ?? 0),
            (int) ($payload['course_id'] ?? 0),
            isset($payload['curriculum_revision'])
                ? (int) $payload['curriculum_revision']
                : null,
            is_array($payload['reward_contract'] ?? null)
                ? $payload['reward_contract']
                : null
        );
    }

    private function fanOutFinancialAlert(array $payload): void
    {
        $anomalyId = (int) ($payload['anomaly_id'] ?? 0);
        $occurrence = (string) ($payload['occurrence'] ?? 'initial');
        foreach ($this->adminIds() as $adminId) {
            $this->internalSignals->record(
                'financial_anomaly.alert_admin',
                "anomaly:{$anomalyId}:occurrence:{$occurrence}:admin:{$adminId}",
                [
                    'anomaly_id' => $anomalyId,
                    'admin_id' => $adminId,
                    'occurrence' => $occurrence,
                ],
                'financial_anomaly',
                $anomalyId
            );
        }
    }

    private function grantCourseAttachments(array $payload): void
    {
        $course = Course::query()->find((int) ($payload['course_id'] ?? 0));
        $revision = max(0, (int) ($payload['published_revision'] ?? 0));
        if (!$course || $revision === 0
            || (int) $course->last_published_authoring_version < $revision) {
            return;
        }

        $this->accessPlans->grantAttachmentsToCurrentEnrollments(
            $course,
            (bool) ($payload['chat'] ?? false),
            (bool) ($payload['project'] ?? false)
        );
    }

    private function projectPassedReward(array $payload): void
    {
        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        $projectId = (int) ($payload['project_id'] ?? 0);
        $project = Project::query()->find($projectId);
        if (!$user || ($projectId <= 0 && !$project)) {
            return;
        }

        $this->learningRewards->awardFirstProject(
            $user,
            $project ?? $projectId,
            is_array($payload['reward_contract'] ?? null)
                ? $payload['reward_contract']
                : null,
            isset($payload['course_id']) ? (int) $payload['course_id'] : null
        );
    }

    private function projectReviewNotification(array $payload): void
    {
        $submission = ProjectSubmission::query()->find((int) ($payload['submission_id'] ?? 0));
        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        $historicalProjectId = (int) ($payload['project_id'] ?? 0);
        if (!$submission || !$user || $historicalProjectId <= 0) return;

        $status = (string) ($payload['status'] ?? '');
        if (
            (int) $submission->user_id !== (int) $user->id
            || !hash_equals((string) $submission->review_status, $status)
        ) {
            return;
        }

        $projectId = $this->stagedAuthoring->currentEntityId(
            Project::class,
            $historicalProjectId
        ) ?? $historicalProjectId;
        $section = CourseSection::query()
            ->where('sectionable_type', Project::class)
            ->where('sectionable_id', $projectId)
            ->first();
        $course = Course::query()->find((int) (
            $section?->course_id ?: ($payload['course_id'] ?? 0)
        ));
        if (!$course) return;

        $passed = $status === ProjectSubmission::STATUS_PASSED;
        if (!$passed && $status !== ProjectSubmission::STATUS_NEEDS_RESUBMISSION) return;

        StudentNotificationService::notifyUser(
            $user,
            StudentNotificationService::TYPE_PROJECT_UPDATE,
            $passed ? 'تم اعتماد مشروعك' : 'مشروعك يحتاج تعديلًا',
            $passed ? 'Your project was approved' : 'Your project needs changes',
            $passed ? (string) $course->title : 'راجع الملاحظات وأرسل المشروع من جديد',
            $passed ? (string) ($course->name_en ?: $course->title) : 'Review the feedback and submit your project again.',
            $section
                ? RoknAppLink::project((int) $course->id, $projectId)
                : RoknAppLink::course((int) $course->id),
            Course::class,
            (int) $course->id,
            'project-review:' . $submission->public_id . ':' . $status,
            [
                'course' => (string) $course->title,
                'project' => (string) ($section?->title ?: 'مشروع العبور'),
            ],
            $course->image
        );
    }

    private function fanOutAiAlert(array $payload): void
    {
        foreach ($this->adminIds() as $adminId) {
            $identity = hash('sha256', json_encode([
                $payload['metric'] ?? '',
                $payload['period'] ?? '',
                $payload['actual'] ?? 0,
                $payload['threshold'] ?? 0,
                $adminId,
            ], JSON_UNESCAPED_SLASHES) ?: '');
            $this->internalSignals->record(
                'ai_usage.threshold_admin',
                $identity,
                array_merge($payload, ['admin_id' => $adminId]),
                'ai_usage_period',
                (string) ($payload['metric'] ?? '') . ':' . (string) ($payload['period'] ?? '')
            );
        }
    }

    /** @return list<int> */
    private function adminIds(): array
    {
        return User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
