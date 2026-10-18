<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Order;
use DateTimeInterface;

/**
 * The single writer for granting or re-sourcing course access.
 *
 * Every way a learner gains or loses access (wallet purchase, approved
 * course order, course code, paid plan upgrade, and the payment reversals
 * FinancialProvenanceService resolves) names its intent here instead of
 * writing enrollment columns itself, so the access rules for each source live
 * in one place. Callers own the transaction and hold the learner lock (the learner
 * is the financial aggregate); each method also locks the enrollment row.
 *
 * The per-source rules below intentionally reproduce the behaviour each flow
 * had before this writer existed. See the method docs for the differences.
 */
final readonly class CourseEnrollmentWriter
{
    public function __construct(
        private AiEntitlementBudgetService $aiBudget,
        private CourseAccessPlanService $plans
    ) {}

    public function lockedFor(int $userId, int $courseId): ?CourseEnrollment
    {
        return CourseEnrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Wallet purchase. The purchased plan becomes the enrollment's plan and
     * order lineage; re-buying over an existing row starts a fresh AI
     * entitlement cycle and restarts access_granted_at.
     *
     * @param  array<string, mixed>  $planSnapshot
     */
    public function grantPurchasedPlan(
        ?CourseEnrollment $existing,
        int $userId,
        int $courseId,
        Order $order,
        CourseAccessPlan $plan,
        array $planSnapshot
    ): CourseEnrollment {
        $enrollment = $existing ?: new CourseEnrollment([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        if ($existing) {
            $this->aiBudget->resetForNewPurchase($existing);
        }
        $enrollment->fill([
            'order_id' => $order->id,
            'access_plan_order_id' => $order->id,
            'access_plan_id' => $plan->id,
            'access_plan_snapshot' => $planSnapshot,
            'enrolled_at' => $enrollment->enrolled_at ?: now(),
            'expires_at' => null,
            'is_active' => true,
            'access_granted_at' => now(),
        ])->save();

        return $enrollment;
    }

    /**
     * An approved course order outside the wallet flow (OrderLifecycleService
     * approve). Only the learning order is re-sourced: plan fields and the AI
     * budget are left as they are, and the first access_granted_at is kept.
     */
    public function grantApprovedOrder(Order $order): CourseEnrollment
    {
        $enrollment = $this->lockedFor((int) $order->user_id, (int) $order->course_id)
            ?: new CourseEnrollment([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id,
                'enrolled_at' => now(),
            ]);
        $enrollment->forceFill([
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => $enrollment->access_granted_at ?: now(),
            'expires_at' => null,
        ])->save();

        return $enrollment;
    }

    /**
     * Free course-code access grants the course's lowest plan (owner decision,
     * 2026-10-17): its terms are captured as a receipt exactly like a purchase,
     * so chat, projects and certificate follow what that plan allows. A paid
     * plan never carries over to a code grant; completion facts and the AI
     * budget rows are kept. A course that publishes no plan is still opened,
     * watch-only, as before.
     *
     * @return array{enrollment: CourseEnrollment, created: bool}
     */
    public function grantCourseCode(int $userId, int $courseId, Order $order): array
    {
        $plan = $this->lowestPlanFor($courseId);
        $planFields = [
            'access_plan_order_id' => $plan ? $order->id : null,
            'access_plan_id' => $plan?->id,
            'access_plan_snapshot' => $plan ? $this->plans->snapshot($plan, now()) : null,
        ];
        $enrollment = $this->lockedFor($userId, $courseId);
        if ($enrollment) {
            $enrollment->forceFill($planFields + [
                'order_id' => $order->id,
                'is_active' => true,
                'access_granted_at' => now(),
                'expires_at' => null,
            ])->save();

            return ['enrollment' => $enrollment, 'created' => false];
        }

        $enrollment = CourseEnrollment::query()->create($planFields + [
            'user_id' => $userId,
            'course_id' => $courseId,
            'order_id' => $order->id,
            'enrolled_at' => now(),
            'is_active' => true,
            'access_granted_at' => now(),
        ]);

        return ['enrollment' => $enrollment, 'created' => true];
    }

    /** The course's "basic" plan, else its first active plan by sort order. */
    private function lowestPlanFor(int $courseId): ?CourseAccessPlan
    {
        $plans = CourseAccessPlan::query()
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $plans->firstWhere('code', 'basic') ?? $plans->first();
    }

    /**
     * Paid plan upgrade of an already active enrollment. Learning lineage
     * (order_id), activity, expiry and the AI budget rows stay untouched.
     *
     * @param  array<string, mixed>  $planSnapshot
     */
    public function applyPlanUpgrade(
        CourseEnrollment $enrollment,
        Order $order,
        CourseAccessPlan $plan,
        array $planSnapshot
    ): void {
        $enrollment->forceFill([
            'access_plan_order_id' => $order->id,
            'access_plan_id' => $plan->id,
            'access_plan_snapshot' => $planSnapshot,
            'access_granted_at' => now(),
        ])->save();
    }

    /**
     * A reversed payment suspends the course it funded. The stamped
     * updated_at is the hold's evidence: reinstatement only reopens the row
     * this exact suspension closed.
     */
    public function suspendForReversedPayment(CourseEnrollment $enrollment, DateTimeInterface $at): void
    {
        $enrollment->forceFill([
            'is_active' => false,
            'updated_at' => $at,
        ])->save();
    }

    /** The reversal was resolved in the learner's favour. */
    public function reinstateAfterRestoredPayment(CourseEnrollment $enrollment): void
    {
        $enrollment->forceFill(['is_active' => true])->save();
    }

    /**
     * Move the enrollment's plan to another order in the same upgrade lineage
     * (back to the parent when an upgrade payment is reversed, forward again
     * when it is restored). Outstanding AI reservations are cancelled first
     * because they were priced under the plan being left.
     */
    public function reassignPlanOrder(
        CourseEnrollment $enrollment,
        Order $planOrder,
        string $reason,
        ?DateTimeInterface $at = null
    ): void {
        $this->aiBudget->cancelOutstandingReservations($enrollment, $reason);
        $enrollment->forceFill([
            'access_plan_order_id' => $planOrder->id,
            'access_plan_id' => $planOrder->access_plan_id,
            'access_plan_snapshot' => $planOrder->access_plan_snapshot,
        ] + ($at ? ['updated_at' => $at] : []))->save();
    }
}
