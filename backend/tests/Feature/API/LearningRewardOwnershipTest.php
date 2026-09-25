<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Exceptions\RewardGrantDeferred;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\LearningRewardConfigurationService;
use App\Services\LearningRewardCreditService;
use App\Services\LearningRewardService;
use App\Services\WalletService;
use App\Support\BusinessClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

final class LearningRewardOwnershipTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-09-05 09:00:00');
        DB::table('wallet_transactions')->where('user_id', $this->user->id)->delete();
        $this->user->forceFill([
            'wallet_coins' => 0, 'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
        ])->save();
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 100]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_configuration_is_read_only_even_without_a_dashboard_settings_row(): void
    {
        Setting::query()->delete();
        $rules = RewardRule::query()->count();
        $configuration = app(LearningRewardConfigurationService::class);
        $first = $configuration->configuration();
        self::assertSame($first, $configuration->configuration());
        self::assertSame(1200, $first['reward_balance_cap']);
        self::assertSame(1200, $configuration->balanceCap());
        self::assertSame(BusinessClock::timezoneName(), $first['reward_timezone']);
        self::assertSame(0, Setting::query()->count());
        self::assertSame($rules, RewardRule::query()->count());
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, DB::table('user_reward_checkins')->count());
        self::assertSame(0, DB::table('user_daily_learning_activities')->count());
    }

    public function test_configuration_and_credit_owner_observe_current_dashboard_cap(): void
    {
        $configuration = app(LearningRewardConfigurationService::class);
        $credits = app(LearningRewardCreditService::class);
        self::assertSame(100, $configuration->balanceCap());
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 8]);
        self::assertSame(8, $configuration->configuration()['reward_balance_cap']);
        self::assertNull($credits->credit($this->user, 10, 'study_reward', 'live-cap', 100));
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 20]);
        self::assertSame(10, $credits->credit($this->user, 10, 'study_reward', 'live-cap', 100)->amount);
    }

    public function test_configuration_endpoint_reads_the_new_owner_without_recording_activity(): void
    {
        $rule = RewardRule::query()->where('event_key', 'study_session')->firstOrFail();
        $rule->update(['coins_amount' => 13, 'interval_count' => 2, 'is_active' => true]);
        $this->actingAs($this->user, 'api')->getJson('/api/v1/economy-config')
            ->assertOk()->assertJsonPath('data.study.coins', 13)
            ->assertJsonPath('data.study.qualified_minutes', 2);
        self::assertSame(0, DB::table('user_daily_learning_activities')->count());
        self::assertSame(0, WalletTransaction::query()->count());
    }

    public function test_credit_retains_the_frozen_amount_source_and_reward_bucket_on_replay(): void
    {
        $source = RewardRule::query()->where('event_key', 'course_completed')->firstOrFail();
        $source->update(['coins_amount' => 99]);
        $credits = app(LearningRewardCreditService::class);
        $receipt = $credits->credit(
            user: $this->user, requested: 10, category: 'course_completion_reward',
            idempotencyKey: 'frozen-completion', rollingCap: 40, source: $source,
            metadata: ['course_id' => $this->courseId]
        );
        self::assertSame(10, $receipt->amount);
        self::assertSame(WalletTransaction::BUCKET_REWARD, $receipt->bucket);
        self::assertSame(RewardRule::class, $receipt->source_type);
        self::assertSame((int) $source->id, (int) $receipt->source_id);
        self::assertSame(100, $receipt->metadata['reward_balance_cap']);
        self::assertSame(40, $receipt->metadata['rolling_30_day_cap']);
        self::assertSame(10, $receipt->metadata['requested_amount']);
        self::assertNull($credits->credit($this->user, 10, 'course_completion_reward', 'frozen-completion', 40, $source));
        self::assertSame(1, WalletTransaction::query()->count());
        self::assertSame(10, (int) $this->user->fresh()->wallet_reward_coins);
        self::assertSame(0, (int) $this->user->fresh()->wallet_purchased_coins);
    }

    public function test_insufficient_room_does_not_pay_partially_or_consume_the_reward_identity(): void
    {
        $wallet = app(WalletService::class);
        $wallet->credit($this->user->id, 95, 'anchor', 'anchor');
        $credits = app(LearningRewardCreditService::class);
        self::assertNull($credits->credit($this->user, 10, 'study_reward', 'whole-reward', 100));
        self::assertSame(95, (int) $this->user->fresh()->wallet_reward_coins);
        self::assertFalse(WalletTransaction::query()->where('idempotency_key', 'whole-reward')->exists());
        $wallet->debit($this->user->id, 20, 'spend', 'spend');
        self::assertSame(10, $credits->credit($this->user, 10, 'study_reward', 'whole-reward', 100)->amount);
        self::assertSame(85, (int) $this->user->fresh()->wallet_reward_coins);
    }

    public function test_temporary_achievement_cap_defers_without_creating_a_receipt(): void
    {
        app(WalletService::class)->credit($this->user->id, 95, 'anchor', 'anchor');
        try {
            app(LearningRewardCreditService::class)->credit(
                user: $this->user, requested: 10, category: 'course_completion_reward',
                idempotencyKey: 'deferred-completion', rollingCap: 100, deferOnCap: true
            );
            self::fail('A temporarily blocked achievement must be retried, not marked paid.');
        } catch (RewardGrantDeferred $exception) {
            self::assertSame(BusinessClock::now()->addHours(12)->timestamp, $exception->retryAt->timestamp);
            self::assertFalse(WalletTransaction::query()->where('idempotency_key', 'deferred-completion')->exists());
        }
    }

    public function test_impossible_or_empty_award_does_not_schedule_an_endless_retry(): void
    {
        $credits = app(LearningRewardCreditService::class);
        foreach ([0, -1, 101] as $requested) {
            self::assertNull($credits->credit(
                user: $this->user, requested: $requested, category: 'course_completion_reward',
                idempotencyKey: 'impossible-' . $requested, rollingCap: 200, deferOnCap: true
            ));
        }
        self::assertNull($credits->credit(
            user: $this->user, requested: 10, category: 'course_completion_reward',
            idempotencyKey: 'impossible-rolling', rollingCap: 5, deferOnCap: true
        ));
        self::assertSame(0, WalletTransaction::query()->count());
    }

    public function test_wallet_storage_failure_rolls_back_balance_and_allows_a_clean_retry(): void
    {
        $fail = true;
        Event::listen('eloquent.creating: ' . WalletTransaction::class, static function () use (&$fail): void {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('ledger unavailable');
            }
        });
        $credits = app(LearningRewardCreditService::class);
        try {
            $credits->credit($this->user, 10, 'study_reward', 'ledger-retry', 100);
            self::fail('A reward cannot be paid without its ledger record.');
        } catch (\RuntimeException $exception) {
            self::assertSame('ledger unavailable', $exception->getMessage());
        }
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, (int) $this->user->fresh()->wallet_reward_coins);
        self::assertSame(10, $credits->credit($this->user, 10, 'study_reward', 'ledger-retry', 100)->amount);
        self::assertSame(1, WalletTransaction::query()->count());
    }

    public function test_outer_transaction_rollback_removes_credit_and_balance_change(): void
    {
        DB::beginTransaction();
        try {
            $receipt = app(LearningRewardCreditService::class)->credit($this->user, 10, 'study_reward', 'outer', 100);
            self::assertSame(10, $receipt->amount);
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, (int) $this->user->fresh()->wallet_reward_coins);
    }

    public function test_checkin_admission_keeps_its_contract_while_settlement_waits_for_balance_room(): void
    {
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 0]);
        $rule = RewardRule::query()->where('event_key', 'daily_checkin')->firstOrFail();
        $rule->update(['coins_amount' => 5, 'rolling_30_day_cap' => 100, 'is_active' => true]);
        $activities = app(LearningRewardService::class);
        self::assertSame(0, $activities->claimDaily($this->user)['awarded']);
        $rule->update(['coins_amount' => 30]);
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 100]);
        self::assertSame(5, $activities->claimDaily($this->user)['awarded']);
        self::assertSame(0, $activities->claimDaily($this->user)['awarded']);
        self::assertSame(1, DB::table('user_reward_checkins')->count());
        self::assertSame(5, WalletTransaction::query()->sole()->amount);
    }
}
