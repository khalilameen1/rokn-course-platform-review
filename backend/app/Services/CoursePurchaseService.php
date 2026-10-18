<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCheckoutRejected;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Course;
use App\Models\User;
use App\Support\CoursePurchaseOutcome;
use App\Support\StudentNotificationIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The direct wallet purchase kept for the shipped course-details screen:
 * the quote it shows and the authorize step behind its buy button. Pricing
 * is CourseCheckoutQuoteService and the debit is CoursePurchaseAction, the
 * same owners course-checkouts use, so this total always equals what
 * authorize debits.
 */
final readonly class CoursePurchaseService
{
    public function __construct(
        private CourseCheckoutQuoteService $quotes,
        private CoursePurchaseAction $purchases,
        private CourseCouponService $coupons,
        private CourseAccessPlanService $plans,
        private CoursePromotionPolicy $promotions,
        private WalletService $wallet,
        private FinancialAnomalyService $financialRisk,
        private PackageChannelPricingService $packagePricing,
        private StudentNotificationService $notifications
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException when the selected plan is not purchasable
     * @throws \DomainException with a CourseCheckoutRejected code for any other refusal
     */
    public function quote(
        User $user,
        int $courseId,
        string $accessPlanCode,
        mixed $couponCode,
        ?int $expectedCourseRevision
    ): array {
        $terms = DB::transaction(function () use ($user, $courseId, $accessPlanCode, $couponCode, $expectedCourseRevision): array {
            // Revision first, as before: a superseded screen re-reads its
            // plans before any coupon decision is shown to the learner.
            $course = Course::query()->sharedLock()->findOrFail($courseId);
            if ($expectedCourseRevision !== null
                && $expectedCourseRevision !== $this->publishedRevision($course)) {
                throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_TERMS_CHANGED);
            }

            return $this->quotes->calculate($user, [
                'course_id' => (int) $course->id,
                'access_plan_code' => $accessPlanCode,
                'coupon_code' => $couponCode,
                // Pricing only: no funding package is bound by this quote.
                'channel' => 'direct',
            ], true, false);
        }, 3);

        return [
            'course_id' => (int) $terms['course_id'],
            'course_revision' => (int) $terms['course_revision'],
            'access_plan_code' => $terms['access_plan_code'],
            'original_price' => (int) $terms['original_price'],
            'discount_amount' => (int) $terms['discount_amount'],
            'final_price' => (int) $terms['final_price'],
            'coupon' => $terms['coupon_code'] !== null ? [
                'code' => $terms['coupon_code'],
                'discount_percentage' => (int) $terms['coupon_discount_percentage'],
            ] : null,
        ];
    }

    /**
     * @throws ValidationException when the selected plan is not purchasable
     * @throws \DomainException with a CourseCheckoutRejected code for any other refusal
     */
    public function authorize(
        User $user,
        int $courseId,
        string $requestedPlanCode,
        ?string $clientIdempotencyKey,
        mixed $couponCode,
        int $expectedPrice,
        ?int $expectedCourseRevision
    ): CoursePurchaseOutcome {
        $course = Course::findOrFail($courseId);
        $requestedCouponCode = $this->coupons->normalize($couponCode);
        try {
            $result = $this->purchases->execute(
                $user, $course, $requestedPlanCode, $clientIdempotencyKey,
                $expectedPrice, $expectedCourseRevision, $requestedCouponCode
            );
        } catch (InsufficientWalletBalanceException $exception) {
            return $this->shortfall($user, $course, $requestedPlanCode, $exception);
        }

        if (($result['access_changed'] ?? false) === true) {
            $currentTerms = is_array($result['current_plan_terms'] ?? null)
                ? $result['current_plan_terms']
                : null;

            return new CoursePurchaseOutcome(CoursePurchaseOutcome::ACCESS_CHANGED, [
                'course_id' => (int) $result['course_id'],
                'requested_access_plan_code' => (string) $result['requested_plan_code'],
                'current_access_plan' => $currentTerms
                    ? $this->plans->publicPayloadFromTerms($currentTerms)
                    : null,
                'already_enrolled' => true,
            ]);
        }

        if (! $result['already_enrolled']) {
            $this->notifyEnrolled($user, $course, $result);
        }

        $balances = $this->wallet->balances($user->fresh());
        $purchasedBalance = $balances['paid'];
        $rewardBalance = $balances['reward'];
        $rewardContribution = $this->promotions->allowance(
            (int) $user->id,
            (int) $course->id,
            (int) data_get($result, 'plan_terms.price_coins', $course->price)
        );
        $minimumPaidCoins = max(0, (int) data_get($result, 'plan_terms.minimum_paid_coins', 0));
        $paidContributionForCourse = $this->wallet->coursePaidContribution(
            (int) $user->id,
            (int) $course->id
        );
        $financialReviewRequired = ! $this->financialRisk->allowsVariableCostFeatures(
            $result['enrollment']->fresh()
        );

        return new CoursePurchaseOutcome(CoursePurchaseOutcome::PURCHASED, [
            'order_id' => $result['order']?->id,
            'bill_id' => $result['bill']?->id,
            'enrollment_id' => $result['enrollment']->id,
            'amount_deducted' => $result['amount'],
            'original_price' => (int) ($result['original_amount'] ?? $result['order']->amount ?? 0),
            'discount_amount' => (int) ($result['discount_amount'] ?? $result['order']->discount_amount ?? 0),
            'coupon_code' => $result['coupon_code'] ?? $result['order']?->coupon_code,
            'total_balance' => $balances['total'],
            'purchased_balance' => $purchasedBalance,
            'reward_balance' => $rewardBalance,
            'spendable_balance' => $purchasedBalance + min($rewardBalance, $rewardContribution['remaining']),
            'reward_contribution_cap_per_course' => $rewardContribution['cap'],
            'reward_contribution_used_for_course' => $rewardContribution['used'],
            'reward_contribution_remaining_for_course' => $rewardContribution['remaining'],
            'minimum_paid_coins_required' => $minimumPaidCoins,
            'paid_coin_floor_remaining' => max(0, $minimumPaidCoins - $paidContributionForCourse),
            'financial_review_required' => $financialReviewRequired,
            'allocation' => [
                'total_coins' => (int) ($result['order']->total_coins ?? 0),
                'paid_coins' => (int) ($result['order']->paid_coins ?? 0),
                'reward_coins' => (int) ($result['order']->reward_coins ?? 0),
                'spend_policy' => 'reward_first_then_paid',
            ],
            'access_plan' => $result['plan_terms']
                ? $this->plans->publicPayloadFromTerms($result['plan_terms'])
                : null,
            'already_enrolled' => $result['already_enrolled'],
            'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
        ], (bool) $result['already_enrolled']);
    }

    /** What the learner still needs, read after the refused debit rolled back. */
    private function shortfall(
        User $user,
        Course $course,
        string $requestedPlanCode,
        InsufficientWalletBalanceException $exception
    ): CoursePurchaseOutcome {
        $deficit = max(0, $exception->required - $exception->balance);
        $balances = $this->wallet->balances($user->fresh());
        try {
            $selectedPlan = $this->plans->selectedPlan($course, $requestedPlanCode);
        } catch (ValidationException $validationException) {
            return new CoursePurchaseOutcome(
                CoursePurchaseOutcome::PLAN_UNAVAILABLE,
                null,
                refusal: collect($validationException->errors())->flatten()->first() ?: null
            );
        }
        $rewardContribution = $this->promotions->allowance(
            (int) $user->id,
            (int) $course->id,
            (int) $selectedPlan->price_coins
        );
        $minimumPaidCoins = max(0, (int) $selectedPlan->minimum_paid_coins);
        $paidFloorRemaining = max(
            0,
            $minimumPaidCoins - $this->wallet->coursePaidContribution((int) $user->id, (int) $course->id)
        );

        return new CoursePurchaseOutcome(CoursePurchaseOutcome::INSUFFICIENT_COINS, [
            'required_coins' => $exception->required,
            'total_balance' => $balances['total'],
            'purchased_balance' => $balances['paid'],
            'reward_balance' => $balances['reward'],
            'spendable_balance' => $exception->balance,
            'reward_contribution_cap_per_course' => $rewardContribution['cap'],
            'reward_contribution_used_for_course' => $rewardContribution['used'],
            'reward_contribution_remaining_for_course' => $rewardContribution['remaining'],
            'minimum_paid_coins_required' => $minimumPaidCoins,
            'paid_coin_floor_remaining' => $paidFloorRemaining,
            'deficit' => $deficit,
            'recommended_packages' => $this->packagePricing->recommendedPackages($deficit),
            // Mobile resumes the purchase after the embedded checkout.
            'resume_action' => [
                'type' => 'purchase_course',
                'course_id' => $course->id,
                'access_plan_code' => $requestedPlanCode,
            ],
        ]);
    }

    /** @param array<string, mixed> $result */
    private function notifyEnrolled(User $user, Course $course, array $result): void
    {
        try {
            $this->notifications->notifyUser(
                $user->fresh(),
                new StudentNotificationIntent(
                    notificationType: StudentNotificationService::TYPE_COURSE_ENROLLED,
                    titleAr: 'الكورس جاهز',
                    titleEn: 'Course unlocked',
                    messageAr: $course->name_ar."\nابدأ أول مقطع الآن",
                    messageEn: 'You can now start: '.$course->name_en,
                    link: '/course/'.$course->id,
                    notifiableType: Course::class,
                    notifiableId: $course->id,
                    deliveryKey: 'course-enrolled:order:'.($result['order']->id ?? $result['enrollment']->id),
                    templateVariables: ['course' => (string) ($course->name_ar ?: $course->name_en)]
                )
            );
        } catch (\Throwable $exception) {
            // A push outage must never turn a completed purchase into an apparent failure.
            report($exception);
        }
    }

    private function publishedRevision(Course $course): int
    {
        return max(1, (int) (
            $course->last_published_authoring_version ?: $course->getAttribute('authoring_version')
        ));
    }
}
