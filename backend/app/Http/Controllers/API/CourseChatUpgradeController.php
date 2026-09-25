<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\FinancialProvenanceException;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Services\CourseEntitlementService;
use App\Services\CourseAccessPlanService;
use App\Services\FinancialAnomalyService;
use App\Services\FinancialProvenanceService;
use App\Services\PackageChannelPricingService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Upgrades an existing enrolment to its next paid service plan. The legacy
 * route name and response codes remain stable for older mobile builds.
 */
final class CourseChatUpgradeController extends Controller
{
    public function __construct(private readonly PackageChannelPricingService $packagePricing)
    {
    }

    public function quote(
        Request $request,
        Course $course,
        CourseEntitlementService $access,
        CourseAccessPlanService $plans,
        WalletService $wallet
    ): JsonResponse
    {
        $user = auth('api')->user();
        $validated = $request->validate([
            'target_plan_code' => 'nullable|string|in:guided,mentor',
        ]);
        $requestedCode = isset($validated['target_plan_code'])
            ? (string) $validated['target_plan_code']
            : null;
        $entitlement = $access->entitlementFor((int) $user->id, (int) $course->id);

        if (!$entitlement['has_learning_access']) {
            return $this->error('course_access_required', 'افتح الكورس أولًا قبل الترقية', 403);
        }
        $enrollment = $access->activeEnrollmentFor((int) $user->id, (int) $course->id);
        if (!$enrollment) {
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
                $entitlement,
                $user,
                $wallet
            ): array {
                $paidCourse = Course::query()
                    ->sharedLock()
                    ->findOrFail($enrollment->course_id);
                $enrollment->setRelation('course', $paidCourse);
                $targetPlan = $this->targetPlan(
                    $paidCourse,
                    $enrollment,
                    $plans,
                    $requestedCode
                );

                if ($entitlement['chat_available'] && !$targetPlan) {
                    $terms = $plans->termsForEnrollment($enrollment);

                    return [
                        'already_upgraded' => true,
                        'course' => $paidCourse,
                        'terms' => $terms,
                    ];
                }

                $price = $this->upgradePrice($paidCourse, $enrollment, $targetPlan, $plans);
                if ($price === null) {
                    throw new \DomainException('full_track_upgrade_not_priced');
                }

                return [
                    'already_upgraded' => false,
                    'payload' => $this->quotePayload(
                        $user->fresh(),
                        $paidCourse,
                        $price,
                        $wallet,
                        $targetPlan
                    ),
                ];
            }, 3);
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'full_track_upgrade_paid_floor_unfunded') {
                return $this->error('full_track_upgrade_paid_floor_unfunded', 'الترقية غير متاحة بالسعر الحالي', 409);
            }
            if ($exception->getMessage() === 'full_track_upgrade_not_priced') {
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

    public function purchase(
        Request $request,
        Course $course,
        CourseEntitlementService $access,
        WalletService $wallet,
        FinancialProvenanceService $provenance,
        FinancialAnomalyService $financialRisk,
        CourseAccessPlanService $plans
    ): JsonResponse
    {
        $user = auth('api')->user();
        if (!$request->filled('idempotency_key') && $request->hasHeader('Idempotency-Key')) {
            $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        }
        $validated = $request->validate([
            // The target is part of the commercial intent. Inferring "next"
            // on a retry could advance twice if the first response was lost.
            'target_plan_code' => 'required|string|in:guided,mentor',
            // Bind the debit to the quote the learner actually reviewed. A
            // moderator price edit between quote and tap must never result in
            // a silent higher (or different) wallet debit.
            'expected_price' => 'required|integer|min:0|max:100000000',
            'expected_course_revision' => 'nullable|integer|min:1',
            'idempotency_key' => [
                'nullable',
                'string',
                'min:16',
                'max:140',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{15,139}$/',
            ],
        ]);
        $requestedCode = isset($validated['target_plan_code'])
            ? strtolower(trim((string) $validated['target_plan_code']))
            : null;
        $clientIdempotencyKey = isset($validated['idempotency_key'])
            ? (string) $validated['idempotency_key']
            : null;
        $expectedPrice = (int) $validated['expected_price'];
        $expectedCourseRevision = isset($validated['expected_course_revision'])
            ? (int) $validated['expected_course_revision']
            : null;

        try {
            $result = app(\App\Services\CoursePlanUpgradeAction::class)->execute(
                $user, $course, $requestedCode, $clientIdempotencyKey,
                $expectedPrice, $expectedCourseRevision
            );
        } catch (InsufficientWalletBalanceException $exception) {
            $enrollment = $access->activeEnrollmentFor((int) $user->id, (int) $course->id);
            $paidCourse = $enrollment
                ? ($enrollment->loadMissing('course')->course ?: $course)
                : $course;
            $targetPlan = $enrollment
                ? $this->targetPlan($paidCourse, $enrollment, $plans, $requestedCode)
                : null;
            $price = ($enrollment ? $this->upgradePrice($paidCourse, $enrollment, $targetPlan, $plans) : null)
                ?? max(0, $exception->required);

            return response()->json([
                'status' => 400,
                'success' => false,
                'code' => 'insufficient_coins',
                'message' => 'رصيدك لا يكفي',
                'data' => $this->quotePayload($user->fresh(), $paidCourse, $price, $wallet, $targetPlan),
            ], 400);
        } catch (FinancialProvenanceException $exception) {
            report($exception);
            return $this->error(
                'financial_provenance_unavailable',
                "تعذّر إكمال الترقية الآن\nلم يتغير رصيدك",
                409
            );
        } catch (\DomainException $exception) {
            $code = $exception->getMessage();
            return $this->error(
                $code,
                match ($code) {
                    'course_price_changed' => "تغيّر السعر\nراجع الإجمالي قبل الشراء",
                    'course_terms_changed' => "تغيّرت تفاصيل الفئة\nراجعها قبل الترقية",
                    'course_access_under_review' =>
                        "هذه العملية قيد المراجعة\nلم يُخصم رصيد جديد",
                    default => 'الترقية غير متاحة لهذه الفئة',
                },
                $code === 'course_access_required' ? 403 : 409
            );
        }

        $payload = $this->quotePayload(
            $user->fresh(),
            $result['course'],
            (int) $result['amount'],
            $wallet,
            $result['plan'] ?? null
        );
        $payload['already_upgraded'] = (bool) $result['already_upgraded'];
        $resultTerms = is_array($result['plan_terms'] ?? null) ? $result['plan_terms'] : [];
        $payload['chat_available'] = (bool) ($resultTerms['chat_enabled'] ?? false);
        $payload['certificate_available'] = (bool) ($resultTerms['certificate_enabled'] ?? true);
        $payload['amount_deducted'] = (int) $result['amount'];
        $payload['order_id'] = $result['order']->id ?? null;
        $payload['idempotent_replay'] = (bool) ($result['idempotent_replay'] ?? false);
        $payload['allocation'] = [
            'total_coins' => (int) ($result['order']->total_coins ?? 0),
            'paid_coins' => (int) ($result['order']->paid_coins ?? 0),
            'reward_coins' => (int) ($result['order']->reward_coins ?? 0),
            'spend_policy' => 'reward_first_with_paid_floor',
        ];
        $enrollment = $access->activeEnrollmentFor(
            (int) $user->id,
            (int) $result['course']->id
        );
        $payload['financial_review_required'] = $enrollment
            ? !$financialRisk->allowsVariableCostFeatures($enrollment)
            : true;

        return response()->json([
            'status' => 200,
            'success' => true,
            'code' => $result['already_upgraded'] ? 'full_track_already_available' : 'full_track_upgrade_complete',
            'message' => 'تم تفعيل الفئة المختارة',
            'data' => $payload,
        ]);
    }

    private function targetPlan(
        Course $course,
        CourseEnrollment $enrollment,
        CourseAccessPlanService $plans,
        ?string $requestedCode = null
    ): ?CourseAccessPlan
    {
        // Purchases retain these exact terms in their order snapshot. A
        // concurrent catalogue edit affects the next quote, never this debit.
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
            return $requested;
        }

        // Legacy clients without an explicit target still advance from their
        // current tier. Looking only at the first chat tier incorrectly made a
        // guided learner appear fully upgraded while a mentor tier existed.
        return $available->first(
            fn (CourseAccessPlan $plan): bool => !$currentTerms
                || $this->planRank((string) $plan->code, (int) $plan->sort_order) > $currentRank
        );
    }

    private function upgradePrice(
        Course $course,
        CourseEnrollment $enrollment,
        ?CourseAccessPlan $targetPlan,
        CourseAccessPlanService $plans
    ): ?int
    {
        if (!$course->isPublishedForLearning() || !$targetPlan) {
            return null;
        }

        return app(\App\Services\CoursePlanUpgradeAction::class)->upgradePrice($course, $enrollment, $targetPlan, $plans);
    }

    /** @return array<string,mixed> */
    private function quotePayload(
        User $user,
        Course $course,
        int $price,
        WalletService $wallet,
        ?CourseAccessPlan $targetPlan = null
    ): array
    {
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
