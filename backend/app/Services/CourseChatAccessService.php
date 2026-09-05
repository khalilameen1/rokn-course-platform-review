<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\FinancialEntitlementHold;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CourseChatAccessService
{
    public function __construct(
        private FinancialProvenanceService $provenance,
        private CourseAccessPlanService $plans,
        private FinancialAnomalyService $financialRisk
    ) {
    }

    public function entitlementFor(int $userId, int $courseId): array
    {
        return $this->resolveEntitlement($userId, $courseId)['entitlement'];
    }

    /**
     * @param Collection<int,int>|array<int,int> $courseIds
     * @return array<int,array<string,mixed>>
     */
    public function entitlementsFor(int $userId, Collection|array $courseIds): array
    {
        return array_map(
            fn (array $resolved): array => $resolved['entitlement'],
            $this->resolveEntitlements($userId, $courseIds)
        );
    }

    /** @return array{entitlement: array<string,mixed>, enrollment: CourseEnrollment|null} */
    public function resolveEntitlement(int $userId, int $courseId): array
    {
        return $this->resolveEntitlements($userId, [$courseId])[$courseId]
            ?? ['entitlement' => $this->emptyEntitlement(), 'enrollment' => null];
    }

    /**
     * The database enforces one enrollment per student and course. Upgrades
     * update its captured plan. Catalogue and details resolve that same row.
     *
     * @return array<int,array{entitlement: array<string,mixed>, enrollment: CourseEnrollment|null}>
     */
    private function resolveEntitlements(int $userId, Collection|array $courseIds): array
    {
        $courseIds = collect($courseIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($courseIds->isEmpty()) {
            return [];
        }

        $publishedIds = Course::query()->whereIn('id', $courseIds)->withCount('sections')->get()
            ->filter(fn (Course $course): bool => $course->isPublishedForLearning())
            ->modelKeys();
        $enrollments = $this->activeEnrollments($userId, $publishedIds)
            ->with($this->enrollmentRelations())->get()->keyBy('course_id');
        $holds = $enrollments->isNotEmpty() && $this->provenance->schemaAvailable()
            ? FinancialEntitlementHold::query()
                ->where('user_id', $userId)
                ->whereIn('course_id', $enrollments->keys())
                ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                ->get(['course_id', 'course_order_id', 'entitlement_scope'])
            : collect();

        $enrollments = $enrollments->reject(
            fn (CourseEnrollment $row): bool => $this->hasLoadedHold($row, $holds, ['course'])
        );
        $funded = $enrollments->filter(fn (CourseEnrollment $row): bool =>
            $this->hasVariableCostFunding($row)
                && !$this->hasLoadedHold($row, $holds, ['chat', 'plan'])
        );
        $financial = $this->financialRisk->variableCostDecisions($funded);

        $result = [];
        foreach ($courseIds as $courseId) {
            $enrollment = $enrollments->get($courseId);
            $result[$courseId] = [
                'entitlement' => $enrollment
                    ? $this->entitlementPayload($enrollment, $financial[(int) $enrollment->id] ?? false)
                    : $this->emptyEntitlement(),
                'enrollment' => $enrollment,
            ];
        }

        return $result;
    }

    private function emptyEntitlement(): array
    {
        return [
            'has_learning_access' => false,
            'access_type' => 'none',
            'chat_available' => false,
            'certificate_available' => false,
            'plan_code' => null,
            'plan_name' => null,
            'project_feedback_level' => 'pass_only',
        ];
    }

    private function entitlementPayload(CourseEnrollment $enrollment, bool $variableCostAllowed): array
    {
        $terms = $this->plans->termsForEnrollment($enrollment);
        $publicTerms = $terms ? $this->plans->publicPayloadFromTerms($terms) : [];
        $isPaid = $this->isPaidOrder($enrollment->order) || $this->isPaidPlanUpgrade($enrollment);
        $isCourseCode = $enrollment->order?->payment_method === Order::PAYMENT_METHOD_COURSE_CODE;
        $isGrant = $this->isGrantEnrollment($enrollment);
        $chatAvailable = $variableCostAllowed && (bool) ($publicTerms['chat_enabled'] ?? false);

        return [
            'has_learning_access' => true,
            'access_type' => $isPaid ? 'paid' : ($isGrant ? 'scholarship' : ($isCourseCode ? 'course_code' : 'free')),
            'chat_available' => $chatAvailable,
            'certificate_available' => $this->certificateAllowedByPlan($enrollment, $terms),
            'plan_code' => $terms['code'] ?? $enrollment->accessPlan?->code,
            'plan_name' => $terms['name_ar'] ?? $enrollment->accessPlan?->name_ar,
            'chat_message_limit' => $chatAvailable ? (int) ($publicTerms['chat_message_limit'] ?? 0) : 0,
            'project_feedback_level' => $variableCostAllowed
                ? ($publicTerms['project_feedback_level'] ?? 'pass_only')
                : 'pass_only',
        ];
    }

    public function hasCertificateAccess(int $userId, int $courseId): bool
    {
        return $this->entitlementFor($userId, $courseId)['certificate_available'];
    }

    /** Earned certificates survive drafting the next curriculum revision. */
    public function enrollmentHasCertificateAccess(CourseEnrollment $enrollment): bool
    {
        $enrollment->loadMissing($this->enrollmentRelations());

        return !$this->provenance->enrollmentHasActiveHold($enrollment, ['course'])
            && $this->certificateAllowedByPlan($enrollment, $this->plans->termsForEnrollment($enrollment));
    }

    private function certificateAllowedByPlan(CourseEnrollment $enrollment, ?array $terms): bool
    {
        return (!$this->isGrantEnrollment($enrollment) || $this->isPaidPlanUpgrade($enrollment))
            && ($terms
                ? (bool) ($terms['certificate_enabled'] ?? false)
                : $enrollment->access_plan_id === null);
    }

    public function hasLearningAccess(int $userId, int $courseId): bool
    {
        if (!$this->courseIsReady($courseId)) {
            return false;
        }

        // Playback only needs the learning gate, not AI budgets or plan data.
        $enrollment = $this->activeEnrollments($userId, [$courseId])->first();

        return $enrollment !== null
            && !$this->provenance->enrollmentHasActiveHold($enrollment, ['course']);
    }

    public function hasChatAccess(int $userId, int $courseId): bool
    {
        return $this->entitlementFor($userId, $courseId)['chat_available'];
    }

    public function activeEnrollmentFor(int $userId, int $courseId): ?CourseEnrollment
    {
        if (!$this->courseIsReady($courseId)) {
            return null;
        }

        $enrollment = $this->activeEnrollments($userId, [$courseId])
            ->with($this->enrollmentRelations())->first();

        return $enrollment && !$this->provenance->enrollmentHasActiveHold($enrollment, ['course'])
            ? $enrollment
            : null;
    }

    /** Resolve the exact entitlement whose captured budget will fund chat. */
    public function activeChatEnrollmentFor(int $userId, int $courseId): ?CourseEnrollment
    {
        $resolved = $this->resolveEntitlement($userId, $courseId);

        return $resolved['entitlement']['chat_available'] ? $resolved['enrollment'] : null;
    }

    /** Pass-only projects use the same enrollment without requiring an AI plan. */
    public function activeProjectEnrollmentFor(int $userId, int $courseId): ?CourseEnrollment
    {
        return $this->activeEnrollmentFor($userId, $courseId);
    }

    public function enrollmentGrantsCourse(CourseEnrollment $enrollment, int $courseId): bool
    {
        return (int) $enrollment->course_id === $courseId;
    }

    /** Drafting a new course revision must not cancel already-submitted work. */
    public function activeCapturedEnrollmentFor(int $userId, int $courseId, int $enrollmentId): ?CourseEnrollment
    {
        $enrollment = $this->activeEnrollments($userId, [$courseId])
            ->whereKey($enrollmentId)->with($this->enrollmentRelations())->first();

        return $enrollment && !$this->provenance->enrollmentHasActiveHold($enrollment, ['course'])
            ? $enrollment
            : null;
    }

    /** Shared financial boundary for every provider-billed course feature. */
    public function enrollmentAllowsVariableCostFeatures(CourseEnrollment $enrollment): bool
    {
        $enrollment->loadMissing($this->enrollmentRelations());

        return $enrollment->isActive()
            && $this->hasVariableCostFunding($enrollment)
            && !$this->provenance->enrollmentHasActiveHold($enrollment, ['course', 'chat', 'plan'])
            && $this->financialRisk->allowsVariableCostFeaturesReadOnly($enrollment);
    }

    private function hasVariableCostFunding(CourseEnrollment $enrollment): bool
    {
        $order = $enrollment->order;
        $fullAccessCode = $order
            && $order->isFinanciallyEffective()
            && $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
            && !$this->isGrantEnrollment($enrollment);

        return $this->isPaidOrder($order) || $fullAccessCode || $this->isPaidPlanUpgrade($enrollment);
    }

    private function isPaidOrder(?Order $order): bool
    {
        return $order !== null
            && $order->isFinanciallyEffective()
            && $order->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
            && ((int) $order->total_coins > 0 || (float) $order->final_amount > 0);
    }

    private function isGrantEnrollment(CourseEnrollment $enrollment): bool
    {
        $order = $enrollment->order;

        // A code that no longer resolves cannot silently become a paid benefit.
        return $order?->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
            && (!$order->courseCode || $order->courseCode->isInstitutionalGrant());
    }

    private function isPaidPlanUpgrade(CourseEnrollment $enrollment): bool
    {
        $planOrder = $enrollment->accessPlanOrder;

        return $this->isPaidOrder($planOrder)
            && (int) $planOrder->id !== (int) $enrollment->order_id
            && $planOrder->parent_order_id !== null
            && (int) $planOrder->user_id === (int) $enrollment->user_id
            && (int) $planOrder->course_id === (int) $enrollment->course_id;
    }

    private function hasLoadedHold(CourseEnrollment $enrollment, Collection $holds, array $scopes): bool
    {
        if (!$enrollment->order_id) {
            return false;
        }

        $planOrderId = (int) ($enrollment->access_plan_order_id ?: $enrollment->order_id);

        return $holds->contains(function (FinancialEntitlementHold $hold) use ($enrollment, $scopes, $planOrderId): bool {
            if ((int) $hold->course_id !== (int) $enrollment->course_id
                || !in_array($hold->entitlement_scope, $scopes, true)) {
                return false;
            }

            $orderId = $hold->entitlement_scope === 'course' ? (int) $enrollment->order_id : $planOrderId;

            return (int) $hold->course_order_id === $orderId;
        });
    }

    private function activeEnrollments(int $userId, array $courseIds): Builder
    {
        return CourseEnrollment::query()
            ->where('user_id', $userId)
            ->whereIn('course_id', $courseIds)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    private function courseIsReady(int $courseId): bool
    {
        return Course::query()->withCount('sections')->find($courseId)?->isPublishedForLearning() ?? false;
    }

    /** @return list<string> */
    private function enrollmentRelations(): array
    {
        return ['order.courseCode', 'accessPlanOrder', 'accessPlan'];
    }
}
