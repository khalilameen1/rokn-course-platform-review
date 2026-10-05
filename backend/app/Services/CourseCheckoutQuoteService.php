<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Package;
use App\Models\User;

/** Calculates immutable checkout terms without charging or enrolling anyone. */
final readonly class CourseCheckoutQuoteService
{
    public function __construct(private WalletService $wallet, private CourseAccessPlanService $plans,
        private CoursePromotionPolicy $promotions, private CourseCouponService $coupons,
        private PackageChannelPricingService $pricing, private CoursePlanUpgradeEligibilityService $upgrades,
        private CourseEntitlementService $access) {}

    public function calculate(User $user, array $input, bool $selectPackage = true, bool $includeFundingOptions = true): array
    {
        if (!$user->active) throw new \DomainException('account_inactive');
        $course = Course::query()->sharedLock()->findOrFail($input['course_id']);
        // Same lock order as purchase/publish, held by the surrounding intent
        // transaction through validation and the final wallet debit.
        $course->accessPlans()->sharedLock()->get();
        $mode = $input['mode'] ?? 'purchase';
        if ($mode === 'upgrade' ? !$course->isPublishedForLearning() : !$course->isAvailableForNewPurchase()) {
            throw new \DomainException('course_not_available');
        }
        $code = $input['access_plan_code'];
        $enrollment = $this->access->activeEnrollmentFor((int) $user->id, (int) $course->id);
        if ($mode === 'upgrade') {
            if (!$enrollment || (int) $enrollment->course_id !== (int) $course->id
                || !$this->access->hasLearningAccess((int) $user->id, (int) $course->id)) {
                throw new \DomainException('course_access_required');
            }
            if ($this->coupons->normalize($input['coupon_code'] ?? null)) throw new \DomainException('coupon_not_applicable');
            $plan = $this->upgrades->targetPlan($course, $enrollment, $code, $input['required_feature'] ?? null);
            $price = $this->upgrades->upgradePrice($course, $enrollment, $plan);
            if ($price === null || !$plan) throw new \DomainException('full_track_upgrade_not_available');
        } else {
            if ($enrollment) throw new \DomainException('course_access_changed');
            $plan = $this->plans->selectedPlan($course, $code);
            $price = max(0, (int) $plan->price_coins);
        }
        $requiredFeature = $input['required_feature'] ?? null;
        if ($requiredFeature !== null) {
            $capabilities = $this->plans->publicPayload($plan);
            $available = match ($requiredFeature) {
                'chat' => (bool) ($capabilities['chat_enabled'] ?? false)
                    && (int) ($capabilities['chat_message_limit'] ?? 0) > 0,
                'project_discussion' => (bool) ($capabilities['projects_enabled'] ?? false)
                    && (bool) ($capabilities['project_thread_reply_enabled'] ?? false)
                    && (int) ($capabilities['project_message_limit'] ?? 0) > 0,
                default => false,
            };
            if (!$available) throw new \DomainException('checkout_feature_unavailable');
        }
        $promotion = $this->promotions->allowance((int) $user->id, (int) $course->id, (int) $plan->price_coins);
        $paidFloor = max(0, (int) $plan->minimum_paid_coins - $this->wallet->coursePaidContribution((int) $user->id, (int) $course->id));
        $coupon = $mode === 'upgrade' ? ['final' => $price, 'discount' => 0, 'code' => null]
            : $this->coupons->quote((int) $user->id, (int) $course->id, $price, $paidFloor, $input['coupon_code'] ?? null);
        $final = (int) $coupon['final'];
        $balances = $this->wallet->balances($user);
        // A plan upgrade settles only the difference between the two plan
        // prices. It is not a second promotional course purchase, so reward
        // coins never reduce that difference.
        $rewardCapacity = $mode === 'upgrade'
            ? 0
            : min(max(0, $promotion['remaining'] - $coupon['discount']), max(0, $final - $paidFloor));
        $maxReward = min($balances['reward'], $rewardCapacity);
        // Discover every package that can fund the course with the allowed
        // rewards. Native localized prices, not catalogue price order, decide
        // which package the client binds in the next quote.
        $minimumDeficit = max(0, $final - $balances['paid'] - $maxReward);
        $channel = $input['channel'];
        if (!in_array($channel, ['google', 'apple', 'direct'], true)) throw new \DomainException('checkout_channel_invalid');
        // Explicit negotiation preserves already-issued legacy/store contracts.
        // Exact funding is a provider amount, never a mutable native product.
        $exactFunding = ($input['funding_mode'] ?? 'package') === 'exact_shortfall';
        if ($exactFunding && $channel !== 'direct') throw new \DomainException('checkout_funding_mode_invalid');
        $eligible = !$includeFundingOptions ? collect() : Package::query()->where('is_active', true)->where('coins', '>', 0)->where('price', '>', 0)
            ->where($channel.'_enabled', true)->orderBy('price')->orderBy('coins')->get()
            ->filter(fn (Package $package): bool => $package->availableChannels()[$channel])
            ->filter(fn (Package $package): bool => $exactFunding || (int) $package->coins >= $minimumDeficit)->values();
        $selected = null;
        $packageId = $input['package_id'] ?? $input['selected_package']['id'] ?? null;
        if ($packageId) {
            // After funding, its issued immutable coin contract remains valid even
            // when the catalogue disables future sales of that denomination.
            $selected = $selectPackage ? $eligible->firstWhere('id', (int) $packageId) : Package::query()->find($packageId);
            if (!$selected || ($selectPackage && $minimumDeficit === 0)) throw new \DomainException('checkout_package_unavailable');
        }
        // The learner's discount is settled before choosing payment funding.
        // A denomination cannot silently remove an already-available benefit.
        $reward = $maxReward;
        $deficit = max(0, $final - $balances['paid'] - $reward);
        $snapshot = $this->plans->snapshot($plan);
        unset($snapshot['purchased_at']);
        // A captured funding order owns its issued denomination AND cash rate.
        // Catalogue availability/rate changes affect future authorizations only.
        $package = $selected ? (!$selectPackage
            ? $input['selected_package']
            : ($exactFunding ? $this->pricing->courseFundingPayload($selected, $deficit)
                : $this->pricing->packagePayload($selected))) : null;
        return [
            'course_id' => (int) $course->id, 'course_revision' => max(1, (int) ($course->last_published_authoring_version ?: $course->authoring_version)),
            'access_plan_code' => $code, 'mode' => $mode, 'channel' => $channel,
            ...($exactFunding ? ['funding_mode' => 'exact_shortfall'] : []),
            ...($requiredFeature !== null ? ['required_feature' => $requiredFeature] : []),
            'original_price' => $price, 'discount_amount' => (int) $coupon['discount'], 'final_price' => $final,
            'coupon_code' => $coupon['code'], 'plan_contract' => $snapshot,
            'enrollment_id' => $enrollment?->id, 'enrollment_order_id' => $enrollment?->access_plan_order_id,
            'paid_coin_floor_remaining' => $paidFloor,
            'reward_policy' => $mode === 'upgrade' ? 'purchased_only' : 'promotion_allowed',
            'promotion' => $promotion,
            'wallet' => $balances, 'purchased_balance' => $balances['paid'], 'reward_balance' => $balances['reward'],
            'allocation' => ['paid_coins' => $final - $reward, 'reward_coins' => $reward],
            'remaining_purchased_balance' => max(0, $balances['paid'] + (int) ($package['coins'] ?? 0) - $final + $reward),
            'remaining_reward_balance' => $balances['reward'] - $reward,
            'deficit' => $deficit, 'selected_package' => $package,
            // Same promotion and paid floor as purchase; never useful on upgrades.
            'reward_opportunity_coins' => min($deficit, max(0, $rewardCapacity - $reward)),
            'recommended_packages' => $minimumDeficit > 0 ? $eligible->map(fn ($row) => $exactFunding
                ? $this->pricing->courseFundingPayload($row, $minimumDeficit)
                : $this->pricing->packagePayload($row))->all() : [],
        ];
    }

    public function commercialHash(array $terms): string
    {
        $fields = array_intersect_key($terms, array_flip(['course_id', 'course_revision', 'access_plan_code', 'mode', 'channel',
            'original_price', 'discount_amount', 'final_price', 'coupon_code', 'plan_contract', 'enrollment_id', 'enrollment_order_id', 'paid_coin_floor_remaining', 'reward_policy', 'required_feature', 'funding_mode']));
        $fields['promotion_percent'] = $terms['promotion']['percent'];
        $package = $terms['selected_package'];
        $fields['package'] = $package ? ['id' => $package['id'], 'coins' => $package['coins'],
            'product' => $package['store_products'][$terms['channel']] ?? null,
            'direct_price' => $terms['channel'] === 'direct' ? $package['direct_price'] : null] : null;
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
