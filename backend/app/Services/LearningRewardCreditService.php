<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RewardGrantDeferred;
use App\Models\RewardRule;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** All-or-nothing settlement for an earned learning reward, under the learner lock. */
final class LearningRewardCreditService
{
    private const TEMPORARY_BALANCE_CAP_RETRY_HOURS = 12;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly LearningRewardConfigurationService $configuration
    ) {
    }

    public function credit(
        User $user,
        int $requested,
        string $category,
        string $idempotencyKey,
        int $rollingCap,
        ?RewardRule $source = null,
        array $metadata = [],
        bool $deferOnCap = false
    ): ?WalletTransaction {
        $requested = max(0, $requested);
        if ($requested === 0) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $requested,
            $category,
            $idempotencyKey,
            $rollingCap,
            $source,
            $metadata,
            $deferOnCap
        ): ?WalletTransaction {
            // All reward sources share the same user aggregate lock. This keeps
            // two different simultaneous rewards from each seeing stale room
            // under the balance or rolling cap.
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if (WalletTransaction::query()
                ->where('user_id', $lockedUser->id)
                ->where('idempotency_key', $idempotencyKey)
                ->exists()) {
                return null;
            }

            $configuredBalanceCap = $this->configuration->balanceCap();
            $rollingTotal = (int) WalletTransaction::query()
                ->where('user_id', $lockedUser->id)
                ->where('category', $category)
                ->where('direction', WalletTransaction::DIRECTION_CREDIT)
                ->where('occurred_at', '>=', BusinessClock::now()->subDays(30))
                ->sum('amount');
            $rollingRoom = max(0, $rollingCap - $rollingTotal);
            $balances = $this->wallet->balances($lockedUser);
            $balanceRoom = max(
                0,
                $configuredBalanceCap - $balances['reward']
            );
            // A displayed reward is one commercial promise. Crediting a
            // smaller remainder would consume its one-time idempotency key
            // while silently paying fewer coins than the configured amount.
            if ($requested > $rollingRoom || $requested > $balanceRoom) {
                $balanceCap = max(0, $configuredBalanceCap);
                $canEverFit = $requested <= $rollingCap && $requested <= $balanceCap;
                if ($deferOnCap && $canEverFit) {
                    $retryAt = BusinessClock::now()
                        ->addHours(self::TEMPORARY_BALANCE_CAP_RETRY_HOURS)
                        ->utc();
                    if ($requested > $rollingRoom) {
                        $rollingRetryAt = $this->rollingCapRetryAt(
                            (int) $lockedUser->id,
                            $category,
                            $requested,
                            $rollingCap,
                            $rollingTotal
                        );
                        if ($rollingRetryAt && $rollingRetryAt->greaterThan($retryAt)) {
                            $retryAt = $rollingRetryAt;
                        }
                    }

                    throw new RewardGrantDeferred($retryAt);
                }

                return null;
            }

            return $this->wallet->credit(
                $lockedUser->id,
                $requested,
                $category,
                $idempotencyKey,
                $source,
                $metadata + [
                    'requested_amount' => $requested,
                    'reward_balance_cap' => $configuredBalanceCap,
                    'rolling_30_day_cap' => $rollingCap,
                    'reward_timezone' => BusinessClock::timezoneName(),
                ],
                WalletTransaction::BUCKET_REWARD
            );
        }, 3);
    }

    private function rollingCapRetryAt(
        int $userId,
        string $category,
        int $requested,
        int $rollingCap,
        int $rollingTotal
    ): ?CarbonImmutable {
        $amountThatMustExpire = max(0, $rollingTotal + $requested - $rollingCap);
        if ($amountThatMustExpire === 0) {
            return null;
        }

        $expiringAmount = 0;
        $credits = WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('occurred_at', '>=', BusinessClock::now()->subDays(30))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['amount', 'occurred_at']);
        foreach ($credits as $credit) {
            $expiringAmount += max(0, (int) $credit->amount);
            if ($expiringAmount >= $amountThatMustExpire) {
                return CarbonImmutable::parse($credit->occurred_at)
                    ->setTimezone(BusinessClock::timezoneName())
                    ->addDays(30)
                    ->addSecond()
                    ->utc();
            }
        }

        return null;
    }
}
