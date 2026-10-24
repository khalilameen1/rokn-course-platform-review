<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCheckoutRejected;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Support\AiRequestTokenEstimate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Read-side offers. Authoring, presentation and settlement use the same rules. */
final readonly class CoursePlanUpgradeEligibilityService
{
    public function __construct(
        private CourseAccessPlanService $plans,
        private WalletService $wallet,
        private FinancialEntitlementHoldReadService $holds,
        private AiEntitlementBudgetService $budgets,
        private CourseChatPromptContextService $chatContext,
        private AiPromptPolicy $promptPolicy,
    ) {}

    public function targetPlan(Course $course, CourseEnrollment $enrollment,
        ?string $requestedCode = null, ?string $requiredFeature = null): ?CourseAccessPlan
    {
        if ($this->legacyPaidEnrollment($enrollment)) {
            return null;
        }
        $available = $this->higherPlans($course, $this->plans->termsForEnrollment($enrollment) ?? []);
        if ($this->holds->enrollmentHasActiveHold($enrollment, ['course', 'chat', 'plan'])) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::COURSE_ACCESS_UNDER_REVIEW);
        }
        if ($requestedCode !== null) {
            $target = $available->firstWhere('code', $requestedCode);
            if (! $target) {
                throw new CourseCheckoutRejected(CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_AVAILABLE);
            }
            if ($requiredFeature !== null && ! $this->supportsFeature($course, $target, $requiredFeature, $enrollment)) {
                throw new CourseCheckoutRejected(CourseCheckoutRejected::CHECKOUT_FEATURE_UNAVAILABLE);
            }
        } else {
            $target = $this->availablePlans($course, $enrollment, $requiredFeature)->first();
        }
        if ($target) {
            $this->plans->assertPurchasableEconomics($target);
        }

        return $target;
    }

    /** Only actionable, published offers; this method never grants or charges. */
    public function availablePlans(Course $course, CourseEnrollment $enrollment, ?string $feature = null): Collection
    {
        if (! $course->isPublishedForLearning() || ! $enrollment->isActive()
            || $this->legacyPaidEnrollment($enrollment)
            || $this->holds->enrollmentHasActiveHold($enrollment, ['course', 'chat', 'plan'])) {
            return collect();
        }

        return $this->higherPlans($course, $this->plans->termsForEnrollment($enrollment) ?? [])
            ->filter(function (CourseAccessPlan $plan) use ($course, $enrollment, $feature): bool {
                if ($feature !== null && ! $this->supportsFeature($course, $plan, $feature, $enrollment)) {
                    return false;
                }
                try {
                    $this->plans->assertPurchasableEconomics($plan);

                    return $this->upgradePrice($course, $enrollment, $plan) !== null;
                } catch (ValidationException $exception) {
                    return false;
                } catch (CourseCheckoutRejected $exception) {
                    if ($exception->reason !== CourseCheckoutRejected::FULL_TRACK_UPGRADE_PAID_FLOOR_UNFUNDED) {
                        throw $exception;
                    }

                    return false;
                }
            })->values();
    }

    public function upgradePrice(Course $course, CourseEnrollment $enrollment, ?CourseAccessPlan $targetPlan): ?int
    {
        if (! $course->isPublishedForLearning() || ! $targetPlan) {
            return null;
        }
        $current = $this->plans->termsForEnrollment($enrollment);
        // A grant captures Basic capabilities, not a purchase credit. Follow
        // the CURRENT plan receipt so a subsequent paid Plus -> Pro upgrade
        // still deducts Plus, even when learning originally began with a code.
        $sourceMethod = Order::query()
            ->whereKey($enrollment->access_plan_order_id ?: $enrollment->order_id)
            ->value('payment_method');
        $currentCredit = $sourceMethod === Order::PAYMENT_METHOD_COURSE_CODE
            ? 0 : (int) ($current['price_coins'] ?? 0);
        $difference = max(0, (int) $targetPlan->price_coins - $currentCredit);
        $remainingPaidFloor = max(0, (int) $targetPlan->minimum_paid_coins
            - $this->wallet->coursePaidContribution((int) $enrollment->user_id, (int) $course->id));
        if ($remainingPaidFloor > $difference) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::FULL_TRACK_UPGRADE_PAID_FLOOR_UNFUNDED);
        }

        return $difference;
    }

    /** Working-draft preview for a new learner, never the staff actor's usage. */
    public function previewPlanCodes(Course $course, string $currentCode, string $feature): array
    {
        $current = $this->plans->publicPlans($course)->firstWhere('code', $currentCode);
        $terms = $current ? $this->plans->snapshot($current) : ['code' => $currentCode];

        return $this->higherPlans($course, $terms)
            ->filter(fn ($plan) => $this->supportsFeature($course, $plan, $feature))
            ->pluck('code')->values()->all();
    }

    private function higherPlans(Course $course, array $currentTerms): Collection
    {
        $currentRank = $this->rank((string) ($currentTerms['code'] ?? ''), (int) ($currentTerms['sort_order'] ?? 0));

        return $this->plans->publicPlans($course)
            ->filter(fn (CourseAccessPlan $plan): bool => in_array($plan->code, [CourseAccessPlan::GUIDED, CourseAccessPlan::MENTOR], true)
                && $this->rank((string) $plan->code, (int) $plan->sort_order) > $currentRank)
            ->sortBy(fn ($plan) => $this->rank((string) $plan->code, (int) $plan->sort_order))->values();
    }

    private function supportsFeature(Course $course, CourseAccessPlan $plan, string $feature, ?CourseEnrollment $enrollment = null): bool
    {
        $contract = $this->plans->publicPayload($plan);
        $terms = $this->plans->snapshot($plan);

        return match ($feature) {
            'chat' => (bool) $contract['chat_enabled']
                && $this->budgets->hasChatCapacity($terms, AiRequestTokenEstimate::forContents(
                    [$this->chatContext->courseBrief($course), $this->chatContext->responseContract(), '؟'],
                    AiRequestTokenEstimate::courseChatOutputLimit($terms)
                ), $enrollment),
            'project_discussion' => (bool) $contract['projects_enabled']
                && (bool) $contract['project_thread_reply_enabled']
                && $this->budgets->hasProjectFollowupCapacity($terms, AiRequestTokenEstimate::minimumProjectFollowup(
                    $terms, $this->promptPolicy->projectFollowup('', '')
                ), $enrollment),
            default => false,
        };
    }

    private function legacyPaidEnrollment(CourseEnrollment $enrollment): bool
    {
        return ! $this->plans->termsForEnrollment($enrollment) && $enrollment->order
            && $enrollment->order->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE;
    }

    private function rank(string $code, int $fallback): int
    {
        return match ($code) {
            CourseAccessPlan::BASIC => 10,
            CourseAccessPlan::GUIDED => 20,
            CourseAccessPlan::MENTOR => 30,
            default => max(0, $fallback),
        };
    }
}
