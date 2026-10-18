<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CourseCheckoutRejected;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Package;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CourseEntitlementService;
use App\Services\CoursePlanUpgradeEligibilityService;
use App\Services\PackageChannelPricingService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quotes the upgrade of an existing enrolment to its next paid service plan
 * for the shipped upgrade offer sheet. Purchasing an upgrade goes through
 * course-checkouts (mode=upgrade) and CoursePlanUpgradeAction.
 */
final class CourseChatUpgradeController extends Controller
{
    public function __construct(private readonly PackageChannelPricingService $packagePricing,
        private readonly CoursePlanUpgradeEligibilityService $upgrades) {}

    public function quote(
        Request $request,
        Course $course,
        CourseEntitlementService $access,
        CourseAccessPlanService $plans,
        WalletService $wallet
    ): JsonResponse {
        $user = auth('api')->user();
        $validated = $request->validate([
            'target_plan_code' => 'nullable|string|in:guided,mentor',
            'required_feature' => 'nullable|string|in:chat,project_discussion',
        ]);
        $requiredFeature = $validated['required_feature'] ?? null;
        $requestedCode = isset($validated['target_plan_code'])
            ? (string) $validated['target_plan_code']
            : null;
        $entitlement = $access->entitlementFor((int) $user->id, (int) $course->id);

        if (! $entitlement['has_learning_access']) {
            return $this->error('course_access_required', 'افتح الكورس أولًا قبل الترقية', 403);
        }
        $enrollment = $access->activeEnrollmentFor((int) $user->id, (int) $course->id);
        if (! $enrollment) {
            return $this->error(
                'full_track_upgrade_not_available',
                'الترقية غير متاحة لهذه الفئة',
                409
            );
        }

        try {
            $quote = DB::transaction(function () use (
                $enrollment,
                $plans,
                $requestedCode,
                $requiredFeature,
                $entitlement,
                $user,
                $wallet
            ): array {
                $paidCourse = Course::query()
                    ->sharedLock()
                    ->findOrFail($enrollment->course_id);
                $enrollment->setRelation('course', $paidCourse);
                $offers = $this->upgrades->availablePlans($paidCourse, $enrollment, $requiredFeature);
                $offerCodes = $offers->pluck('code')->values()->all();
                if (! $requestedCode && $offers->isEmpty()) {
                    if ($requiredFeature === null && $entitlement['chat_available']) {
                        return ['already_upgraded' => true, 'course' => $paidCourse,
                            'terms' => $plans->termsForEnrollment($enrollment), 'available_plan_codes' => []];
                    }

                    return ['already_upgraded' => false, 'payload' => [
                        ...$this->quotePayload($user->fresh(), $paidCourse, 0, $wallet),
                        'upgrade_available' => false, 'available_plan_codes' => [],
                    ]];
                }
                $targetPlan = $this->targetPlan(
                    $paidCourse,
                    $enrollment,
                    $plans,
                    $requestedCode,
                    $requiredFeature
                );

                if ($entitlement['chat_available'] && ! $targetPlan) {
                    $terms = $plans->termsForEnrollment($enrollment);

                    return [
                        'already_upgraded' => true,
                        'course' => $paidCourse,
                        'terms' => $terms,
                        'available_plan_codes' => $offerCodes,
                    ];
                }

                $price = $this->upgradePrice($paidCourse, $enrollment, $targetPlan, $plans);
                if ($price === null) {
                    throw new CourseCheckoutRejected(CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_PRICED);
                }

                return [
                    'already_upgraded' => false,
                    'payload' => [...$this->quotePayload(
                        $user->fresh(),
                        $paidCourse,
                        $price,
                        $wallet,
                        $targetPlan
                    ), 'upgrade_available' => true, 'available_plan_codes' => $offerCodes],
                ];
            }, 3);
        } catch (\DomainException $exception) {
            $code = CourseCheckoutRejected::codeFor($exception);
            if ($code === CourseCheckoutRejected::FULL_TRACK_UPGRADE_PAID_FLOOR_UNFUNDED) {
                return $this->error('full_track_upgrade_paid_floor_unfunded', 'الترقية غير متاحة بالسعر الحالي', 409);
            }
            if ($code === CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_PRICED) {
                return $this->error(
                    'full_track_upgrade_not_priced',
                    'لم يحدد سعر الترقية لهذا الكورس',
                    409
                );
            }

            return $this->error(
                'full_track_upgrade_not_available',
                'الترقية غير متاحة لهذه الفئة',
                409
            );
        }

        if ($quote['already_upgraded']) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'code' => 'full_track_already_available',
                'message' => 'الفئة المختارة مفعّلة بالفعل',
                'data' => [
                    'already_upgraded' => true,
                    'upgrade_available' => false,
                    'available_plan_codes' => $quote['available_plan_codes'],
                    'chat_available' => (bool) $entitlement['chat_available'],
                    'certificate_available' => (bool) ($quote['terms']['certificate_enabled'] ?? true),
                    'course_revision' => $this->publishedRevision($quote['course']),
                ],
            ]);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل فرق الترقية',
            'data' => $quote['payload'],
        ]);
    }

    private function targetPlan(
        Course $course,
        CourseEnrollment $enrollment,
        CourseAccessPlanService $plans,
        ?string $requestedCode = null,
        ?string $requiredFeature = null
    ): ?CourseAccessPlan {
        if (! $requestedCode) {
            return $this->upgrades->availablePlans($course, $enrollment, $requiredFeature)->first();
        }

        return $this->upgrades->targetPlan($course, $enrollment, $requestedCode, $requiredFeature);
    }

    private function upgradePrice(
        Course $course,
        CourseEnrollment $enrollment,
        ?CourseAccessPlan $targetPlan,
        CourseAccessPlanService $plans
    ): ?int {
        if (! $course->isPublishedForLearning() || ! $targetPlan) {
            return null;
        }

        return $this->upgrades->upgradePrice($course, $enrollment, $targetPlan);
    }

    /** @return array<string,mixed> */
    private function quotePayload(
        User $user,
        Course $course,
        int $price,
        WalletService $wallet,
        ?CourseAccessPlan $targetPlan = null
    ): array {
        $balances = $wallet->balances($user);
        $total = $balances['total'];
        $paid = $balances['paid'];
        $minimumPaidCoins = max(0, (int) ($targetPlan?->minimum_paid_coins ?? 0));
        $paidForCourse = $wallet->coursePaidContribution((int) $user->id, (int) $course->id);
        $paidFloorRemaining = max(0, $minimumPaidCoins - $paidForCourse);
        // The upgrade is exactly the price difference and can be paid only
        // with purchased coins. Rewards are intentionally excluded here.
        $rewardContribution = 0;
        $paidContribution = min($paid, $price);
        $spendable = $paid;
        $deficit = max(0, $price - $spendable);

        $targetContract = $targetPlan
            ? app(CourseAccessPlanService::class)->publicPayload($targetPlan)
            : [];

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
            'total_balance' => $total,
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
            'recommended_packages' => $deficit > 0
                ? Package::query()
                    ->where('is_active', true)
                    ->where('coins', '>=', $deficit)
                    ->where('coins', '>', 0)
                    ->where('price', '>', 0)
                    ->purchasable()
                    ->orderBy('coins')
                    ->limit(3)
                    ->get()
                    ->map(fn (Package $package): array => $this->packagePricing->packagePayload($package))
                : [],
        ];
    }

    private function publishedRevision(Course $course): int
    {
        return max(1, (int) (
            $course->last_published_authoring_version ?: $course->authoring_version
        ));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $status);
    }
}
