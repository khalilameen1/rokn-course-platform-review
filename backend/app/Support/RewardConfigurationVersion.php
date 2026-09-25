<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Models\Setting;

/** Stable editor fingerprints shared by rendering and locked writes. No database reads. */
final class RewardConfigurationVersion
{
    public static function settings(?Setting $setting): string
    {
        return hash('sha256', json_encode([
            (string) ($setting?->how_to_use_coins_ar ?? ''),
            (string) ($setting?->how_to_use_coins_en ?? ''),
            (string) ($setting?->rewards_help_ar ?? ''),
            (string) ($setting?->rewards_help_en ?? ''),
            (int) ($setting?->reward_balance_cap ?? 1200),
            (int) ($setting?->max_reward_contribution_per_course ?? 1200),
            (int) ($setting?->max_course_promotion_percent ?? config('course_plans.max_promotion_percent', 20)),
            (string) ($setting?->recommended_social_provider ?? config('social_auth.recommended_provider')),
            (int) ($setting?->recommended_provider_bonus_coins ?? 0),
            (string) ($setting?->recommended_provider_badge_ar ?? ''),
            (string) ($setting?->recommended_provider_badge_en ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function method(CoinEarningMethod $method): string
    {
        return hash('sha256', json_encode([
            (string) $method->title_ar,
            (string) $method->title_en,
            (int) $method->coins_amount,
            (string) $method->action_key,
            (string) $method->campaign_key,
            (string) $method->action_url,
            (bool) $method->requires_external_visit,
            (int) $method->verification_delay_seconds,
            $method->starts_at?->toIso8601String(),
            $method->ends_at?->toIso8601String(),
            $method->total_claim_limit === null ? null : (int) $method->total_claim_limit,
            (int) $method->sort_order,
            (bool) $method->is_active,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function rule(RewardRule $rule): string
    {
        return hash('sha256', json_encode([
            (string) $rule->event_key,
            (string) $rule->title_ar,
            (string) $rule->title_en,
            (int) $rule->coins_amount,
            (int) $rule->interval_count,
            $rule->daily_cap === null ? null : (int) $rule->daily_cap,
            $rule->rolling_30_day_cap === null ? null : (int) $rule->rolling_30_day_cap,
            (bool) $rule->is_active,
            (int) $rule->sort_order,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
