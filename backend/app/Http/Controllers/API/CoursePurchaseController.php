<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CourseCheckoutRejected;
use App\Exceptions\FinancialProvenanceException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseAuthorizationRequest;
use App\Http\Requests\API\CoursePurchaseQuoteRequest;
use App\Models\Course;
use App\Models\Package;
use App\Services\CourseAccessPlanService;
use App\Services\CourseCheckoutQuoteService;
use App\Services\CourseCouponService;
use App\Services\CoursePromotionPolicy;
use App\Services\CoursePurchaseAction;
use App\Services\FinancialAnomalyService;
use App\Services\FinancialProvenanceService;
use App\Services\PackageChannelPricingService;
use App\Services\StudentNotificationService;
use App\Services\WalletService;
use App\Support\StudentNotificationIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CoursePurchaseController extends Controller
{
    public function __construct(
        private readonly PackageChannelPricingService $packagePricing,
        private readonly StudentNotificationService $notifications
    ) {}

    /**
     * Legacy purchase quote kept for the shipped course-details screen. The
     * terms come from the same CourseCheckoutQuoteService that prices
     * course-checkouts, so this total always equals what authorize debits.
     */
    public function quote(
        CoursePurchaseQuoteRequest $request,
        CourseCheckoutQuoteService $quotes
    ): JsonResponse {
        $user = auth('api')->user();
        $expectedCourseRevision = $request->filled('expected_course_revision')
            ? $request->integer('expected_course_revision')
            : null;

        try {
            $terms = DB::transaction(function () use ($request, $user, $quotes, $expectedCourseRevision): array {
                // Revision first, as before: a superseded screen re-reads its
                // plans before any coupon decision is shown to the learner.
                $course = Course::query()->sharedLock()->findOrFail($request->course_id);
                if ($expectedCourseRevision !== null
                    && $expectedCourseRevision !== $this->publishedRevision($course)) {
                    throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_TERMS_CHANGED);
                }

                return $quotes->calculate($user, [
                    'course_id' => (int) $course->id,
                    'access_plan_code' => strtolower(trim((string) $request->input('access_plan_code'))),
                    'coupon_code' => $request->input('coupon_code'),
                    // Pricing only: no funding package is bound by this quote.
                    'channel' => 'direct',
                ], true, false);
            }, 3);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم حساب الإجمالي',
                'data' => [
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
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'course_plan_unavailable',
                'message' => collect($exception->errors())->flatten()->first()
                    ?: 'راجع الفئة المختارة',
                'errors' => $exception->errors(),
                'data' => null,
            ], 422);
        } catch (\DomainException $exception) {
            $code = CourseCheckoutRejected::codeFor($exception);
            $status = in_array($code, CourseCheckoutRejected::COUPON_REJECTIONS, true) ? 422 : 409;

            return response()->json([
                'status' => $status,
                'success' => false,
                'code' => $code,
                'message' => match ($code) {
                    CourseCheckoutRejected::COURSE_TERMS_CHANGED => "تغيّرت تفاصيل الفئة\nراجعها قبل الشراء",
                    CourseCheckoutRejected::COURSE_ACCESS_CHANGED => "الكورس مضاف إلى حسابك بالفعل\nحدّث الصفحة",
                    CourseCheckoutRejected::COUPON_ALREADY_USED => 'استخدمت هذا الكود من قبل',
                    CourseCheckoutRejected::COUPON_NOT_APPLICABLE => 'لا ينطبق الخصم على هذه الفئة',
                    CourseCheckoutRejected::COUPON_QUOTA_REACHED => 'اكتمل عدد مرات استخدام هذا الكود',
                    CourseCheckoutRejected::COUPON_INVALID => 'الكود غير صحيح أو انتهت صلاحيته',
                    default => 'هذا الكورس غير متاح للشراء الآن',
                },
                'data' => null,
            ], $status);
        }
    }

    public function authorizeCourse(
        CourseAuthorizationRequest $request,
        WalletService $walletService,
        FinancialProvenanceService $provenance,
        FinancialAnomalyService $financialRisk,
        CourseAccessPlanService $planService,
        CourseCouponService $coupons
    ): JsonResponse {
        $user = auth('api')->user();
        $course = Course::findOrFail($request->course_id);
        $requestedPlanCode = strtolower(trim((string) $request->input('access_plan_code')));
        $clientIdempotencyKey = $request->filled('idempotency_key')
            ? (string) $request->input('idempotency_key')
            : null;
        $requestedCouponCode = $coupons->normalize($request->input('coupon_code'));
        $expectedPrice = $request->integer('expected_price');
        $expectedCourseRevision = $request->filled('expected_course_revision')
            ? $request->integer('expected_course_revision')
            : null;
        try {
            $result = app(CoursePurchaseAction::class)->execute(
                $user, $course, $requestedPlanCode, $clientIdempotencyKey,
                $expectedPrice, $expectedCourseRevision, $requestedCouponCode
            );
        } catch (InsufficientWalletBalanceException $exception) {
            $deficit = max(0, $exception->required - $exception->balance);
            $freshUser = $user->fresh();
            $balances = $walletService->balances($freshUser);
            $totalBalance = $balances['total'];
            $purchasedBalance = $balances['paid'];
            $rewardBalance = $balances['reward'];
            try {
                $selectedPlan = $planService->selectedPlan($course, $requestedPlanCode);
            } catch (ValidationException $validationException) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'code' => 'course_plan_unavailable',
                    'message' => collect($validationException->errors())->flatten()->first()
                        ?: 'هذه الفئة لم تعد متاحة',
                    'data' => null,
                ], 422);
            }
            $rewardContribution = $this->rewardContribution(
                $walletService, (int) $user->id, (int) $course->id, (int) $selectedPlan->price_coins
            );
            $minimumPaidCoins = max(0, (int) $selectedPlan->minimum_paid_coins);
            $paidFloorRemaining = max(
                0,
                $minimumPaidCoins - $walletService->coursePaidContribution(
                    (int) $user->id,
                    (int) $course->id
                )
            );
            $recommendedPackages = Package::query()
                ->where('is_active', true)
                ->where('coins', '>=', $deficit)
                ->where('coins', '>', 0)
                ->where('price', '>', 0)
                ->purchasable()
                ->orderBy('coins')
                ->limit(3)
                ->get()
                ->map(fn (Package $package): array => $this->packagePricing->packagePayload($package));

            return response()->json([
                'status' => 400,
                'success' => false,
                'code' => 'insufficient_coins',
                'message' => 'رصيدك لا يكفي',
                'data' => [
                    'required_coins' => $exception->required,
                    'total_balance' => $totalBalance,
                    'purchased_balance' => $purchasedBalance,
                    'reward_balance' => $rewardBalance,
                    'spendable_balance' => $exception->balance,
                    'reward_contribution_cap_per_course' => $rewardContribution['cap'],
                    'reward_contribution_used_for_course' => $rewardContribution['used'],
                    'reward_contribution_remaining_for_course' => $rewardContribution['remaining'],
                    'minimum_paid_coins_required' => $minimumPaidCoins,
                    'paid_coin_floor_remaining' => $paidFloorRemaining,
                    'deficit' => $deficit,
                    'recommended_packages' => $recommendedPackages,
                    // Mobile resumes the purchase after the embedded checkout.
                    'resume_action' => [
                        'type' => 'purchase_course',
                        'course_id' => $course->id,
                        'access_plan_code' => $requestedPlanCode,
                    ],
                ],
            ], 400);
        } catch (FinancialProvenanceException $exception) {
            report($exception);

            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'financial_provenance_unavailable',
                'message' => "تعذّر إكمال الشراء الآن\nلم يتغير رصيدك",
                'data' => null,
            ], 409);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'course_plan_unavailable',
                'message' => collect($exception->errors())->flatten()->first()
                    ?: 'راجع الفئة المختارة',
                'errors' => $exception->errors(),
                'data' => null,
            ], 422);
        } catch (\DomainException $exception) {
            $code = CourseCheckoutRejected::codeFor($exception);
            $couponFailure = in_array($code, CourseCheckoutRejected::COUPON_REJECTIONS, true);

            return response()->json([
                'status' => $couponFailure ? 422 : 409,
                'success' => false,
                'code' => $code,
                'message' => match ($code) {
                    'checkout_idempotency_conflict' => "تغيّر طلب الشراء أثناء التنفيذ\nأعد المحاولة",
                    'coupon_already_used' => 'استخدمت هذا الكود من قبل',
                    'coupon_not_applicable' => 'لا ينطبق الخصم على هذه الفئة',
                    'coupon_quota_reached' => 'اكتمل عدد مرات استخدام هذا الكود',
                    'coupon_invalid' => 'الكود غير صحيح أو انتهت صلاحيته',
                    'course_price_changed' => "تغير السعر\nراجع الإجمالي قبل الشراء",
                    'course_terms_changed' => "تغيّرت تفاصيل الفئة\nراجعها قبل الشراء",
                    'course_purchase_not_effective', 'course_access_under_review' => "هذه العملية قيد المراجعة\nلم يُخصم رصيد جديد",
                    default => 'هذا الكورس غير متاح للشراء الآن',
                },
                'data' => null,
            ], $couponFailure ? 422 : 409);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر إكمال الشراء\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }

        if (($result['access_changed'] ?? false) === true) {
            $currentTerms = is_array($result['current_plan_terms'] ?? null)
                ? $result['current_plan_terms']
                : null;

            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'course_access_changed',
                'message' => "تغيّر وصولك إلى الكورس\nحدّث الصفحة قبل المتابعة",
                'data' => [
                    'course_id' => (int) $result['course_id'],
                    'requested_access_plan_code' => (string) $result['requested_plan_code'],
                    'current_access_plan' => $currentTerms
                        ? $planService->publicPayloadFromTerms($currentTerms)
                        : null,
                    'already_enrolled' => true,
                ],
            ], 409);
        }

        if (! $result['already_enrolled']) {
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
                        deliveryKey: 'course-enrolled:order:'.($result['order']?->id ?? $result['enrollment']->id),
                        templateVariables: ['course' => (string) ($course->name_ar ?: $course->name_en)]
                    )
                );
            } catch (\Throwable $exception) {
                // A push outage must never turn a completed purchase into an apparent failure.
                report($exception);
            }
        }

        $freshUser = $user->fresh();
        $balances = $walletService->balances($freshUser);
        $totalBalance = $balances['total'];
        $purchasedBalance = $balances['paid'];
        $rewardBalance = $balances['reward'];
        $rewardContribution = $this->rewardContribution(
            $walletService,
            (int) $user->id,
            (int) $course->id,
            (int) data_get($result, 'plan_terms.price_coins', $course->price)
        );
        $minimumPaidCoins = max(0, (int) data_get($result, 'plan_terms.minimum_paid_coins', 0));
        $paidContributionForCourse = $walletService->coursePaidContribution(
            (int) $user->id,
            (int) $course->id
        );
        $financialReviewRequired = ! $financialRisk->allowsVariableCostFeatures(
            $result['enrollment']->fresh()
        );

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $result['already_enrolled']
                ? 'الكورس مضاف إلى حسابك بالفعل'
                : 'تمت إضافة الكورس إلى حسابك',
            'data' => [
                'order_id' => $result['order']?->id,
                'bill_id' => $result['bill']?->id,
                'enrollment_id' => $result['enrollment']->id,
                'amount_deducted' => $result['amount'],
                'original_price' => (int) ($result['original_amount'] ?? $result['order']?->amount ?? 0),
                'discount_amount' => (int) ($result['discount_amount'] ?? $result['order']?->discount_amount ?? 0),
                'coupon_code' => $result['coupon_code'] ?? $result['order']?->coupon_code,
                'total_balance' => $totalBalance,
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
                    'total_coins' => (int) ($result['order']?->total_coins ?? 0),
                    'paid_coins' => (int) ($result['order']?->paid_coins ?? 0),
                    'reward_coins' => (int) ($result['order']?->reward_coins ?? 0),
                    'spend_policy' => 'reward_first_then_paid',
                ],
                'access_plan' => $result['plan_terms']
                    ? $planService->publicPayloadFromTerms($result['plan_terms'])
                    : null,
                'already_enrolled' => $result['already_enrolled'],
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
            ],
        ]);
    }

    /** @return array{cap:int,used:int,remaining:int} */
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
}
