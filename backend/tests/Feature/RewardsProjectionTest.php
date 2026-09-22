<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserCoinEarning;
use App\Services\WalletQueryService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RewardsProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reward_history_excludes_paid_topups_and_uses_only_the_reward_part_of_a_debit(): void
    {
        $user = $this->student('wallet@example.test');
        $wallet = app(WalletService::class);
        $wallet->credit($user->id, 50, 'welcome_bonus', 'welcome-test');
        $wallet->credit($user->id, 200, 'package_purchase', 'paid-test', bucket: 'paid');
        $wallet->debit($user->id, 100, 'course_purchase', 'course-test', maxRewardAmount: 20);
        for ($index = 0; $index < 12; $index++) {
            $wallet->credit($user->id, 1, 'package_purchase', 'paid-'.$index, bucket: 'paid');
        }
        $snapshot = app(WalletQueryService::class)->summary($user);
        self::assertSame(30, $snapshot['rewards']['balance']);
        self::assertSame(162, $snapshot['total_balance']);
        self::assertCount(10, $snapshot['recent_transactions']);
        $rewards = $snapshot['rewards']['recent_transactions']->all();
        self::assertCount(2, $rewards);
        self::assertSame(20, $rewards[0]['amount']);
        self::assertArrayNotHasKey('paid_coins', $rewards[0]);
        self::assertSame('debit', $rewards[0]['direction']);
        self::assertSame('welcome_bonus', $rewards[1]['category']);
        self::assertSame(50, $rewards[1]['amount']);
    }

    public function test_completed_tasks_remain_visible_after_the_campaign_ends_without_reopening_claims(): void
    {
        $user = $this->student('tasks@example.test');
        $method = CoinEarningMethod::query()->create([
            'title_ar' => 'تابع سلسلة التصميم', 'title_en' => 'Follow the design series',
            'coins_amount' => 30, 'action_key' => 'design_campaign', 'requires_external_visit' => false,
            'verification_delay_seconds' => 0, 'is_active' => false, 'sort_order' => 1,
            'ends_at' => now()->subDay(), 'total_claim_limit' => 1,
        ]);
        UserCoinEarning::query()->create(['user_id' => $user->id, 'coin_earning_method_id' => $method->id, 'amount' => 30]);
        $this->actingAs($user, 'api')->getJson('/api/v1/coin-earning-methods')->assertOk()
            ->assertJsonFragment(['id' => $method->id, 'task_state' => 'claimed']);
        self::assertFalse($method->isLearnerTask());
        self::assertFalse($method->hasClaimCapacity());
    }

    public function test_registration_and_welcome_are_not_manual_tasks(): void
    {
        foreach (CoinEarningMethod::AUTOMATIC_ACTION_KEYS as $action) {
            $method = CoinEarningMethod::query()->firstOrCreate(['action_key' => $action], [
                'title_ar' => 'ترحيب', 'title_en' => 'Welcome', 'coins_amount' => 50,
                'is_active' => true, 'requires_external_visit' => false,
            ]);
            self::assertFalse($method->isLearnerTask());
            self::assertFalse(CoinEarningMethod::learnerTask()->whereKey($method->id)->exists());
        }
    }

    private function student(string $email): User
    {
        return User::query()->forceCreate([
            'name' => 'Rewards Student', 'email' => $email, 'role' => 'client', 'active' => true,
            'wallet_coins' => 0, 'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
        ]);
    }
}
