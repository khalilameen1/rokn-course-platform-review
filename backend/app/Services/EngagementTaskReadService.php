<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserCoinTaskAttempt;

/** One candidate selector for generic offers and course purchase-exit offers. */
final readonly class EngagementTaskReadService
{
    public function __construct(private AcquisitionRewardTombstoneService $tombstones) {}

    public function next(User $user, int $creditRoom): ?CoinEarningMethod
    {
        if ($creditRoom <= 0) return null;
        $methods = CoinEarningMethod::query()->learnerTask()
            ->where('coins_amount', '<=', $creditRoom)
            ->withCount('userEarnings')->orderBy('sort_order')->orderBy('id')->get();
        $methodIds = $methods->pluck('id');
        $earned = $user->coinEarnings()->whereIn('coin_earning_method_id', $methodIds)
            ->pluck('coin_earning_method_id')->map(static fn ($id): int => (int) $id)->flip();
        $claimed = UserCoinTaskAttempt::query()->where('user_id', $user->id)
            ->whereIn('coin_earning_method_id', $methodIds)
            ->where('status', UserCoinTaskAttempt::STATUS_CLAIMED)
            ->pluck('coin_earning_method_id')->map(static fn ($id): int => (int) $id)->flip();
        $consumed = $this->tombstones->consumedRewardKeys($user);

        return $methods->first(function (CoinEarningMethod $method) use ($earned, $claimed, $consumed): bool {
            $key = $this->tombstones->rewardKeyForMethod($method);
            return $method->hasUsableDestination()
                && ($method->total_claim_limit === null || (int) $method->user_earnings_count < (int) $method->total_claim_limit)
                && ($key === null || !in_array($key, $consumed, true))
                && !$earned->has((int) $method->id) && !$claimed->has((int) $method->id);
        });
    }
}
