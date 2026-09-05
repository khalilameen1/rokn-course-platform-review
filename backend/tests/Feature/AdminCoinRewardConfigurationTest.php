<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CoinEarningMethodController;
use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

final class AdminCoinRewardConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_reward_cannot_save_a_positive_cap_smaller_than_one_payout(): void
    {
        $rule = $this->rule('course_completed', 200, 400);

        try {
            $this->controller()->updateRewardRule(
                $this->ruleRequest($rule, [
                    'coins_amount' => 200,
                    'rolling_30_day_cap' => 100,
                ]),
                $rule
            );
            self::fail('An active reward must not save a cap that can never fund one payout.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('rolling_30_day_cap', $exception->errors());
        }

        self::assertSame(400, $rule->fresh()->rolling_30_day_cap);
    }

    public function test_inactive_draft_and_zero_cap_remain_explicit_disable_paths(): void
    {
        $rule = $this->rule('course_completed', 200, 400);

        $this->controller()->updateRewardRule(
            $this->ruleRequest($rule, [
                'is_active' => '0',
                'rolling_30_day_cap' => '',
            ]),
            $rule
        );
        self::assertFalse($rule->fresh()->is_active);
        self::assertNull($rule->fresh()->rolling_30_day_cap);

        $rule->forceFill(['is_active' => true])->save();
        $this->controller()->updateRewardRule(
            $this->ruleRequest($rule->fresh(), [
                'is_active' => '1',
                'rolling_30_day_cap' => 0,
            ]),
            $rule->fresh()
        );
        self::assertTrue($rule->fresh()->is_active);
        self::assertSame(0, $rule->fresh()->rolling_30_day_cap);
    }

    public function test_event_irrelevant_values_are_normalized_instead_of_becoming_dead_configuration(): void
    {
        $rule = $this->rule('daily_checkin', 20, 200);

        $request = $this->ruleRequest($rule, [
            'interval_count' => null,
            'daily_cap' => 999,
        ]);
        $request->request->remove('interval_count');

        $this->controller()->updateRewardRule($request, $rule);

        $rule->refresh();
        self::assertSame(1, $rule->interval_count);
        self::assertNull($rule->daily_cap);
    }

    public function test_study_positive_daily_cap_must_fund_one_reward(): void
    {
        $rule = $this->rule('study_session', 20, 200);
        $rule->forceFill(['daily_cap' => 40, 'interval_count' => 5])->save();

        try {
            $this->controller()->updateRewardRule(
                $this->ruleRequest($rule->fresh(), [
                    'interval_count' => 5,
                    'daily_cap' => 10,
                ]),
                $rule->fresh()
            );
            self::fail('A study cap below one indivisible reward must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('daily_cap', $exception->errors());
        }
    }

    public function test_streak_interval_cannot_save_the_runtime_impossible_one_day_value(): void
    {
        $rule = $this->rule('streak_milestone', 20, 200);
        $rule->forceFill(['interval_count' => 7])->save();

        try {
            $this->controller()->updateRewardRule(
                $this->ruleRequest($rule->fresh(), ['interval_count' => 1]),
                $rule->fresh()
            );
            self::fail('The dashboard must match the runtime two-day minimum.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('interval_count', $exception->errors());
        }

        self::assertSame(7, $rule->fresh()->interval_count);
    }

    public function test_active_coin_task_must_fit_the_positive_reward_balance_cap(): void
    {
        $setting = Setting::query()->firstOrCreate([]);
        $setting->forceFill(['reward_balance_cap' => 100])->save();
        $method = CoinEarningMethod::query()->create([
            'title_ar' => 'مهمة داخلية',
            'title_en' => 'Internal task',
            'coins_amount' => 50,
            'action_key' => 'internal_task_contract',
            'requires_external_visit' => false,
            'verification_delay_seconds' => 0,
            'sort_order' => 10,
            'is_active' => true,
            'is_repeatable' => false,
        ]);

        try {
            $this->controller()->update(
                $this->methodRequest($method, ['coins_amount' => 150]),
                $method
            );
            self::fail('An active task must not promise more than its wallet can credit.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('coins_amount', $exception->errors());
        }
        self::assertSame(50, $method->fresh()->coins_amount);

        $this->controller()->update(
            $this->methodRequest($method->fresh(), [
                'coins_amount' => 150,
                'is_active' => '0',
            ]),
            $method->fresh()
        );
        self::assertFalse($method->fresh()->is_active);
        self::assertSame(150, $method->fresh()->coins_amount);
    }

    public function test_settings_reject_oversized_text_and_an_unpayable_active_welcome_offer(): void
    {
        $setting = Setting::query()->firstOrCreate([]);
        $setting->forceFill([
            'reward_balance_cap' => 1200,
            'max_reward_contribution_per_course' => 1200,
            'recommended_social_provider' => 'google',
            'recommended_provider_bonus_coins' => 0,
        ])->save();
        $welcome = $this->rule('welcome_bonus', 20, null);

        try {
            $this->controller()->updateSettings($this->settingsRequest($setting, [
                'how_to_use_coins_ar' => str_repeat('أ', 12001),
            ]));
            self::fail('The dashboard must reject copy that cannot fit its TEXT column.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('how_to_use_coins_ar', $exception->errors());
        }

        try {
            $this->controller()->updateSettings($this->settingsRequest($setting->fresh(), [
                'reward_balance_cap' => 25,
                'recommended_provider_bonus_coins' => 9,
            ]));
            self::fail('The recommended welcome offer must fit the configured reward balance cap.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('recommended_provider_bonus_coins', $exception->errors());
        }

        self::assertSame(1200, $setting->fresh()->reward_balance_cap);
        self::assertSame(20, $welcome->fresh()->coins_amount);
    }

    public function test_settings_cannot_lower_a_positive_cap_below_an_active_task(): void
    {
        RewardRule::query()->update(['is_active' => false]);
        CoinEarningMethod::query()->where('action_key', 'register')->update(['is_active' => false]);
        $setting = Setting::query()->firstOrCreate([]);
        $setting->forceFill([
            'reward_balance_cap' => 1200,
            'max_reward_contribution_per_course' => 1200,
            'recommended_social_provider' => 'google',
            'recommended_provider_bonus_coins' => 0,
        ])->save();
        CoinEarningMethod::query()->create([
            'title_ar' => 'تابع ركن',
            'title_en' => 'Follow Rokn',
            'coins_amount' => 50,
            'action_key' => 'settings_cap_task',
            'requires_external_visit' => false,
            'verification_delay_seconds' => 0,
            'sort_order' => 10,
            'is_active' => true,
            'is_repeatable' => false,
        ]);

        try {
            $this->controller()->updateSettings($this->settingsRequest($setting, [
                'reward_balance_cap' => 40,
            ]));
            self::fail('A settings save must not strand an active task above the new cap.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reward_balance_cap', $exception->errors());
            self::assertStringContainsString('تابع ركن', $exception->errors()['reward_balance_cap'][0]);
        }

        self::assertSame(1200, $setting->fresh()->reward_balance_cap);
    }

    private function controller(): CoinEarningMethodController
    {
        return app(CoinEarningMethodController::class);
    }

    private function rule(string $event, int $amount, ?int $rollingCap): RewardRule
    {
        return RewardRule::query()->updateOrCreate(['event_key' => $event], [
            'title_ar' => $event,
            'title_en' => null,
            'coins_amount' => $amount,
            'interval_count' => 1,
            'daily_cap' => null,
            'rolling_30_day_cap' => $rollingCap,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function ruleRequest(RewardRule $rule, array $overrides = []): Request
    {
        return Request::create('/dashboard/reward-rules/'.$rule->id, 'PUT', array_replace([
            'event_key' => $rule->event_key,
            'title_ar' => $rule->title_ar,
            'title_en' => $rule->title_en,
            'coins_amount' => $rule->coins_amount,
            'interval_count' => $rule->interval_count,
            'daily_cap' => $rule->daily_cap,
            'rolling_30_day_cap' => $rule->rolling_30_day_cap,
            'sort_order' => $rule->sort_order,
            'is_active' => $rule->is_active ? '1' : '0',
            'editor_version' => $this->version('rewardRuleEditorVersion', $rule),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function settingsRequest(Setting $setting, array $overrides = []): Request
    {
        return Request::create('/dashboard/coin-earning-methods-settings', 'POST', array_replace([
            'how_to_use_coins_ar' => $setting->how_to_use_coins_ar,
            'how_to_use_coins_en' => $setting->how_to_use_coins_en,
            'reward_balance_cap' => $setting->reward_balance_cap ?? 1200,
            'max_reward_contribution_per_course' => $setting->max_reward_contribution_per_course ?? 1200,
            'recommended_social_provider' => $setting->recommended_social_provider ?: 'google',
            'recommended_provider_bonus_coins' => $setting->recommended_provider_bonus_coins ?? 0,
            'recommended_provider_badge_ar' => $setting->recommended_provider_badge_ar,
            'recommended_provider_badge_en' => $setting->recommended_provider_badge_en,
            'editor_version' => $this->version('settingsEditorVersion', $setting),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function methodRequest(CoinEarningMethod $method, array $overrides = []): Request
    {
        return Request::create('/dashboard/coin-earning-methods/'.$method->id, 'PUT', array_replace([
            'title_ar' => $method->title_ar,
            'title_en' => $method->title_en,
            'coins_amount' => $method->coins_amount,
            'action_key' => $method->action_key,
            'campaign_key' => $method->campaign_key,
            'action_url' => $method->action_url,
            'requires_external_visit' => $method->requires_external_visit ? '1' : '0',
            'verification_delay_seconds' => $method->verification_delay_seconds,
            'starts_at' => null,
            'ends_at' => null,
            'total_claim_limit' => $method->total_claim_limit,
            'sort_order' => $method->sort_order,
            'is_active' => $method->is_active ? '1' : '0',
            'editor_version' => $this->version('methodEditorVersion', $method),
        ], $overrides));
    }

    private function version(string $methodName, object $model): string
    {
        $method = new ReflectionMethod($this->controller(), $methodName);
        $method->setAccessible(true);

        return (string) $method->invoke($this->controller(), $model);
    }
}
