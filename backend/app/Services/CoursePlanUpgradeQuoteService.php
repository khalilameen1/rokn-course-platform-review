<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCheckoutRejected;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Quotes the upgrade of an existing enrolment to its next paid service plan
 * for the shipped upgrade offer sheet. It never charges: purchasing an
 * upgrade goes through course-checkouts (mode=upgrade) and
 * CoursePlanUpgradeAction, priced by the same CoursePlanUpgradeEligibilityService.
 */
final readonly class CoursePlanUpgradeQuoteService
{
    public function __construct(
        private CourseEntitlementService $access,
        private CourseAccessPlanService $plans,
        private CoursePlanUpgradeEligibilityService $upgrades,
        private WalletService $wallet,
        private PackageChannelPricingService $packagePricing
    ) {}

    /**
     * @return array{already_upgraded: bool, data: array<string, mixed>}
     *
     * @throws \DomainException with a CourseCheckoutRejected code when no upgrade can be quoted
     */
    public function quote(User $user, Course $course, ?string $requestedCode, ?string $requiredFeature): array
    {
        $entitlement = $this->access->entitlementFor((int) $user->id, (int) $course->id);
        if (! $entitlement['has_learning_access']) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_ACCESS_REQUIRED);
        }
        $enrollment = $this->access->activeEnrollmentFor((int) $user->id, (int) $course->id);
        if (! $enrollment) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_AVAILABLE);
        }

        $quote = DB::transaction(function () use ($enrollment, $requestedCode, $requiredFeature, $entitlement, $user): array {
            // Readers need a stable published course, not an exclusive one.
            $paidCourse = Course::query()
                ->sharedLock()
                ->findOrFail($enrollment->course_id);
            $enrollment->setRelation('course', $paidCourse);
            $offers = $this->upgrades->availablePlans($paidCourse, $enrollment, $requiredFeature);
            $offerCodes = $offers->pluck('code')->values()->all();
            if (! $requestedCode && $offers->isEmpty()) {
                if ($requiredFeature === null && $entitlement['chat_available']) {
                    return ['already_upgraded' => true, 'course' => $paidCourse,
                        'terms' => $this->plans->termsForEnrollment($enrollment), 'available_plan_codes' => []];
                }

                return ['already_upgraded' => false, 'payload' => [
                    ...$this->quotePayload($user->fresh(), $paidCourse, 0),
                    'upgrade_available' => false, 'available_plan_codes' => [],
                ]];
            }
            $targetPlan = $this->targetPlan($paidCourse, $enrollment, $requestedCode, $requiredFeature);

            if ($entitlement['chat_available'] && ! $targetPlan) {
                return [
                    'already_upgraded' => true,
                    'course' => $paidCourse,
                    'terms' => $this->plans->termsForEnrollment($enrollment),
                    'available_plan_codes' => $offerCodes,
                ];
            }

            $price = $this->upgradePrice($paidCourse, $enrollment, $targetPlan);
            if ($price === null) {
                throw new CourseCheckoutRejected(CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_PRICED);
            }

            return [
                'already_upgraded' => false,
                'payload' => [
                    ...$this->quotePayload($user->fresh(), $paidCourse, $price, $targetPlan),
                    'upgrade_available' => true,
                    'available_plan_codes' => $offerCodes,
                ],
            ];
        }, 3);

        if ($quote['already_upgraded']) {
            return ['already_upgraded' => true, 'data' => [
                'already_upgraded' => true,
                'upgrade_available' => false,
                'available_plan_codes' => $quote['available_plan_codes'],
                'chat_available' => (bool) $entitlement['chat_available'],
                'certificate_available' => (bool) ($quote['terms']['certificate_enabled'] ?? true),
                'course_revision' => $this->publishedRevision($quote['course']),
            ]];
        }

        return ['already_upgraded' => false, 'data' => $quote['payload']];
    }

    private function targetPlan(
        Course $course,
        CourseEnrollment $enrollment,
        ?string $requestedCode,
        ?string $requiredFeature
    ): ?CourseAccessPlan {
        if (! $requestedCode) {
            return $this->upgrades->availablePlans($course, $enrollment, $requiredFeature)->first();
        }

        return $this->upgrades->targetPlan($course, $enrollment, $requestedCode, $requiredFeature);
    }

    private function upgradePrice(Course $course, CourseEnrollment $enrollment, ?CourseAccessPlan $targetPlan): ?int
    {
        if (! $course->isPublishedForLearning() || ! $targetPlan) {
            return null;
        }

        return $this->upgrades->upgradePrice($course, $enrollment, $targetPlan);
    }

    /** @return array<string, mixed> */
    private function quotePayload(User $user, Course $course, int $price, ?CourseAccessPlan $targetPlan = null): array
    {
        $balances = $this->wallet->balances($user);
        $paid = $balances['paid'];
        $minimumPaidCoins = max(0, (int) ($targetPlan->minimum_paid_coins ?? 0));
        $paidForCourse = $this->wallet->coursePaidContribution((int) $user->id, (int) $course->id);
        $paidFloorRemaining = max(0, $minimumPaidCoins - $paidForCourse);
        // The upgrade is exactly the price difference and can be paid only
        // with purchased coins. Rewards are intentionally excluded here.
        $rewardContribution = 0;
        $paidContribution = min($paid, $price);
        $spendable = $paid;
        $deficit = max(0, $price - $spendable);

        $targetContract = $targetPlan ? $this->plans->publicPayload($targetPlan) : [];

        return [
            'already_upgraded' => false,
            'chat_available' => false,
            'certificate_available' => false,
            'ai_included' => (bool) ($targetContract['chat_enabled'] ?? false),
            'course_id' => (int) $course->id,
            'course_revision' => $this->publishedRevision($course),
            'course_title' => (string) $course->name_ar,
            'upgrade_price' => $price,
            'target_plan_code' => $targetPlan?->code,
            'target_plan_name' => $targetPlan?->name_ar,
            'target_message_limit' => $targetPlan
                ? (int) ($targetContract['chat_message_limit'] ?? 0) : null,
            'total_balance' => $balances['total'],
            'purchased_balance' => $paid,
            'reward_balance' => $balances['reward'],
            'spendable_balance' => $spendable,
            'reward_contribution_cap_per_course' => 0,
            'reward_contribution_used_for_course' => 0,
            'reward_contribution_remaining_for_course' => 0,
            'minimum_paid_coins_required' => $minimumPaidCoins,
            'paid_coin_floor_remaining' => $paidFloorRemaining,
            'estimated_allocation' => [
                'paid_coins' => $paidContribution,
                'reward_coins' => $rewardContribution,
            ],
            'deficit' => $deficit,
            'recommended_packages' => $deficit > 0 ? $this->packagePricing->recommendedPackages($deficit) : [],
        ];
    }

    private function publishedRevision(Course $course): int
    {
        return max(1, (int) (
            $course->last_published_authoring_version ?: $course->getAttribute('authoring_version')
        ));
    }
}
