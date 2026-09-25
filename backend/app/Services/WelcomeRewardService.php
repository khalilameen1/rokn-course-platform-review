<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

final class WelcomeRewardService
{
    public function __construct(
        private readonly WelcomeRewardOfferService $offer,
        private readonly AcquisitionRewardTombstoneService $tombstones,
        private readonly WalletService $wallet,
        private readonly StudentNotificationService $notifications
    ) {
    }

    /**
     * Grant the one-time welcome reward, audit and inbox receipt atomically.
     *
     * @param User $user
     * @return int Number of coins credited during this call.
     */
    public function grant(User $user, ?string $verifiedProvider = null): int
    {
        try {
            if ($this->tombstones->userHasConsumed(
                $user,
                AcquisitionRewardTombstoneService::WELCOME_REWARD
            )) {
                return 0;
            }

            $method = CoinEarningMethod::active()->where('action_key', 'register')->first();

            // Keep the granted amount identical to the login promise. The
            // earning-method row remains the claim/audit record, not a second
            // source of truth for this acquisition offer.
            $coinsAmount = $this->offer->amountForProvider(
                $verifiedProvider ?: (string) $user->social_provider
            );
            $methodId = $method ? $method->id : null;

            if ($coinsAmount <= 0) {
                return 0;
            }

            $idempotencyKey = 'registration-bonus:' . $user->id;

            return DB::transaction(function () use (
                $user,
                $method,
                $methodId,
                $coinsAmount,
                $idempotencyKey
            ): int {
                // Serialize first-login retries. Wallet credit, audit row and
                // inbox notification either complete together or can be retried.
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

                $existingCredit = WalletTransaction::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                $alreadyCredited = $existingCredit !== null;
                $alreadyClaimed = $methodId
                    ? $lockedUser->coinEarnings()
                        ->where('coin_earning_method_id', $methodId)
                        ->exists()
                    : false;

                // Preserve bonuses issued by the legacy system even when they
                // predate the wallet ledger. Never add a second welcome credit.
                if ($alreadyClaimed && !$alreadyCredited) {
                    return 0;
                }

                if (!$alreadyCredited) {
                    $credit = $this->wallet->creditRewardWithinConfiguredCap(
                        $lockedUser->id,
                        $coinsAmount,
                        'welcome_bonus',
                        $idempotencyKey,
                        $method,
                        ['action_key' => 'register']
                    );
                    if (!$credit) {
                        return 0;
                    }
                    $coinsAmount = (int) $credit->amount;
                } else {
                    // The immutable ledger fact wins over today's dashboard
                    // value when a post-credit side effect is being replayed.
                    $coinsAmount = (int) $existingCredit->amount;
                }

                if ($methodId) {
                    $lockedUser->coinEarnings()->firstOrCreate(
                        ['coin_earning_method_id' => $methodId],
                        ['amount' => $coinsAmount]
                    );
                }

                $this->notifications->welcomeRewardReceipt(
                    $lockedUser,
                    $coinsAmount,
                    $methodId
                );

                return $alreadyCredited ? 0 : $coinsAmount;
            }, 3);
        } catch (\Throwable $e) {
            if (app()->environment('testing')) {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::error('Failed to grant registration bonus', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);
            return 0;
        }
    }
}
