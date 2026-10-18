<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCheckoutRejected;
use App\Models\Bill;
use App\Models\CouponRedemption;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CoursePurchaseAction
{
    public function __construct(private readonly WalletService $wallet,
        private readonly FinancialProvenanceService $provenance,
        private readonly FinancialEntitlementHoldReadService $holds,
        private readonly CourseAccessPlanService $plans,
        private readonly CourseCouponService $coupons,
        private readonly CourseEnrollmentWriter $enrollments) {}

    public function execute(
        User $user,
        Course $course,
        string $requestedPlanCode,
        ?string $clientIdempotencyKey,
        ?int $expectedPrice,
        ?int $expectedCourseRevision,
        ?string $requestedCouponCode = null,
        ?int $rewardLimit = null
    ): array {
        $walletService = $this->wallet;
        $provenance = $this->provenance;
        $holds = $this->holds;
        $planService = $this->plans;
        $coupons = $this->coupons;
        $enrollments = $this->enrollments;

        return DB::transaction(function () use (
            $user,
            $course,
            $walletService,
            $provenance,
            $holds,
            $planService,
            $coupons,
            $enrollments,
            $requestedPlanCode,
            $requestedCouponCode,
            $clientIdempotencyKey,
            $expectedPrice,
            $expectedCourseRevision,
            $rewardLimit
        ): array {
            // The learner is the financial aggregate: wallet balance,
            // enrollment and idempotency serialize there. Buyers take a
            // shared course lock, so they still run concurrently while an
            // exclusive authoring publish cannot replace the selected plan
            // between revision validation and receipt creation.
            User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedCourse = Course::query()->sharedLock()->findOrFail($course->id);

            $existingEnrollment = $enrollments->lockedFor((int) $user->id, (int) $lockedCourse->id);

            if ($clientIdempotencyKey !== null) {
                $replayedOrder = Order::query()
                    ->with(['bill', 'accessPlan'])
                    ->where('user_id', $user->id)
                    ->where('checkout_request_key', $clientIdempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($replayedOrder) {
                    if (! $this->isSamePurchaseReplay(
                        $replayedOrder,
                        (int) $lockedCourse->id,
                        $requestedPlanCode,
                        $requestedCouponCode,
                        $expectedPrice
                    )) {
                        throw new CourseCheckoutRejected(CourseCheckoutRejected::CHECKOUT_IDEMPOTENCY_CONFLICT);
                    }
                    if (! $existingEnrollment) {
                        throw new \LogicException('Committed course order has no enrollment.');
                    }
                    if (
                        ! $replayedOrder->isFinanciallyEffective()
                        || ! $existingEnrollment->isActive()
                        || $holds->enrollmentHasActiveHold($existingEnrollment, ['course'])
                    ) {
                        throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_PURCHASE_NOT_EFFECTIVE);
                    }

                    return [
                        'enrollment' => $existingEnrollment,
                        'order' => $replayedOrder,
                        'bill' => $replayedOrder->bill,
                        'amount' => 0,
                        'already_enrolled' => true,
                        'idempotent_replay' => true,
                        'plan_terms' => is_array($replayedOrder->access_plan_snapshot)
                            ? $replayedOrder->access_plan_snapshot
                            : null,
                    ];
                }
            }

            if ($existingEnrollment && $existingEnrollment->isActive()) {
                if (
                    ($existingEnrollment->order
                        && ! $existingEnrollment->order->isFinanciallyEffective())
                    || $holds->enrollmentHasActiveHold($existingEnrollment, ['course'])
                ) {
                    throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_ACCESS_UNDER_REVIEW);
                }
                $currentTerms = $planService->termsForEnrollment($existingEnrollment);
                $currentPlanCode = strtolower(trim((string) ($currentTerms['code'] ?? '')));
                if (
                    $currentPlanCode === ''
                    || ! hash_equals($currentPlanCode, $requestedPlanCode)
                ) {
                    return [
                        'access_changed' => true,
                        'course_id' => (int) $lockedCourse->id,
                        'requested_plan_code' => $requestedPlanCode,
                        'current_plan_terms' => $currentTerms,
                    ];
                }

                return [
                    'enrollment' => $existingEnrollment,
                    'order' => $existingEnrollment->order,
                    'bill' => $existingEnrollment->order?->bill,
                    'amount' => 0,
                    'already_enrolled' => true,
                    'idempotent_replay' => false,
                    'plan_terms' => $currentTerms,
                ];
            }

            if (! $lockedCourse->isAvailableForNewPurchase()) {
                throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_NOT_AVAILABLE);
            }
            if ($expectedCourseRevision !== null
                && $expectedCourseRevision !== $this->publishedRevision($lockedCourse)) {
                throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_TERMS_CHANGED);
            }

            $selectedPlan = $planService->selectedPlan($lockedCourse, $requestedPlanCode);
            $amount = max(0, (int) $selectedPlan->price_coins);

            $checkoutKey = $clientIdempotencyKey ?: sprintf(
                'server:course-purchase:%d:%d:%s',
                $user->id,
                $lockedCourse->id,
                Str::orderedUuid()->toString()
            );
            $walletIdempotencyKey = 'course-purchase:'.hash(
                'sha256',
                $user->id.'|'.$checkoutKey
            );
            $planSnapshot = $planService->snapshot($selectedPlan, now());
            $minimumPaidCoins = max(0, (int) ($planSnapshot['minimum_paid_coins'] ?? 0));
            $paidFloorForQuote = max(0, $minimumPaidCoins - $walletService->coursePaidContribution(
                (int) $user->id, (int) $lockedCourse->id
            ));
            $couponQuote = $coupons->quote(
                (int) $user->id,
                (int) $lockedCourse->id,
                $amount,
                $paidFloorForQuote,
                $requestedCouponCode,
                true
            );
            $finalAmount = (int) $couponQuote['final'];
            if ($expectedPrice !== null && $expectedPrice !== $finalAmount) {
                throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_PRICE_CHANGED);
            }

            $order = Order::create([
                'user_id' => $user->id,
                'course_id' => $lockedCourse->id,
                'access_plan_id' => $selectedPlan->id,
                'access_plan_snapshot' => $planSnapshot,
                'checkout_request_key' => $checkoutKey,
                'coupon_id' => $couponQuote['coupon']?->id,
                'coupon_code' => $couponQuote['code'],
                'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                'amount' => $amount,
                'discount_amount' => $couponQuote['discount'],
                'final_amount' => $finalAmount,
                'status' => Order::STATUS_APPROVED,
                'financial_status' => Order::FINANCIAL_SETTLED,
                'approved_at' => now(),
                'approved_by' => null,
                'is_premium_user' => $user->isPremiumUser(),
                'notes' => 'Wallet course purchase',
            ]);

            // The learner row is the financial lock. Derive the remaining
            // course-wide allowance from the immutable wallet ledger so
            // base purchases and every plan upgrade share one cumulative
            // reward cap without serializing unrelated learners.
            $rewardContribution = $this->rewardContribution(
                $walletService,
                (int) $user->id,
                (int) $lockedCourse->id,
                $amount
            );
            $paidFloorRemaining = max(
                0,
                $minimumPaidCoins - $walletService->coursePaidContribution(
                    (int) $user->id,
                    (int) $lockedCourse->id
                )
            );
            $maximumRewardForPurchase = min(
                max(0, $rewardContribution['remaining'] - (int) $couponQuote['discount']),
                $rewardLimit ?? PHP_INT_MAX,
                max(0, $finalAmount - min($finalAmount, $paidFloorRemaining))
            );
            $walletTransaction = $walletService->debit(
                $user->id,
                $finalAmount,
                'course_purchase',
                $walletIdempotencyKey,
                $lockedCourse,
                [
                    'course_title' => $lockedCourse->name_ar,
                    'minimum_paid_coins' => $minimumPaidCoins,
                    'paid_floor_remaining_before_purchase' => $paidFloorRemaining,
                    'original_price_coins' => $amount,
                    'coupon_id' => $couponQuote['coupon']?->id,
                    'coupon_discount_coins' => $couponQuote['discount'],
                ],
                $maximumRewardForPurchase
            );

            // Course orders preserve the paid/reward coin attribution.
            $order->forceFill([
                'wallet_transaction_id' => $walletTransaction->id,
                'total_coins' => $finalAmount,
                'paid_coins' => (int) $walletTransaction->paid_amount,
                'reward_coins' => (int) $walletTransaction->reward_amount,
            ])->save();
            $provenance->allocateCourseDebit($order, $walletTransaction);

            $bill = Bill::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'course_id' => $lockedCourse->id,
                'bill_number' => Bill::numberForOrder((int) $order->id),
                'amount' => $amount,
                'tax_amount' => 0,
                'total_amount' => $finalAmount,
                'payment_status' => Bill::PAYMENT_STATUS_PAID,
                'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                'due_date' => now(),
                'paid_at' => now(),
                'notes' => $couponQuote['coupon']
                    ? 'Paid via Rokn coins with coupon #'.$couponQuote['coupon']->id
                    : 'Paid via Rokn coins',
            ]);

            if ($couponQuote['coupon']) {
                CouponRedemption::create([
                    'coupon_id' => $couponQuote['coupon']->id,
                    'user_id' => $user->id,
                    'course_id' => $lockedCourse->id,
                    'order_id' => $order->id,
                    'coupon_code' => $couponQuote['code'],
                    'discount_percentage' => $couponQuote['percentage'],
                    'discount_coins' => $couponQuote['discount'],
                    'redeemed_at' => now(),
                ]);
            }

            // A repurchase starts a fresh AI entitlement cycle.
            $enrollment = $enrollments->grantPurchasedPlan(
                $existingEnrollment,
                (int) $user->id,
                (int) $lockedCourse->id,
                $order,
                $selectedPlan,
                $planSnapshot
            );

            return [
                'enrollment' => $enrollment,
                'order' => $order,
                'bill' => $bill,
                'amount' => $finalAmount,
                'original_amount' => $amount,
                'discount_amount' => (int) $couponQuote['discount'],
                'coupon_code' => $couponQuote['code'],
                'paid_coins' => (int) $walletTransaction->paid_amount,
                'reward_coins' => (int) $walletTransaction->reward_amount,
                'already_enrolled' => false,
                'idempotent_replay' => false,
                'plan_terms' => $planSnapshot,
            ];
        }, 3);
    }

    private function rewardContribution(WalletService $wallet, int $userId, int $courseId, int $targetPrice): array
    {
        return app(CoursePromotionPolicy::class)->allowance($userId, $courseId, $targetPrice);
    }

    private function publishedRevision(Course $course): int
    {
        return max(1, (int) (
            $course->last_published_authoring_version ?: $course->authoring_version
        ));
    }

    private function isSamePurchaseReplay(
        Order $order,
        int $courseId,
        string $requestedPlanCode,
        ?string $requestedCouponCode,
        ?int $expectedPrice
    ): bool {
        if (
            (int) $order->course_id !== $courseId
            || $order->package_id !== null
            || $order->payment_method !== Order::PAYMENT_METHOD_WALLET_COINS
            || $order->status !== Order::STATUS_APPROVED
        ) {
            return false;
        }

        if (! hash_equals((string) $order->coupon_code, (string) $requestedCouponCode)) {
            return false;
        }
        if ($expectedPrice !== null && (int) $order->final_amount !== $expectedPrice) {
            return false;
        }

        return (string) data_get($order->access_plan_snapshot, 'code') === $requestedPlanCode;
    }
}
