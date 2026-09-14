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
        private PackageChannelPricingService $pricing, private CoursePlanUpgradeAction $upgrades,
        private CourseChatAccessService $access) {}

    public function calculate(User $user, array $input, bool $selectPackage = true): array
    {
        if (!$user->active) throw new \DomainException('account_inactive');
        $course = Course::query()->sharedLock()->findOrFail($input['course_id']);
        // Same lock order as purchase/publish, held by the surrounding intent
        // transaction through validation and the final wallet debit.
        $course->accessPlans()->sharedLock()->get();
        if (!$course->is_catalog_visible || !$course->isPublishedForLearning()) throw new \DomainException('course_not_available');
        $mode = $input['mode'] ?? 'purchase';
        $code = $input['access_plan_code'];
        $enrollment = $this->access->activeEnrollmentFor((int) $user->id, (int) $course->id);
        if ($mode === 'upgrade') {
            if (!$enrollment || (int) $enrollment->course_id !== (int) $course->id) throw new \DomainException('course_access_required');
            if ($this->coupons->normalize($input['coupon_code'] ?? null)) throw new \DomainException('coupon_not_applicable');
            $plan = $this->upgrades->targetPlan($course, $enrollment, $this->plans, $code);
            $price = $this->upgrades->upgradePrice($course, $enrollment, $plan, $this->plans);
            if ($price === null || !$plan) throw new \DomainException('full_track_upgrade_not_available');
        } else {
            if ($enrollment) throw new \DomainException('course_access_changed');
            $plan = $this->plans->selectedPlan($course, $code);
            $price = max(0, (int) $plan->price_coins);
        }
        $promotion = $this->promotions->allowance((int) $user->id, (int) $course->id, (int) $plan->price_coins);
        $paidFloor = max(0, (int) $plan->minimum_paid_coins - $this->wallet->coursePaidContribution((int) $user->id, (int) $course->id));
        $coupon = $mode === 'upgrade' ? ['final' => $price, 'discount' => 0, 'code' => null]
            : $this->coupons->quote((int) $user->id, (int) $course->id, $price, $paidFloor, $input['coupon_code'] ?? null);
        $final = (int) $coupon['final'];
        $balances = $this->wallet->balances($user);
        $maxReward = min($balances['reward'], max(0, $promotion['remaining'] - $coupon['discount']), max(0, $final - $paidFloor));
        $deficit = max(0, $final - $balances['paid'] - $maxReward);
        $channel = $input['channel'];
        if (!in_array($channel, ['google', 'apple', 'direct'], true)) throw new \DomainException('checkout_channel_invalid');
        $eligible = Package::query()->where('is_active', true)->where('coins', '>', 0)->where('price', '>', 0)
            ->where($channel.'_enabled', true)->orderBy('price')->orderBy('coins')->get()
            ->filter(fn (Package $package): bool => $package->availableChannels()[$channel])
            ->filter(fn (Package $package): bool => (int) $package->coins >= $deficit)->values();
        $selected = null;
        $packageId = $input['package_id'] ?? $input['selected_package']['id'] ?? null;
        if ($packageId) {
            // After funding, its issued immutable coin contract remains valid even
            // when the catalogue disables future sales of that denomination.
            $selected = $selectPackage ? $eligible->firstWhere('id', (int) $packageId) : Package::query()->find($packageId);
            if (!$selected || ($selectPackage && $deficit === 0)) throw new \DomainException('checkout_package_unavailable');
        } elseif ($selectPackage && $deficit > 0) {
            $selected = $eligible->first();
        }
        $reward = $selected
            ? min($maxReward, max(0, $final - $balances['paid'] - (int) $selected->coins))
            : $maxReward;
        $snapshot = $this->plans->snapshot($plan);
        unset($snapshot['purchased_at']);
        $package = $selected ? $this->pricing->packagePayload($selected) : null;
        // Preserve issued product identity while disabled; availability is checked
        // at authorization, not after the customer has already paid the store.
        if ($package && !$selectPackage && $channel !== 'direct') {
            $package['store_products'][$channel] = $selected->{$channel.'_product_id'};
        }
        return [
            'course_id' => (int) $course->id, 'course_revision' => max(1, (int) ($course->last_published_authoring_version ?: $course->authoring_version)),
            'access_plan_code' => $code, 'mode' => $mode, 'channel' => $channel,
            'original_price' => $price, 'discount_amount' => (int) $coupon['discount'], 'final_price' => $final,
            'coupon_code' => $coupon['code'], 'plan_contract' => $snapshot,
            'enrollment_id' => $enrollment?->id, 'enrollment_order_id' => $enrollment?->access_plan_order_id,
            'paid_coin_floor_remaining' => $paidFloor, 'promotion' => $promotion,
            'wallet' => $balances, 'purchased_balance' => $balances['paid'], 'reward_balance' => $balances['reward'],
            'allocation' => ['paid_coins' => $final - $reward, 'reward_coins' => $reward],
            'remaining_purchased_balance' => max(0, $balances['paid'] + (int) ($package['coins'] ?? 0) - $final + $reward),
            'remaining_reward_balance' => $balances['reward'] - $reward,
            'deficit' => $deficit, 'selected_package' => $package,
            'recommended_packages' => $deficit > 0 ? $eligible->map(fn ($row) => $this->pricing->packagePayload($row))->all() : [],
        ];
    }

    public function commercialHash(array $terms): string
    {
        $fields = array_intersect_key($terms, array_flip(['course_id', 'course_revision', 'access_plan_code', 'mode', 'channel',
            'original_price', 'discount_amount', 'final_price', 'coupon_code', 'plan_contract', 'enrollment_id', 'enrollment_order_id', 'paid_coin_floor_remaining']));
        $fields['promotion_percent'] = $terms['promotion']['percent'];
        $package = $terms['selected_package'];
        $fields['package'] = $package ? ['id' => $package['id'], 'coins' => $package['coins'],
            'product' => $package['store_products'][$terms['channel']] ?? null,
            'direct_price' => $terms['channel'] === 'direct' ? $package['direct_price'] : null] : null;
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
