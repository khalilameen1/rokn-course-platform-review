<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Edits future offers; enrollment receipts are not mutated by authoring. */
final readonly class CoursePlanAuthoringService
{
    public function __construct(
        private CoursePlanDefinitionService $definitions,
        private CoursePlanEconomicsService $economics
    ) {}

    public function createDefaults(Course $course): void
    {
        DB::transaction(function () use ($course): void {
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            if ($lockedCourse->accessPlans()->exists()) {
                return;
            }

            $base = max(0, (int) ($lockedCourse->price ?? 0));
            $policy = $this->definitions->globalAiPolicy();
            foreach ($this->definitions->defaults($base) as $row) {
                $tier = (array) ($policy[$row['code']] ?? []);
                if ($tier !== []) {
                    $row = $this->definitions->applyAiPolicy($row, $tier, $row);
                }
                $lockedCourse->accessPlans()->create($row);
            }
        }, 3);
    }

    /** @param array<string,array<string,mixed>> $policy */
    public function syncGlobalAiPolicy(array $policy): void
    {
        $templates = collect($this->definitions->defaults(0))->keyBy('code');

        CourseAccessPlan::query()->chunkById(200, function ($plans) use ($policy, $templates): void {
            foreach ($plans as $plan) {
                $tier = (array) ($policy[$plan->code] ?? []);
                $template = (array) $templates->get($plan->code, []);
                if ($tier === [] || $template === []) continue;

                $values = $this->definitions->applyAiPolicy($plan->getAttributes(), $tier, $template);
                // A global capacity change is also a commercial change for
                // future purchasers; it cannot bypass a costed offer's floor.
                $this->economics->assertCommercialFloor($values, $plan->code);
                $plan->forceFill(array_intersect_key(
                    $values,
                    array_flip(CoursePlanDefinitionService::AI_RUNTIME_FIELDS)
                ))->save();
            }
        });
    }

    /**
     * Plans shown by the authoring form. A GET must never create commercial
     * contracts behind the editor's back: old courses without plan rows get
     * unsaved defaults, which become real only through the revision-checked
     * course update.
     *
     * @return Collection<int, CourseAccessPlan>
     */
    public function plansForEditor(Course $course): Collection
    {
        $plans = $course->accessPlans()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($plans->isNotEmpty()) {
            return $plans;
        }

        $base = max(0, (int) ($course->price ?? 0));

        return collect($this->definitions->defaults($base))
            ->map(function (array $row) use ($course): CourseAccessPlan {
                $plan = new CourseAccessPlan(array_merge($row, [
                    'course_id' => (int) $course->getKey(),
                ]));
                $plan->setRelation('course', $course);

                return $plan;
            });
    }

    /** Plan codes remain stable across dashboard updates. */
    public function syncAdminPlans(
        Course $course,
        array $input
    ): void
    {
        $allowedCodes = [CourseAccessPlan::BASIC, CourseAccessPlan::GUIDED, CourseAccessPlan::MENTOR];
        $base = max(0, (int) ($course->price ?? 0));
        $globalPolicy = $this->definitions->globalAiPolicy();
        $policyDefaults = collect($this->definitions->defaults($base))
            ->map(function (array $definition) use ($globalPolicy): array {
                $tier = (array) ($globalPolicy[$definition['code']] ?? []);
                return $tier === []
                    ? $definition
                    : $this->definitions->applyAiPolicy($definition, $tier, $definition);
            })
            ->keyBy('code');
        if (array_diff($allowedCodes, array_keys($input)) !== []) {
            throw ValidationException::withMessages([
                'access_plans' => ['يجب إرسال المستويات الثلاثة كاملة في عملية حفظ واحدة.'],
            ]);
        }

        $prices = collect($allowedCodes)->mapWithKeys(fn (string $code) => [
            $code => max(0, (int) data_get($input, "{$code}.price_coins", 0)),
        ]);
        if ($prices['guided'] < $prices['basic'] || $prices['mentor'] < $prices['guided']) {
            throw ValidationException::withMessages([
                'access_plans' => ['سعر كل مستوى يجب أن يساوي أو يزيد عن المستوى الذي قبله.'],
            ]);
        }

        DB::transaction(function () use (
            $course,
            $input,
            $allowedCodes,
            $prices,
            $policyDefaults,
            $globalPolicy
        ): void {
            // Plan updates and purchases serialize on the course row.
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            $lockedCourse->forceFill(['price' => (int) $prices[CourseAccessPlan::BASIC]])->save();

            foreach ($allowedCodes as $position => $code) {
                $row = is_array($input[$code] ?? null) ? $input[$code] : [];
                $existingPlan = $lockedCourse->accessPlans()
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->first();
                $runtime = $existingPlan
                    ? $existingPlan->getAttributes()
                    : (array) $policyDefaults->get($code, []);
                $tier = (array) ($globalPolicy[$code] ?? []);
                $template = (array) $policyDefaults->get($code, []);
                if ($tier !== [] && $template !== []) {
                    $runtime = $this->definitions->applyAiPolicy($runtime, $tier, $template);
                }
                // Editing Basic publishes the new watch-only offer. It does
                // not modify already-purchased enrollment/order snapshots.
                if ($code === CourseAccessPlan::BASIC) {
                    $runtime = (array) $policyDefaults->get(CourseAccessPlan::BASIC, []);
                }
                foreach (CoursePlanDefinitionService::AI_RUNTIME_FIELDS as $field) {
                    $row[$field] = $runtime[$field] ?? null;
                }
                $lockedCourse->accessPlans()->updateOrCreate(
                    ['code' => $code],
                    $this->normalizeOffer($row, $existingPlan, $code, $position)
                );
            }
        }, 3);
    }

    /** Validate and normalize one offer before persisting the three-tier set. */
    private function normalizeOffer(
        array $row,
        ?CourseAccessPlan $existingPlan,
        string $code,
        int $position
    ): array
    {
        $allowedFeedback = CourseAccessPlan::PROJECT_FEEDBACK_LEVELS;
        $allowedModels = array_values(array_filter(config('openrouter.allowed_models', [])));
        $financialValue = static function (
            string $field,
            float $fallback = 0.0
        ) use ($row): float {
            return max(0, (float) ($row[$field] ?? $fallback));
        };
        $model = trim((string) ($row['model_override'] ?? ''));
        if ($model !== '' && !in_array($model, $allowedModels, true)) {
            throw ValidationException::withMessages([
                "access_plans.{$code}.model_override" => ['النموذج خارج قائمة الخادم المعتمدة.'],
            ]);
        }
        $feedback = (string) ($row['project_feedback_level'] ?? 'pass_only');
        if (!in_array($feedback, $allowedFeedback, true)) {
            $feedback = 'pass_only';
        }
        $chatEnabled = !empty($row['chat_enabled']);
        $chatAttachmentsEnabled = $chatEnabled && !empty($row['chat_attachments_enabled']);
        $projectAttachmentsEnabled = $feedback === 'enhanced'
            && !empty($row['project_followup_attachments_enabled']);
        $minimumPaidCoins = max(0, (int) ($row['minimum_paid_coins'] ?? 0));
        $chatBudget = $financialValue('ai_budget_usd');
        $chatReserve = $financialValue('request_reserve_usd');
        $projectBudget = $financialValue('project_feedback_budget_usd');
        $projectReserve = $financialValue('project_feedback_reserve_usd');
        $followupMessageLimit = max(0, (int) ($row['project_followup_message_limit'] ?? 0));
        $followupTokenBudget = max(0, (int) ($row['project_followup_token_budget'] ?? 0));
        $followupBudget = $financialValue('project_followup_budget_usd');
        $followupReserve = $financialValue('project_followup_reserve_usd');
        $maxOutputTokens = max(
            80,
            min(
                (int) config('openrouter.max_tokens', 800),
                (int) ($row['max_output_tokens'] ?? 320)
            )
        );

        if ($chatEnabled && (
            (int) ($row['chat_token_budget'] ?? 0) < $maxOutputTokens
            || $chatBudget <= 0
            || $chatReserve <= 0
            || $chatReserve > $chatBudget
        )) {
            throw ValidationException::withMessages([
                "access_plans.{$code}" => ['ميزانية المحادثة أو حجز الطلب غير صالحين لهذه الخطة.'],
            ]);
        }
        $hasVariableCost = $chatEnabled || $feedback !== 'pass_only';
        $priceCoins = max(0, (int) ($row['price_coins'] ?? 0));
        if (
            $minimumPaidCoins > $priceCoins
            || ($hasVariableCost && $minimumPaidCoins <= 0)
        ) {
            throw ValidationException::withMessages([
                "access_plans.{$code}.minimum_paid_coins" => [
                    'الفئة ذات التكلفة المتغيرة تحتاج حدًا مدفوعًا موجبًا لا يزيد عن سعرها.',
                ],
            ]);
        }
        if ($feedback !== 'pass_only' && (
            (int) ($row['project_feedback_token_budget'] ?? 0) < $maxOutputTokens
            || $projectBudget <= 0
            || $projectReserve <= 0
            || $projectReserve > $projectBudget
        )) {
            throw ValidationException::withMessages([
                "access_plans.{$code}" => ['ميزانية تقرير المشروع أو حجزه غير صالحين لهذه الخطة.'],
            ]);
        }
        if ($feedback === 'enhanced' && (
            $followupMessageLimit < 1
            || $followupTokenBudget < $maxOutputTokens
            || $followupBudget <= 0
            || $followupReserve <= 0
            || $followupReserve > $followupBudget
        )) {
            throw ValidationException::withMessages([
                "access_plans.{$code}" => ['حدود محادثة تقرير المشروع أو حجزها غير صالحة لهذه الخطة.'],
            ]);
        }

        $deliveryCost = array_key_exists('delivery_cost_usd', $row)
            ? ($row['delivery_cost_usd'] === '' ? null : $row['delivery_cost_usd'])
            : $existingPlan?->delivery_cost_usd;
        $this->economics->assertCommercialFloor(array_merge($row, [
            'delivery_cost_usd' => $deliveryCost,
        ]), $code);

        return [
            'name_ar' => trim((string) ($row['name_ar'] ?? '')) ?: $this->definitions->defaultName($code),
            'name_en' => trim((string) ($row['name_en'] ?? '')) ?: null,
            'price_coins' => max(0, (int) ($row['price_coins'] ?? 0)),
            'minimum_paid_coins' => $minimumPaidCoins,
            'chat_enabled' => $chatEnabled,
            'chat_message_limit' => $chatEnabled ? max(1, (int) ($row['chat_message_limit'] ?? 1)) : 0,
            'chat_token_budget' => $chatEnabled ? max(100, (int) ($row['chat_token_budget'] ?? 100)) : 0,
            'chat_attachments_enabled' => $chatAttachmentsEnabled,
            'chat_attachment_max_files' => $chatAttachmentsEnabled
                ? min(5, max(1, (int) ($row['chat_attachment_max_files'] ?? 1)))
                : 0,
            'project_followup_attachments_enabled' => $projectAttachmentsEnabled,
            'project_followup_attachment_max_files' => $projectAttachmentsEnabled
                ? min(5, max(1, (int) ($row['project_followup_attachment_max_files'] ?? 1)))
                : 0,
            // Runtime limits are copied from the global tier policy
            // above. A course save may change the commercial offer,
            // but it can never retain a dormant provider budget after
            // the global feature is disabled.
            'ai_budget_usd' => $chatEnabled ? $chatBudget : 0,
            'request_reserve_usd' => $chatEnabled ? $chatReserve : 0,
            'project_feedback_token_budget' => $feedback !== 'pass_only'
                ? max(100, (int) ($row['project_feedback_token_budget'] ?? 100))
                : 0,
            'project_feedback_budget_usd' => $feedback !== 'pass_only'
                ? $projectBudget : 0,
            'project_feedback_reserve_usd' => $feedback !== 'pass_only'
                ? $projectReserve : 0,
            'project_followup_message_limit' => $feedback === 'enhanced' ? $followupMessageLimit : 0,
            'project_followup_token_budget' => $feedback === 'enhanced' ? $followupTokenBudget : 0,
            'project_followup_budget_usd' => $feedback === 'enhanced'
                ? $followupBudget : 0,
            'project_followup_reserve_usd' => $feedback === 'enhanced'
                ? $followupReserve : 0,
            'max_output_tokens' => $maxOutputTokens,
            'model_override' => $model !== '' ? $model : null,
            'project_feedback_level' => $feedback,
            'project_output_enabled' => $feedback === 'enhanced'
                && !empty($row['project_output_enabled']),
            'projects_enabled' => $code !== CourseAccessPlan::BASIC,
            'certificate_enabled' => $code !== CourseAccessPlan::BASIC
                && (!array_key_exists('certificate_enabled', $row) || !empty($row['certificate_enabled'])),
            'delivery_cost_usd' => $deliveryCost,
            'is_active' => !empty($row['is_active']),
            'sort_order' => ($position + 1) * 10,
        ];
    }
}
