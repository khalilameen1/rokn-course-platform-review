<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RewardRule;
use App\Models\Setting;

/** Read-only acquisition offer shared by discovery and the grant action. */
final class WelcomeRewardOfferService
{
    /** The discovery promise and the credited one-time amount share this rule. */
    public function amountForProvider(?string $provider = null): int
    {
        $settings = Setting::query()->first();
        $amount = RewardRule::configuredAmount(
            'welcome_bonus',
            (int) ($settings?->welcome_bonus_coins
                ?? config('social_auth.welcome_bonus_coins', 20))
        );
        if (
            $settings?->recommended_social_provider
            && strtolower(trim((string) $provider)) === strtolower(trim((string) $settings->recommended_social_provider))
        ) {
            $amount += max(0, (int) $settings->recommended_provider_bonus_coins);
        }

        // WalletService treats acquisition offers as indivisible. Advertising
        // an amount above the reward-wallet ceiling would promise coins that
        // the canonical ledger must reject in full.
        $cap = max(0, (int) ($settings?->reward_balance_cap ?? 1200));
        return $amount > 0 && $amount <= $cap ? $amount : 0;
    }
}
