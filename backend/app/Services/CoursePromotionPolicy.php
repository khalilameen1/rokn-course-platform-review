<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;

/** One lifetime allowance shared by coupons, earned coins and every upgrade. */
final readonly class CoursePromotionPolicy
{
    public function __construct(private WalletService $wallet) {}

    /** @return array{percent:int,cap:int,used:int,remaining:int,reward_used:int,coupon_used:int} */
    public function allowance(int $userId, int $courseId, int $targetPrice): array
    {
        // select(*) also supports an older rolling-upgrade/partial test schema;
        // an existing configured percentage is never silently ignored.
        $setting = Setting::query()->sharedLock()->first();
        $percent = min(20, max(0, (int) ($setting?->getAttribute('max_course_promotion_percent')
            ?? config('course_plans.max_promotion_percent', 20))));
        $cap = intdiv(max(0, $targetPrice) * $percent, 100);
        // Lifetime debit history deliberately does not reset after a refund.
        $reward = $this->wallet->courseRewardContribution($userId, $courseId, $cap)['used'];
        $coupon = max(0, (int) Order::query()->where('user_id', $userId)
            ->where('course_id', $courseId)->whereNull('package_id')
            ->where('payment_method', Order::PAYMENT_METHOD_WALLET_COINS)
            ->whereNotNull('wallet_transaction_id')->sum('discount_amount'));
        return ['percent' => $percent, 'cap' => $cap, 'used' => $reward + $coupon,
            'remaining' => max(0, $cap - $reward - $coupon),
            'reward_used' => $reward, 'coupon_used' => $coupon];
    }
}
