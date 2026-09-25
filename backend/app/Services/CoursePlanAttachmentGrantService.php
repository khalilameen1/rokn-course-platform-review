<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Support\CourseAccessPlanSnapshot;
use Illuminate\Support\Facades\DB;

/** Explicit additive attachment grants, separate from editing commercial offers. */
final readonly class CoursePlanAttachmentGrantService
{
    /** Explicit additive grant; never runs as a side effect of editing a live plan. */
    public function grantAttachmentsToCurrentEnrollments(
        Course $course,
        bool $grantCourseChat,
        bool $grantProjectFollowup
    ): int
    {
        if (!$grantCourseChat && !$grantProjectFollowup) return 0;
        $planRows = $course->accessPlans()->get();
        $plans = $planRows->keyBy('id');
        $plansByCode = $planRows->keyBy('code');
        $updated = 0;
        CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->whereNotNull('access_plan_id')
            ->active()
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($enrollments) use (
                $course, $plans, $plansByCode, $grantCourseChat, $grantProjectFollowup, &$updated
            ): void {
                foreach ($enrollments as $enrollment) {
                    // Re-read under the same row lock used by a purchase/upgrade.
                    // A queued grant must not overwrite a newer purchased tier.
                    $changed = DB::transaction(function () use (
                        $course, $enrollment, $plans, $plansByCode, $grantCourseChat, $grantProjectFollowup
                    ): bool {
                        $current = CourseEnrollment::query()
                            ->whereKey($enrollment->getKey())
                            ->where('course_id', $course->getKey())
                            ->active()
                            ->lockForUpdate()
                            ->first();
                        if (!$current || !$current->access_plan_id) return false;
                        $snapshot = $current->access_plan_snapshot;
                        // Old plan identities can survive publication. Match
                        // the current offer by stable code without rewriting IDs.
                        $plan = $plans->get($current->access_plan_id)
                            ?: $plansByCode->get((string) ($snapshot['code'] ?? ''));
                        return $plan && $this->grantToEnrollment(
                            $current, $plan, $grantCourseChat, $grantProjectFollowup
                        );
                    }, 3);
                    if ($changed) $updated++;
                }
            });
        return $updated;
    }

    private function grantToEnrollment(
        CourseEnrollment $enrollment,
        CourseAccessPlan $plan,
        bool $grantCourseChat,
        bool $grantProjectFollowup
    ): bool {
        $snapshot = $enrollment->access_plan_snapshot;
        if (!is_array($snapshot) || (int) ($snapshot['version'] ?? 0) < 3) return false;
        $original = $snapshot;
        $existingChat = (int) $snapshot['version'] >= 4
            && (bool) ($snapshot['chat_attachments_enabled'] ?? false);
        $existingProject = (int) $snapshot['version'] >= 5
            && (bool) ($snapshot['project_followup_attachments_enabled'] ?? false);
        $addChat = $grantCourseChat && (bool) ($snapshot['chat_enabled'] ?? false)
            && (bool) $plan->chat_attachments_enabled;
        $addProject = $grantProjectFollowup
            && ($snapshot['project_feedback_level'] ?? '') === CourseAccessPlan::FEEDBACK_ENHANCED
            && (bool) $plan->project_followup_attachments_enabled;
        if (!$addChat && !$addProject) return false;

        $snapshot['version'] = CourseAccessPlanSnapshot::CURRENT_VERSION;
        $snapshot['projects_enabled'] = (bool) ($snapshot['projects_enabled'] ?? true);
        $snapshot['chat_attachments_enabled'] = $existingChat || $addChat;
        $snapshot['chat_attachment_max_files'] = max(
            $existingChat ? (int) $snapshot['chat_attachment_max_files'] : 0,
            $addChat ? min(5, max(1, (int) $plan->chat_attachment_max_files)) : 0
        );
        $snapshot['project_followup_attachments_enabled'] = $existingProject || $addProject;
        $snapshot['project_followup_attachment_max_files'] = max(
            $existingProject ? (int) $snapshot['project_followup_attachment_max_files'] : 0,
            $addProject ? min(5, max(1, (int) $plan->project_followup_attachment_max_files)) : 0
        );
        if ($snapshot === $original) return false;
        CourseAccessPlanSnapshot::assertValidForPlan((int) $enrollment->access_plan_id, $snapshot);
        $enrollment->forceFill(['access_plan_snapshot' => $snapshot])->save();
        return true;
    }
}
