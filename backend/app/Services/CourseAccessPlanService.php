<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Support\CourseAccessPlanSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Reads available offers and immutable purchased terms without authoring writes. */
final readonly class CourseAccessPlanService
{

    /** @return Collection<int, CourseAccessPlan> */
    public function publicPlans(Course $course, bool $lockForUpdate = false): Collection
    {
        $query = $course->accessPlans()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function selectedPlan(
        Course $course,
        string $code,
        bool $lockForUpdate = false
    ): CourseAccessPlan
    {
        $plans = $this->publicPlans($course, $lockForUpdate);
        if ($plans->isEmpty()) {
            throw ValidationException::withMessages([
                'access_plan_code' => ['فتح هذا الكورس متوقف مؤقتًا حتى تُنشر خطة متاحة.'],
            ]);
        }

        $normalized = strtolower(trim($code));
        if ($normalized === '') {
            throw ValidationException::withMessages([
                'access_plan_code' => ['اختر الفئة المناسبة لك.'],
            ]);
        }

        $plan = $plans->firstWhere('code', $normalized);
        if (!$plan) {
            throw ValidationException::withMessages([
                'access_plan_code' => ['هذه الخطة لم تعد متاحة. حدّث الصفحة واختر مرة أخرى.'],
            ]);
        }

        $this->assertPurchasableEconomics($plan);
        return $plan;
    }

    /** Guard new sales only; never re-price or revoke an existing receipt. */
    public function assertPurchasableEconomics(CourseAccessPlan $plan): void
    {
        if (!config('course_plans.enforce_commercial_floor') || (int) $plan->price_coins <= 0) return;
        $economics = app(CoursePlanEconomicsService::class)->evaluate($plan->getAttributes());
        if (!$economics['configured'] || !$economics['meets_floor']) {
            // Public callers must not receive internal provider costs or net
            // settlement rates from an administrator validation message.
            throw ValidationException::withMessages([
                'access_plan_code' => ['هذا الاشتراك غير متاح للشراء الآن جرّب لاحقًا'],
            ]);
        }
    }

    public function planForEnrollment(CourseEnrollment $enrollment): ?CourseAccessPlan
    {
        if (!$enrollment->access_plan_id) {
            return null;
        }

        return $enrollment->relationLoaded('accessPlan')
            ? $enrollment->accessPlan
            : $enrollment->accessPlan()->first();
    }

    public function snapshot(CourseAccessPlan $plan, ?CarbonInterface $purchasedAt = null): array
    {
        return [
            'version' => CourseAccessPlanSnapshot::CURRENT_VERSION,
            'plan_id' => (int) $plan->id,
            'code' => (string) $plan->code,
            'name_ar' => (string) $plan->name_ar,
            'price_coins' => (int) $plan->price_coins,
            'minimum_paid_coins' => (int) $plan->minimum_paid_coins,
            'sort_order' => (int) $plan->sort_order,
            'chat_enabled' => (bool) $plan->chat_enabled,
            'chat_message_limit' => (int) $plan->chat_message_limit,
            'chat_token_budget' => (int) $plan->chat_token_budget,
            'chat_attachments_enabled' => (bool) $plan->chat_attachments_enabled,
            'chat_attachment_max_files' => (int) $plan->chat_attachment_max_files,
            'project_followup_attachments_enabled' => (bool) $plan->project_followup_attachments_enabled,
            'project_followup_attachment_max_files' => (int) $plan->project_followup_attachment_max_files,
            // Provider budgets remain fixed-decimal receipt values.
            'ai_budget_usd' => $this->formatUsd($plan->ai_budget_usd),
            'request_reserve_usd' => $this->formatUsd($plan->request_reserve_usd),
            'project_feedback_token_budget' => (int) $plan->project_feedback_token_budget,
            'project_feedback_budget_usd' => $this->formatUsd($plan->project_feedback_budget_usd),
            'project_feedback_reserve_usd' => $this->formatUsd($plan->project_feedback_reserve_usd),
            'project_followup_message_limit' => (int) $plan->project_followup_message_limit,
            'project_followup_token_budget' => (int) $plan->project_followup_token_budget,
            'project_followup_budget_usd' => $this->formatUsd($plan->project_followup_budget_usd),
            'project_followup_reserve_usd' => $this->formatUsd($plan->project_followup_reserve_usd),
            'max_output_tokens' => (int) $plan->max_output_tokens,
            'model_override' => $plan->model_override,
            'project_feedback_level' => (string) $plan->project_feedback_level,
            'project_output_enabled' => (bool) $plan->project_output_enabled,
            'certificate_enabled' => (bool) $plan->certificate_enabled,
            'projects_enabled' => (bool) ($plan->projects_enabled ?? true),
            'purchased_at' => ($purchasedAt ?: now())->toIso8601String(),
        ];
    }

    public function termsForEnrollment(CourseEnrollment $enrollment): ?array
    {
        $snapshot = is_array($enrollment->access_plan_snapshot)
            ? $enrollment->access_plan_snapshot
            : null;
        if (!$snapshot || !$enrollment->access_plan_id) {
            return null;
        }

        try {
            CourseAccessPlanSnapshot::assertValidForPlan(
                (int) $enrollment->access_plan_id,
                $snapshot
            );
        } catch (\LogicException) {
            // Purchased terms are a receipt. Falling back to the mutable live
            // plan would silently rewrite limits, cost ceilings and benefits
            // for an old order. An incomplete legacy receipt therefore loses
            // variable/tier benefits until it is explicitly repaired.
            return null;
        }

        return $snapshot;
    }

    /** Code access is watch-only until a paid plan captures project access. */
    public function projectsEnabledForEnrollment(CourseEnrollment $enrollment): bool
    {
        if (!$enrollment->access_plan_id) {
            return $enrollment->order?->payment_method !== \App\Models\Order::PAYMENT_METHOD_COURSE_CODE;
        }
        $terms = $this->termsForEnrollment($enrollment);
        return $terms !== null && (bool) ($terms['projects_enabled'] ?? true);
    }

    /** Public value contract; provider names and dollar budgets never leak to the learner. */
    public function publicPayload(CourseAccessPlan $plan): array
    {
        return $this->publicPayloadFromTerms([
            'code' => $plan->code,
            'name_ar' => $plan->name_ar,
            'price_coins' => $plan->price_coins,
            'minimum_paid_coins' => $plan->minimum_paid_coins,
            'chat_enabled' => $plan->chat_enabled,
            'chat_message_limit' => $plan->chat_message_limit,
            'chat_token_budget' => $plan->chat_token_budget,
            'chat_attachments_enabled' => $plan->chat_attachments_enabled,
            'chat_attachment_max_files' => $plan->chat_attachment_max_files,
            'project_followup_attachments_enabled' => $plan->project_followup_attachments_enabled,
            'project_followup_attachment_max_files' => $plan->project_followup_attachment_max_files,
            'ai_budget_usd' => $plan->ai_budget_usd,
            'request_reserve_usd' => $plan->request_reserve_usd,
            'max_output_tokens' => $plan->max_output_tokens,
            'project_feedback_level' => $plan->project_feedback_level,
            'project_feedback_token_budget' => $plan->project_feedback_token_budget,
            'project_feedback_budget_usd' => $plan->project_feedback_budget_usd,
            'project_feedback_reserve_usd' => $plan->project_feedback_reserve_usd,
            'project_followup_message_limit' => $plan->project_followup_message_limit,
            'project_followup_token_budget' => $plan->project_followup_token_budget,
            'project_followup_budget_usd' => $plan->project_followup_budget_usd,
            'project_followup_reserve_usd' => $plan->project_followup_reserve_usd,
            'project_output_enabled' => $plan->project_output_enabled,
            'certificate_enabled' => $plan->certificate_enabled,
            'projects_enabled' => $plan->projects_enabled ?? true,
        ]);
    }

    /** @param array<string,mixed> $terms */
    public function publicPayloadFromTerms(array $terms): array
    {
        $feedback = (string) ($terms['project_feedback_level'] ?? 'pass_only');
        if (!in_array($feedback, CourseAccessPlan::PROJECT_FEEDBACK_LEVELS, true)) {
            $feedback = CourseAccessPlan::FEEDBACK_PASS_ONLY;
        }

        $maxOutputTokens = max(1, (int) ($terms['max_output_tokens'] ?? 0));
        $chatBudget = max(0, (float) ($terms['ai_budget_usd'] ?? 0));
        $chatReserve = max(0, (float) ($terms['request_reserve_usd'] ?? 0));
        $chatEnabled = (bool) ($terms['chat_enabled'] ?? false)
            && max(0, (int) ($terms['chat_message_limit'] ?? 0)) > 0
            && max(0, (int) ($terms['chat_token_budget'] ?? 0)) >= $maxOutputTokens
            && $chatBudget > 0
            && $chatReserve > 0
            && $chatReserve <= $chatBudget;
        $chatAttachmentsEnabled = $chatEnabled
            && (bool) ($terms['chat_attachments_enabled'] ?? false)
            && max(0, (int) ($terms['chat_attachment_max_files'] ?? 0)) > 0;
        $reportBudget = max(0, (float) ($terms['project_feedback_budget_usd'] ?? 0));
        $reportReserve = max(0, (float) ($terms['project_feedback_reserve_usd'] ?? 0));
        $reportEnabled = in_array($feedback, [
            CourseAccessPlan::FEEDBACK_REPORT,
            CourseAccessPlan::FEEDBACK_ENHANCED,
        ], true)
            && max(0, (int) ($terms['project_feedback_token_budget'] ?? 0)) >= $maxOutputTokens
            && $reportBudget > 0
            && $reportReserve > 0
            && $reportReserve <= $reportBudget;
        $followupBudget = max(0, (float) ($terms['project_followup_budget_usd'] ?? 0));
        $followupReserve = max(0, (float) ($terms['project_followup_reserve_usd'] ?? 0));
        $threadEnabled = $feedback === CourseAccessPlan::FEEDBACK_ENHANCED
            && $reportEnabled
            && max(0, (int) ($terms['project_followup_message_limit'] ?? 0)) > 0
            && max(0, (int) ($terms['project_followup_token_budget'] ?? 0)) >= $maxOutputTokens
            && $followupBudget > 0
            && $followupReserve > 0
            && $followupReserve <= $followupBudget;
        $projectAttachmentsEnabled = $threadEnabled
            && (bool) ($terms['project_followup_attachments_enabled'] ?? false)
            && max(0, (int) ($terms['project_followup_attachment_max_files'] ?? 0)) > 0;
        $effectiveFeedback = !$reportEnabled
            ? CourseAccessPlan::FEEDBACK_PASS_ONLY
            : ($threadEnabled
                ? CourseAccessPlan::FEEDBACK_ENHANCED
                : CourseAccessPlan::FEEDBACK_REPORT);

        return [
            'code' => (string) ($terms['code'] ?? ''),
            'name' => (string) ($terms['name_ar'] ?? ''),
            'price_coins' => max(0, (int) ($terms['price_coins'] ?? 0)),
            'minimum_paid_coins' => max(0, (int) ($terms['minimum_paid_coins'] ?? 0)),
            'chat_enabled' => $chatEnabled,
            'chat_message_limit' => $chatEnabled
                ? max(0, (int) ($terms['chat_message_limit'] ?? 0))
                : 0,
            'chat_attachments_enabled' => $chatAttachmentsEnabled,
            'chat_attachment_max_files' => $chatAttachmentsEnabled
                ? min(5, max(1, (int) ($terms['chat_attachment_max_files'] ?? 1)))
                : 0,
            'project_feedback_level' => $effectiveFeedback,
            'project_report_enabled' => $reportEnabled,
            'project_thread_reply_enabled' => $threadEnabled,
            'project_message_limit' => $threadEnabled
                ? max(0, (int) ($terms['project_followup_message_limit'] ?? 0))
                : 0,
            'project_token_budget' => $threadEnabled
                ? max(0, (int) ($terms['project_followup_token_budget'] ?? 0))
                : 0,
            'project_attachments_enabled' => $projectAttachmentsEnabled,
            'project_attachment_max_files' => $projectAttachmentsEnabled
                ? min(5, max(1, (int) ($terms['project_followup_attachment_max_files'] ?? 1)))
                : 0,
            'project_output_enabled' => $threadEnabled
                && (bool) ($terms['project_output_enabled'] ?? false),
            'certificate_enabled' => (bool) ($terms['certificate_enabled'] ?? false),
            'projects_enabled' => (bool) ($terms['projects_enabled'] ?? true),
        ];
    }

    /** @param int|float|string|null $value */
    private function formatUsd($value): string
    {
        return number_format(max(0, (float) $value), 6, '.', '');
    }
}
