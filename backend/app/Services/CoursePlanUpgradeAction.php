<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\CourseChatAccessService;
use App\Services\CourseAccessPlanService;
use App\Services\FinancialProvenanceService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CoursePlanUpgradeAction
{
    public function __construct(private readonly CourseChatAccessService $access,
        private readonly WalletService $wallet, private readonly FinancialProvenanceService $provenance,
        private readonly CourseAccessPlanService $plans) {}

    public function execute(User $user, Course $course, string $requestedCode,
        ?string $clientIdempotencyKey, int $expectedPrice,
        ?int $expectedCourseRevision): array
    {
        $access = $this->access;
        $wallet = $this->wallet;
        $provenance = $this->provenance;
        $plans = $this->plans;
        return DB::transaction(function () use (
                $user,
                $course,
                $access,
                $wallet,
                $provenance,
                $plans,
                $requestedCode,
                $clientIdempotencyKey,
                $expectedPrice,
                $expectedCourseRevision
            ): array {
                User::query()->lockForUpdate()->findOrFail($user->id);
                $entitlement = $access->entitlementFor((int) $user->id, (int) $course->id);
                if (!$entitlement['has_learning_access']) {
                    throw new \DomainException('course_access_required');
                }
                $eligibleEnrollment = $access->activeEnrollmentFor(
                    (int) $user->id,
                    (int) $course->id
                );
                $enrollment = $eligibleEnrollment
                    ? CourseEnrollment::query()
                        ->with(['course', 'order.courseCode', 'accessPlan'])
                        ->whereKey($eligibleEnrollment->id)
                        ->lockForUpdate()
                        ->first()
                    : null;
                if (
                    !$enrollment
                    || !$access->activeCapturedEnrollmentFor(
                        (int) $user->id,
                        (int) $course->id,
                        (int) $enrollment->id
                    )
                ) {
                    throw new \DomainException('full_track_upgrade_not_available');
                }
                // A section can route to an enrollment on its parent course.
                // Buyers share the paid-course lock, while authoring takes it
                // exclusively. The selected capabilities therefore stay tied
                // to the published revision the learner reviewed.
                $paidCourse = Course::query()
                    ->sharedLock()
                    ->findOrFail($enrollment->course_id);
                $enrollment->setRelation('course', $paidCourse);
                $currentPlan = $plans->planForEnrollment($enrollment);
                $currentTerms = $plans->termsForEnrollment($enrollment);

                if ($clientIdempotencyKey !== null) {
                    $replayedOrder = Order::query()
                        ->where('user_id', $user->id)
                        ->where('checkout_request_key', $clientIdempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($replayedOrder) {
                        if (!$this->isSameUpgradeReplay(
                            $replayedOrder,
                            (int) $paidCourse->id,
                            $requestedCode,
                            $expectedPrice
                        )) {
                            throw new \DomainException('checkout_idempotency_conflict');
                        }
                        if (
                            !$replayedOrder->isFinanciallyEffective()
                            || $provenance->enrollmentHasActiveHold(
                                $enrollment,
                                ['course', 'chat', 'plan']
                            )
                        ) {
                            throw new \DomainException('course_access_under_review');
                        }

                        return [
                            'already_upgraded' => true,
                            'idempotent_replay' => true,
                            'course' => $paidCourse,
                            'amount' => 0,
                            'order' => $replayedOrder,
                            'plan' => $currentPlan,
                            'plan_terms' => $currentTerms,
                        ];
                    }
                }

                if ($requestedCode !== null && ($currentTerms['code'] ?? null) === $requestedCode) {
                    return [
                        'already_upgraded' => true,
                        'idempotent_replay' => false,
                        'course' => $paidCourse,
                        'amount' => 0,
                        'plan' => $currentPlan,
                        'plan_terms' => $currentTerms,
                    ];
                }
                if ($expectedCourseRevision !== null
                    && $expectedCourseRevision !== $this->publishedRevision($paidCourse)) {
                    throw new \DomainException('course_terms_changed');
                }
                $targetPlan = $this->targetPlan(
                    $paidCourse,
                    $enrollment,
                    $plans,
                    $requestedCode
                );
                if (!$targetPlan && $entitlement['chat_available']) {
                    return [
                        'already_upgraded' => true,
                        'idempotent_replay' => false,
                        'course' => $paidCourse,
                        'amount' => 0,
                        'plan' => $currentPlan,
                        'plan_terms' => $currentTerms,
                    ];
                }
                $price = $this->upgradePrice($paidCourse, $enrollment, $targetPlan, $plans);
                if ($price === null) {
                    throw new \DomainException('full_track_upgrade_not_priced');
                }
                if ($price !== $expectedPrice) {
                    throw new \DomainException('course_price_changed');
                }

                $targetCode = (string) $targetPlan->code;
                $checkoutKey = $clientIdempotencyKey
                    ?: sprintf(
                        'system:course-plan-upgrade:%d:%s:%s',
                        $enrollment->id,
                        $targetCode,
                        Str::orderedUuid()->toString()
                    );
                $idempotencyKey = 'course-plan-upgrade:' . hash(
                    'sha256',
                    $user->id . '|' . $checkoutKey
                );
                $replayedOrder = Order::query()
                    ->where('user_id', $user->id)
                    ->where('checkout_request_key', $checkoutKey)
                    ->lockForUpdate()
                    ->first();
                if ($replayedOrder) {
                    if (!$this->isSameUpgradeReplay(
                        $replayedOrder,
                        (int) $paidCourse->id,
                        $targetCode,
                        $expectedPrice
                    )) {
                        throw new \DomainException('checkout_idempotency_conflict');
                    }
                    if (
                        !$replayedOrder->isFinanciallyEffective()
                        || $provenance->enrollmentHasActiveHold(
                            $enrollment,
                            ['course', 'chat', 'plan']
                        )
                    ) {
                        throw new \DomainException('course_access_under_review');
                    }

                    return [
                        'already_upgraded' => true,
                        'idempotent_replay' => true,
                        'course' => $paidCourse,
                        'amount' => 0,
                        'order' => $replayedOrder,
                        'plan' => $currentPlan,
                        'plan_terms' => $currentTerms,
                    ];
                }

                $originalOrderId = (int) (
                    $enrollment->access_plan_order_id
                    ?: $enrollment->order_id
                    ?: 0
                );
                $planSnapshot = $plans->snapshot($targetPlan, now());
                $order = Order::create([
                    'user_id' => $user->id,
                    'course_id' => $paidCourse->id,
                    'parent_order_id' => $originalOrderId ?: null,
                    'access_plan_id' => $targetPlan->id,
                    'access_plan_snapshot' => $planSnapshot,
                    'checkout_request_key' => $checkoutKey,
                    'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                    'amount' => $price,
                    'discount_amount' => 0,
                    'final_amount' => $price,
                    'status' => Order::STATUS_APPROVED,
                    'financial_status' => Order::FINANCIAL_SETTLED,
                    'approved_at' => now(),
                    'approved_by' => null,
                    'is_premium_user' => $user->isPremiumUser(),
                    'notes' => 'Course access-plan upgrade from order #' . $originalOrderId,
                ]);

                $minimumPaidCoins = max(0, (int) ($planSnapshot['minimum_paid_coins'] ?? 0));
                $paidFloorRemaining = max(
                    0,
                    $minimumPaidCoins - $wallet->coursePaidContribution(
                        (int) $user->id,
                        (int) $paidCourse->id
                    )
                );
                // Upgrades charge the difference between the previous plan
                // and the selected plan from purchased coins only. Reward
                // coins stay untouched for a later first-course purchase.
                $walletTransaction = $wallet->debit(
                    (int) $user->id,
                    $price,
                    'course_full_track_upgrade',
                    $idempotencyKey,
                    $paidCourse,
                    [
                        'requested_course_id' => (int) $course->id,
                        'enrollment_id' => (int) $enrollment->id,
                        'base_order_id' => (int) $enrollment->order_id,
                        'parent_order_id' => $originalOrderId,
                        'minimum_paid_coins' => $minimumPaidCoins,
                        'paid_floor_remaining_before_upgrade' => $paidFloorRemaining,
                    ],
                    0
                );

                $order->forceFill([
                    'wallet_transaction_id' => $walletTransaction->id,
                    'total_coins' => $price,
                    'paid_coins' => (int) $walletTransaction->paid_amount,
                    'reward_coins' => (int) $walletTransaction->reward_amount,
                ])->save();
                $provenance->allocateCourseDebit($order, $walletTransaction);

                Bill::create([
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'course_id' => $paidCourse->id,
                    'bill_number' => Bill::numberForOrder((int) $order->id),
                    'amount' => $price,
                    'tax_amount' => 0,
                    'total_amount' => $price,
                    'payment_status' => Bill::PAYMENT_STATUS_PAID,
                    'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                    'due_date' => now(),
                    'paid_at' => now(),
                    'notes' => 'Paid course access-plan upgrade via Rokn coins',
                ]);

                // Keep learning and plan-upgrade order lineage independent.
                $enrollment->forceFill([
                    'access_plan_order_id' => $order->id,
                    'access_plan_id' => $targetPlan->id,
                    'access_plan_snapshot' => $planSnapshot,
                    'access_granted_at' => now(),
                ])->save();

                return [
                    'already_upgraded' => false,
                    'idempotent_replay' => false,
                    'course' => $paidCourse,
                    'amount' => $price,
                    'order' => $order,
                    'plan' => $targetPlan,
                    'plan_terms' => $planSnapshot,
                ];
            }, 3);
    }

    public function targetPlan(
        Course $course,
        CourseEnrollment $enrollment,
        CourseAccessPlanService $plans,
        ?string $requestedCode = null
    ): ?CourseAccessPlan
    {
        // Purchases retain these exact terms in their order snapshot. A
        // concurrent catalogue edit affects the next quote, never this debit.
        // Only the two upgrade tiers are candidates. Their value can be
        // projects/reports rather than chat; Basic is never an upgrade target.
        $available = $plans->publicPlans($course)->filter(
            fn (CourseAccessPlan $plan): bool => in_array($plan->code, [CourseAccessPlan::GUIDED, CourseAccessPlan::MENTOR], true)
        )->values();
        if ($available->isEmpty()) {
            return null;
        }
        $currentTerms = $plans->termsForEnrollment($enrollment);
        $currentCode = (string) ($currentTerms['code'] ?? '');
        $currentRank = $this->planRank(
            $currentCode,
            (int) ($currentTerms['sort_order'] ?? 0)
        );
        if (
            !$currentTerms
            && $enrollment->order
            && $enrollment->order->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
        ) {
            // Legacy paid enrollments retain their original chat entitlement.
            return null;
        }
        if ($requestedCode) {
            $requested = $available->firstWhere('code', $requestedCode);
            if (!$requested) {
                throw new \DomainException('full_track_upgrade_not_available');
            }
            if (
                $currentTerms
                && $this->planRank((string) $requested->code, (int) $requested->sort_order) <= $currentRank
            ) {
                throw new \DomainException('full_track_upgrade_not_available');
            }
            $plans->assertPurchasableEconomics($requested);
            return $requested;
        }

        // Legacy clients without an explicit target still advance from their
        // current tier. Looking only at the first chat tier incorrectly made a
        // guided learner appear fully upgraded while a mentor tier existed.
        $next = $available->first(
            fn (CourseAccessPlan $plan): bool => !$currentTerms
                || $this->planRank((string) $plan->code, (int) $plan->sort_order) > $currentRank
        );
        if ($next) $plans->assertPurchasableEconomics($next);
        return $next;
    }

    public function upgradePrice(
        Course $course,
        CourseEnrollment $enrollment,
        ?CourseAccessPlan $targetPlan,
        CourseAccessPlanService $plans
    ): ?int
    {
        if (!$course->isPublishedForLearning() || !$targetPlan) {
            return null;
        }

        $current = $plans->termsForEnrollment($enrollment);
        $currentPrice = $current ? (int) ($current['price_coins'] ?? 0) : 0;
        $difference = max(0, (int) $targetPlan->price_coins - $currentPrice);

        $remainingPaidFloor = max(0, (int) $targetPlan->minimum_paid_coins
            - $this->wallet->coursePaidContribution((int) $enrollment->user_id, (int) $course->id));
        if ($remainingPaidFloor > $difference) {
            // Never increase the accepted price or activate an unfunded tier.
            throw new \DomainException('full_track_upgrade_paid_floor_unfunded');
        }

        return $difference;
    }

    private function isSameUpgradeReplay(
        Order $order,
        int $courseId,
        ?string $targetPlanCode,
        int $expectedPrice
    ): bool
    {
        if (
            (int) $order->course_id !== $courseId
            || $order->package_id !== null
            || $order->payment_method !== Order::PAYMENT_METHOD_WALLET_COINS
            || $order->status !== Order::STATUS_APPROVED
            || !str_starts_with((string) $order->notes, 'Course access-plan upgrade from order #')
        ) {
            return false;
        }

        if ((int) $order->final_amount !== $expectedPrice) {
            return false;
        }

        return $targetPlanCode === null
            || (string) data_get($order->access_plan_snapshot, 'code') === $targetPlanCode;
    }

    private function planRank(string $code, int $storedSortOrder): int
    {
        return match ($code) {
            'basic' => 10,
            'guided' => 20,
            'mentor' => 30,
            default => max(0, $storedSortOrder),
        };
    }

    private function publishedRevision(Course $course): int
    {
        return max(1, (int) (
            $course->last_published_authoring_version ?: $course->authoring_version
        ));
    }


}
