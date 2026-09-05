<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\FinancialProvenanceException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseAuthorizationRequest;
use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Package;
use App\Models\Setting;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseCouponService;
use App\Services\FinancialAnomalyService;
use App\Services\FinancialProvenanceService;
use App\Services\PackageChannelPricingService;
use App\Services\StudentNotificationService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CoursePurchaseController extends Controller
{
    public function __construct(private readonly PackageChannelPricingService $packagePricing)
    {
    }

    public function quote(
        CourseAuthorizationRequest $request,
        CourseAccessPlanService $planService,
        CourseCouponService $coupons
    ): JsonResponse {
        $user = auth('api')->user();
        $expectedCourseRevision = $request->filled('expected_course_revision')
            ? $request->integer('expected_course_revision')
            : null;

        try {
            $quoteData = DB::transaction(function () use (
                $request,
                $user,
                $planService,
                $coupons,
                $expectedCourseRevision
            ): array {
                // Shared course locks let many learners quote concurrently but
                // serialize them against the exclusive lock used by authoring.
                // Plan capabilities therefore cannot change between checking
                // the published revision and reading the selected plan.
                $course = Course::query()->sharedLock()->findOrFail($request->course_id);
                if (!$this->isAvailableForNewPurchase($course)) {
                    throw new \DomainException('course_not_available');
                }
                if ($expectedCourseRevision !== null
                    && $expectedCourseRevision !== $this->publishedRevision($course)) {
                    throw new \DomainException('course_terms_changed');
                }

                $plan = $planService->selectedPlan(
                    $course,
                    strtolower(trim((string) $request->input('access_plan_code')))
                );
                $price = max(0, (int) $plan->price_coins);
                $minimumPaid = max(0, (int) $plan->minimum_paid_coins);
                $quote = $coupons->quote(
                    (int) $user->id,
                    (int) $course->id,
                    $price,
                    $minimumPaid,
                    $request->input('coupon_code')
                );

                return compact('course', 'plan', 'price', 'quote');
            }, 3);
            $course = $quoteData['course'];
            $plan = $quoteData['plan'];
            $price = $quoteData['price'];
            $quote = $quoteData['quote'];

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم حساب الإجمالي',
                'data' => [
                    'course_id' => (int) $course->id,
                    'course_revision' => $this->publishedRevision($course),
                    'access_plan_code' => $plan->code,
                    'original_price' => $price,
                    'discount_amount' => (int) $quote['discount'],
                    'final_price' => (int) $quote['final'],
                    'coupon' => $quote['coupon'] ? [
                        'code' => $quote['code'],
                        'discount_percentage' => (int) $quote['percentage'],
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
            $code = $exception->getMessage();

            return response()->json([
                'status' => in_array($code, ['course_not_available', 'course_terms_changed'], true) ? 409 : 422,
                'success' => false,
                'code' => $code,
                'message' => match ($code) {
                    'course_not_available' => 'هذا الكورس غير متاح للشراء الآن',
                    'course_terms_changed' => "تغيّرت تفاصيل الفئة\nراجعها قبل الشراء",
                    'coupon_already_used' => 'استخدمت هذا الكود من قبل',
                    'coupon_not_applicable' => 'لا ينطبق الخصم على هذه الفئة',
                    'coupon_quota_reached' => 'اكتمل عدد مرات استخدام هذا الكود',
                    default => 'الكود غير صحيح أو انتهت صلاحيته',
                },
                'data' => null,
            ], in_array($code, ['course_not_available', 'course_terms_changed'], true) ? 409 : 422);
        }
    }

    public function authorizeCourse(
        CourseAuthorizationRequest $request,
        WalletService $walletService,
        FinancialProvenanceService $provenance,
        FinancialAnomalyService $financialRisk,
        CourseAccessPlanService $planService,
        CourseCouponService $coupons,
        AiEntitlementBudgetService $aiBudget
    ): JsonResponse
    {
        $user = auth('api')->user();
        $course = Course::findOrFail($request->course_id);
        $requestedPlanCode = strtolower(trim((string) $request->input('access_plan_code')));
        $clientIdempotencyKey = $request->filled('idempotency_key')
            ? (string) $request->input('idempotency_key')
            : null;
        $requestedCouponCode = $coupons->normalize($request->input('coupon_code'));
        $expectedPrice = $request->filled('expected_price')
            ? $request->integer('expected_price')
            : null;
        $expectedCourseRevision = $request->filled('expected_course_revision')
            ? $request->integer('expected_course_revision')
            : null;
        try {
            $result = DB::transaction(function () use (
                $user,
                $course,
                $walletService,
                $provenance,
                $planService,
                $coupons,
                $aiBudget,
                $requestedPlanCode,
                $requestedCouponCode,
                $clientIdempotencyKey,
                $expectedPrice,
                $expectedCourseRevision
            ): array {
                // The learner is the financial aggregate: wallet balance,
                // enrollment and idempotency serialize there. Buyers take a
                // shared course lock, so they still run concurrently while an
                // exclusive authoring publish cannot replace the selected plan
                // between revision validation and receipt creation.
                \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);
                $lockedCourse = Course::query()->sharedLock()->findOrFail($course->id);

                $existingEnrollment = CourseEnrollment::query()
                    ->where('user_id', $user->id)
                    ->where('course_id', $lockedCourse->id)
                    ->lockForUpdate()
                    ->first();

                if ($clientIdempotencyKey !== null) {
                    $replayedOrder = Order::query()
                        ->with(['bill', 'accessPlan'])
                        ->where('user_id', $user->id)
                        ->where('checkout_request_key', $clientIdempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($replayedOrder) {
                        if (!$this->isSamePurchaseReplay(
                            $replayedOrder,
                            (int) $lockedCourse->id,
                            $requestedPlanCode,
                            $requestedCouponCode,
                            $expectedPrice
                        )) {
                            throw new \DomainException('checkout_idempotency_conflict');
                        }
                        if (!$existingEnrollment) {
                            throw new \LogicException('Committed course order has no enrollment.');
                        }
                        if (
                            !$replayedOrder->isFinanciallyEffective()
                            || !$existingEnrollment->isActive()
                            || $provenance->enrollmentHasActiveHold($existingEnrollment, ['course'])
                        ) {
                            throw new \DomainException('course_purchase_not_effective');
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
                            && !$existingEnrollment->order->isFinanciallyEffective())
                        || $provenance->enrollmentHasActiveHold($existingEnrollment, ['course'])
                    ) {
                        throw new \DomainException('course_access_under_review');
                    }
                    $currentTerms = $planService->termsForEnrollment($existingEnrollment);
                    $currentPlanCode = strtolower(trim((string) ($currentTerms['code'] ?? '')));
                    if (
                        $currentPlanCode === ''
                        || !hash_equals($currentPlanCode, $requestedPlanCode)
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

                if (!$this->isAvailableForNewPurchase($lockedCourse)) {
                    throw new \DomainException('course_not_available');
                }
                if ($expectedCourseRevision !== null
                    && $expectedCourseRevision !== $this->publishedRevision($lockedCourse)) {
                    throw new \DomainException('course_terms_changed');
                }

                $selectedPlan = $planService->selectedPlan($lockedCourse, $requestedPlanCode);
                $amount = max(0, (int) $selectedPlan->price_coins);

                $checkoutKey = $clientIdempotencyKey ?: sprintf(
                    'server:course-purchase:%d:%d:%s',
                    $user->id,
                    $lockedCourse->id,
                    Str::orderedUuid()->toString()
                );
                $walletIdempotencyKey = 'course-purchase:' . hash(
                    'sha256',
                    $user->id . '|' . $checkoutKey
                );
                $planSnapshot = $planService->snapshot($selectedPlan, now());
                $minimumPaidCoins = max(0, (int) ($planSnapshot['minimum_paid_coins'] ?? 0));
                $couponQuote = $coupons->quote(
                    (int) $user->id,
                    (int) $lockedCourse->id,
                    $amount,
                    $minimumPaidCoins,
                    $requestedCouponCode,
                    true
                );
                $finalAmount = (int) $couponQuote['final'];
                if ($expectedPrice !== null && $expectedPrice !== $finalAmount) {
                    throw new \DomainException('course_price_changed');
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
                    (int) $lockedCourse->id
                );
                $paidFloorRemaining = max(
                    0,
                    $minimumPaidCoins - $walletService->coursePaidContribution(
                        (int) $user->id,
                        (int) $lockedCourse->id
                    )
                );
                $maximumRewardForPurchase = min(
                    $rewardContribution['remaining'],
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

                $enrollment = $existingEnrollment ?: new CourseEnrollment([
                    'user_id' => $user->id,
                    'course_id' => $lockedCourse->id,
                ]);
                if ($existingEnrollment) {
                    // A repurchase starts a fresh AI entitlement cycle.
                    $aiBudget->resetForNewPurchase($existingEnrollment);
                }
                $enrollment->fill([
                    'order_id' => $order->id,
                    'access_plan_order_id' => $order->id,
                    'access_plan_id' => $selectedPlan->id,
                    'access_plan_snapshot' => $planSnapshot,
                    'enrolled_at' => $enrollment->enrolled_at ?: now(),
                    'expires_at' => null,
                    'is_active' => true,
                    'access_granted_at' => now(),
                ])->save();

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
        } catch (InsufficientWalletBalanceException $exception) {
            $deficit = max(0, $exception->required - $exception->balance);
            $freshUser = $user->fresh();
            $balances = $walletService->balances($freshUser);
            $totalBalance = $balances['total'];
            $purchasedBalance = $balances['paid'];
            $rewardBalance = $balances['reward'];
            $rewardContribution = $this->rewardContribution(
                $walletService,
                (int) $user->id,
                (int) $course->id
            );
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
            $code = $exception->getMessage();
            $couponFailure = in_array($code, [
                'coupon_invalid',
                'coupon_already_used',
                'coupon_not_applicable',
                'coupon_quota_reached',
            ], true);

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
                    'course_price_changed' => 'تغير السعر\nراجع الإجمالي قبل الشراء',
                    'course_terms_changed' => "تغيّرت تفاصيل الفئة\nراجعها قبل الشراء",
                    'course_purchase_not_effective', 'course_access_under_review' =>
                        "هذه العملية قيد المراجعة\nلم يُخصم رصيد جديد",
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

        if (!$result['already_enrolled']) {
            try {
                StudentNotificationService::notifyUser(
                    $user->fresh(),
                    StudentNotificationService::TYPE_COURSE_ENROLLED,
                    'الكورس جاهز',
                    'Course unlocked',
                    $course->name_ar . "\nابدأ أول مقطع الآن",
                    'You can now start: ' . $course->name_en,
                    '/course/' . $course->id,
                    Course::class,
                    $course->id,
                    'course-enrolled:order:' . ($result['order']?->id ?? $result['enrollment']->id),
                    ['course' => (string) ($course->name_ar ?: $course->name_en)]
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
            (int) $course->id
        );
        $minimumPaidCoins = max(0, (int) data_get($result, 'plan_terms.minimum_paid_coins', 0));
        $paidContributionForCourse = $walletService->coursePaidContribution(
            (int) $user->id,
            (int) $course->id
        );
        $financialReviewRequired = !$financialRisk->allowsVariableCostFeatures(
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
    private function rewardContribution(WalletService $wallet, int $userId, int $courseId): array
    {
        $cap = max(
            0,
            (int) (Setting::query()->value('max_reward_contribution_per_course') ?? 1200)
        );

        return $wallet->courseRewardContribution($userId, $courseId, $cap);
    }

    private function isAvailableForNewPurchase(Course $course): bool
    {
        return (bool) $course->is_catalog_visible
            && $course->isPublishedForLearning();
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
    ): bool
    {
        if (
            (int) $order->course_id !== $courseId
            || $order->package_id !== null
            || $order->payment_method !== Order::PAYMENT_METHOD_WALLET_COINS
            || $order->status !== Order::STATUS_APPROVED
        ) {
            return false;
        }

        if (!hash_equals((string) $order->coupon_code, (string) $requestedCouponCode)) {
            return false;
        }
        if ($expectedPrice !== null && (int) $order->final_amount !== $expectedPrice) {
            return false;
        }

        return (string) data_get($order->access_plan_snapshot, 'code') === $requestedPlanCode;
    }
}
