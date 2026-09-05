<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\RewardRule;
use App\Services\LearningRewardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class LearningRewardEndpointTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekly_checkin_reward_is_dashboard_driven_and_idempotent(): void
    {
        DB::table('settings')->update(['reward_balance_cap' => 1000]);
        DB::table('reward_rules')->where('event_key', 'daily_checkin')->update([
            'coins_amount' => 1,
            'rolling_30_day_cap' => 30,
        ]);
        DB::table('reward_rules')->where('event_key', 'streak_milestone')->update([
            'interval_count' => 7,
            'coins_amount' => 25,
            'rolling_30_day_cap' => 100,
        ]);
        DB::table('wallet_transactions')->where('user_id', $this->user->id)->delete();
        $this->user->forceFill([
            'wallet_coins' => 0,
            'wallet_reward_coins' => 0,
            'wallet_purchased_coins' => 0,
        ])->save();
        $token = $this->user->generateApiToken();
        $start = Carbon::parse('2026-08-01 12:00:00');

        for ($day = 0; $day < 7; $day++) {
            Carbon::setTestNow($start->copy()->addDays($day));
            $response = $this->withToken($token)->postJson('/api/v1/rewards/daily')->assertOk();
            if ($day === 6) {
                $response
                    ->assertJsonPath('data.current_streak_days', 7)
                    ->assertJsonPath('data.streak_awarded', 25)
                    ->assertJsonPath('data.reward_balance', 32);
            }
        }

        $this->withToken($token)->postJson('/api/v1/rewards/daily')
            ->assertOk()
            ->assertJsonPath('data.streak_awarded', 0)
            ->assertJsonPath('data.reward_balance', 32);

        $this->assertSame(7, DB::table('user_reward_checkins')->count());
        $this->assertSame(7, DB::table('wallet_transactions')->where('category', 'daily_learning_reward')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('category', 'streak_reward')->count());
    }

    public function test_study_day_finishes_its_frozen_contract_after_the_rule_is_disabled(): void
    {
        DB::table('settings')->update(['reward_balance_cap' => 1000]);
        $rule = RewardRule::query()->where('event_key', 'study_session')->firstOrFail();
        $rule->forceFill([
            'coins_amount' => 10,
            'interval_count' => 1,
            'daily_cap' => 20,
            'rolling_30_day_cap' => 200,
            'is_active' => true,
        ])->save();
        DB::table('wallet_transactions')->where('user_id', $this->user->id)->delete();
        $this->user->forceFill([
            'wallet_coins' => 0,
            'wallet_reward_coins' => 0,
            'wallet_purchased_coins' => 0,
        ])->save();

        $rewards = app(LearningRewardService::class);
        $firstHalf = $rewards->recordStudy($this->user, 30);
        self::assertSame(30, $firstHalf['qualified_seconds_today']);
        self::assertSame(0, $firstHalf['awarded']);

        $rule->forceFill(['is_active' => false])->save();

        $secondHalf = $rewards->recordStudy($this->user, 30);
        self::assertSame(60, $secondHalf['qualified_seconds_today']);
        self::assertSame(1, $secondHalf['rewarded_slots_today']);
        self::assertSame(10, $secondHalf['awarded']);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'category' => 'study_reward',
            'amount' => 10,
        ]);

        Carbon::setTestNow(now()->addDay());
        $nextDay = $rewards->recordStudy($this->user, 60);
        self::assertSame(0, $nextDay['awarded']);
        $this->assertDatabaseMissing('user_daily_learning_activities', [
            'user_id' => $this->user->id,
            'activity_date' => now()->toDateString(),
        ]);
    }
}
