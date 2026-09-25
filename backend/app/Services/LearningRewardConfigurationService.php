<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RewardRule;
use App\Models\Setting;
use App\Support\BusinessClock;

/** Read-only dashboard configuration; never creates settings or credits a learner. */
final class LearningRewardConfigurationService
{
    private const DEFAULT_REWARD_BALANCE_CAP = 1200;
    private const DEFAULT_REWARD_CONTRIBUTION_PER_COURSE = 1200;

    public function configuration(): array
    {
        $settings = $this->settings();
        $welcome = RewardRule::activeFor('welcome_bonus');
        $daily = RewardRule::activeFor('daily_checkin');
        $streak = RewardRule::activeFor('streak_milestone');
        $study = RewardRule::activeFor('study_session');
        $firstProject = RewardRule::activeFor('first_project_passed');
        $courseCompletion = RewardRule::activeFor('course_completed');

        return [
            'reward_timezone' => BusinessClock::timezoneName(),
            'welcome_bonus_coins' => (int) ($welcome?->coins_amount ?? 0),
            'reward_balance_cap' => (int) $settings->reward_balance_cap,
            'max_reward_contribution_per_course' => (int) $settings->max_reward_contribution_per_course,
            'max_course_promotion_percent' => min(20, max(0, (int) ($settings->max_course_promotion_percent ?? config('course_plans.max_promotion_percent', 20)))),
            'daily' => [
                'enabled' => $daily !== null,
                'coins' => (int) ($daily?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($daily?->rolling_30_day_cap ?? 0),
            ],
            'streak' => [
                'enabled' => $streak !== null,
                'days' => (int) ($streak?->interval_count ?? 0),
                'coins' => (int) ($streak?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($streak?->rolling_30_day_cap ?? 0),
            ],
            'study' => [
                'enabled' => $study !== null,
                'coins' => (int) ($study?->coins_amount ?? 0),
                'qualified_minutes' => (int) ($study?->interval_count ?? 0),
                'daily_cap' => (int) ($study?->daily_cap ?? 0),
                'rolling_30_day_cap' => (int) ($study?->rolling_30_day_cap ?? 0),
            ],
            'first_project' => [
                'enabled' => $firstProject !== null,
                'coins' => (int) ($firstProject?->coins_amount ?? 0),
                'lifetime_cap' => (int) ($firstProject?->rolling_30_day_cap ?? 0),
            ],
            'course_completion' => [
                'enabled' => $courseCompletion !== null,
                'coins' => (int) ($courseCompletion?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($courseCompletion?->rolling_30_day_cap ?? 0),
            ],
        ];
    }

    public function balanceCap(): int
    {
        return (int) $this->settings()->reward_balance_cap;
    }

    private function settings(): Setting
    {
        $settings = Setting::query()->first();
        if ($settings) {
            return $settings;
        }

        // Public configuration and reward reads must never create the global
        // settings row. Apart from making GET mutate state, firstOrCreate([])
        // could race on a fresh installation because the table has no natural
        // singleton key. The dashboard remains the sole explicit creator; the
        // service uses the same migration defaults until that first save.
        return (new Setting())->forceFill([
            'reward_balance_cap' => self::DEFAULT_REWARD_BALANCE_CAP,
            'max_reward_contribution_per_course' => self::DEFAULT_REWARD_CONTRIBUTION_PER_COURSE,
        ]);
    }
}
