<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendUserPushNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\WalletService;
use App\Services\WhatsAppLinkService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordsRowLocks;
use Tests\TestCase;

final class CoinEarningTaskLifecycleTest extends TestCase
{
    use RecordsRowLocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendUserPushNotification::class, SendWhatsAppMessage::class]);
    }

    public function test_claim_retry_acknowledges_the_same_receipt_after_campaign_is_retired(): void
    {
        $user = $this->student('reward-retry@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('retry-reward', 37);

        $first = $this->withToken($token)
            ->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertOk()
            ->assertJsonPath('data.already_claimed', false)
            ->assertJsonPath('data.earned_amount', 37)
            ->assertJsonPath('data.new_balance', 37)
            ->assertJsonPath('data.task_state', 'claimed');

        self::assertSame('وصلت العملات إلى محفظتك', $first->json('message'));
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('user_coin_earnings', 1);
        $this->assertDatabaseCount('user_coin_task_attempts', 1);
        $this->assertDatabaseHas('student_notifications', [
            'user_id' => $user->id,
            'delivery_key' => 'coins-claimed:'.$user->id.':'.$method->id,
            'notification_type' => 'coins_claimed',
        ]);

        // Model the real recovery case: the server committed, its response was
        // lost, then the administrator retired the campaign before the retry.
        $method->delete();

        $this->withToken($token)
            ->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertOk()
            ->assertJsonPath('data.already_claimed', true)
            ->assertJsonPath('data.earned_amount', 37)
            ->assertJsonPath('data.new_balance', 37)
            ->assertJsonPath('data.task_state', 'claimed');

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('user_coin_earnings', 1);
        $this->assertDatabaseCount('user_coin_task_attempts', 1);
        self::assertSame(1, StudentNotification::query()
            ->where('delivery_key', 'coins-claimed:'.$user->id.':'.$method->id)
            ->count());
    }

    public function test_start_and_claim_lock_the_learner_before_the_attempt_and_a_quota_campaign(): void
    {
        $user = $this->student('reward-lock-order@rokn.test');
        $token = $user->generateApiToken();
        $unlimited = $this->method('lock-order-unlimited', 11);
        $limited = $this->method('lock-order-limited', 13);
        $limited->forceFill(['total_claim_limit' => 5])->save();

        $start = null;
        $startLocks = $this->recordLocks(function () use ($token, $unlimited, &$start): void {
            $start = $this->withToken($token)->postJson('/api/v1/coin-earning-methods/'.$unlimited->id.'/start');
        });
        $start->assertOk()
            ->assertJsonPath('message', 'المهمة جاهزة')
            ->assertJsonPath('data.task_state', 'ready_to_claim');
        self::assertSame(['users:update'], $startLocks);

        $claim = null;
        $claimLocks = $this->recordLocks(function () use ($token, $unlimited, &$claim): void {
            $claim = $this->withToken($token)->postJson('/api/v1/claim-coins', ['method_id' => $unlimited->id]);
        });
        $claim->assertOk()->assertJsonPath('data.earned_amount', 11);
        self::assertSame('users:update', $claimLocks[0]);
        self::assertSame('user_coin_task_attempts:update', $claimLocks[1]);
        // An unlimited campaign has no shared aggregate to serialize on.
        self::assertNotContains('coin_earning_methods:update', $claimLocks);

        $limitedClaim = null;
        $limitedLocks = $this->recordLocks(function () use ($token, $limited, &$limitedClaim): void {
            $limitedClaim = $this->withToken($token)->postJson('/api/v1/claim-coins', ['method_id' => $limited->id]);
        });
        $limitedClaim->assertOk()->assertJsonPath('data.earned_amount', 13);
        self::assertSame(
            ['users:update', 'user_coin_task_attempts:update', 'coin_earning_methods:update'],
            array_slice($limitedLocks, 0, 3)
        );
    }

    public function test_a_refused_claim_keeps_its_stable_code_and_writes_nothing(): void
    {
        $user = $this->student('reward-refused@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('refused-reward', 17);
        $method->forceFill(['verification_delay_seconds' => 600])->save();

        $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
            ->assertOk()
            ->assertJsonPath('data.task_state', 'started');
        $this->withToken($token)
            ->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'claim_not_ready')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('message', 'لم تكتمل المهمة بعد');
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_coin_task_attempts', [
            'user_id' => $user->id,
            'coin_earning_method_id' => $method->id,
            'status' => 'started',
        ]);
    }

    public function test_dashboard_activation_controls_discovery_and_stops_unclaimed_rewards(): void
    {
        $user = $this->student('reward-toggle@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('toggle-reward', 19);

        $this->withToken($token)
            ->getJson('/api/v1/coin-earning-methods')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $method->id,
                'task_state' => 'available',
            ]);

        $method->forceFill(['is_active' => false])->save();

        $this->withToken($token)
            ->getJson('/api/v1/coin-earning-methods')
            ->assertOk()
            ->assertJsonMissing(['id' => $method->id]);
        $this->withToken($token)
            ->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'task_unavailable');

        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $user->id,
            'category' => 'task_reward',
        ]);
    }

    public function test_claim_uses_the_fresh_visit_requirement_loaded_inside_the_transaction(): void
    {
        $user = $this->student('reward-fresh-visit-rule@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('fresh-visit-rule', 23);
        $switched = false;

        CoinEarningMethod::retrieved(function (CoinEarningMethod $retrieved) use ($method, &$switched): void {
            if ($switched || $retrieved->id !== $method->id) {
                return;
            }

            $switched = true;
            DB::table('coin_earning_methods')
                ->where('id', $method->id)
                ->update(['requires_external_visit' => true]);
        });

        $this->withToken($token)
            ->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'task_not_started');

        self::assertTrue($switched, 'The rule must change after the request reads its first snapshot.');
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $user->id,
            'category' => 'task_reward',
        ]);
        $this->assertDatabaseMissing('user_coin_task_attempts', [
            'user_id' => $user->id,
            'coin_earning_method_id' => $method->id,
        ]);
    }

    public function test_whatsapp_reward_and_its_inbox_receipt_roll_back_and_retry_together(): void
    {
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.linking.bot_phone', '201001234567');
        config()->set('whatsapp.linking.webhook_secret', 'reward-webhook-secret');
        config()->set('whatsapp.whatspie.api_key', '');

        $user = $this->student('whatsapp-atomic-reward@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('link_whatsapp', 43);
        $start = $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
            ->assertOk();
        parse_str((string) parse_url((string) $start->json('data.action_url'), PHP_URL_QUERY), $query);
        $message = (string) ($query['text'] ?? '');
        self::assertStringContainsString('ROKN_LINK_', $message);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_whatsapp_reward_notification
            BEFORE INSERT ON student_notifications
            WHEN NEW.delivery_key LIKE 'whatsapp-linked:%'
            BEGIN
                SELECT RAISE(ABORT, 'forced notification failure');
            END
        SQL);

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/whatsapp/webhook?token=reward-webhook-secret', [
                'from' => '201012345678',
                'message' => $message,
            ]);
            self::fail('A failed durable inbox receipt must abort the reward operation.');
        } catch (QueryException) {
            // Expected: the enclosing transaction must leave every reward fact retriable.
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_whatsapp_reward_notification');
            $this->withExceptionHandling();
        }

        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $user->id,
            'category' => 'task_reward',
        ]);
        $this->assertDatabaseMissing('user_whatsapp_connections', ['user_id' => $user->id]);

        $this->postJson('/api/v1/whatsapp/webhook?token=reward-webhook-secret', [
            'from' => '201012345678',
            'message' => $message,
        ])
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('already_claimed', false)
            ->assertJsonPath('coins_added', 43);

        $this->assertDatabaseHas('student_notifications', [
            'user_id' => $user->id,
            'delivery_key' => 'whatsapp-linked:'.$user->id,
            'notifiable_type' => CoinEarningMethod::class,
            'notifiable_id' => $method->id,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'category' => 'task_reward',
            'amount' => 43,
        ]);
    }

    public function test_opening_whatsapp_does_not_mark_an_unverified_task_ready_to_claim(): void
    {
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.linking.bot_phone', '201001234567');
        $user = $this->student('whatsapp-unverified@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('link_whatsapp', 43);
        $method->update(['requires_external_visit' => true]);

        $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
            ->assertOk()
            ->assertJsonPath('data.task_state', 'started');

        $this->withToken($token)->getJson('/api/v1/coin-earning-methods')
            ->assertOk()
            ->assertJsonFragment(['id' => $method->id, 'task_state' => 'started']);
        $this->withToken($token)->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'whatsapp_not_verified');
    }

    public function test_verified_whatsapp_reward_can_be_claimed_after_spending_without_another_message(): void
    {
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.linking.bot_phone', '201001234567');
        Setting::query()->firstOrCreate()->forceFill(['reward_balance_cap' => 100])->save();
        $user = $this->student('whatsapp-deferred@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('link_whatsapp', 43);
        $method->update(['requires_external_visit' => true]);
        $wallet = app(WalletService::class);
        $wallet->credit($user->id, 100, 'test_reward', 'initial-reward');

        $start = $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')->assertOk();
        parse_str((string) parse_url((string) $start->json('data.action_url'), PHP_URL_QUERY), $query);
        $inbound = app(WhatsAppLinkService::class)->consumeInbound('201012345678', $query['text']);
        self::assertTrue($inbound['reward_deferred']);
        $this->assertDatabaseMissing('user_coin_earnings', ['user_id' => $user->id]);

        $wallet->debit($user->id, 60, 'test_purchase', 'spend-reward');
        $this->withToken($token)->getJson('/api/v1/coin-earning-methods')
            ->assertOk()
            ->assertJsonFragment(['id' => $method->id, 'task_state' => 'ready_to_claim']);

        // Returning to the task must not generate a new link or require a
        // second message for an ownership check already completed by inbound.
        $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start', ['supports_ready_claim' => true])
            ->assertOk()
            ->assertJsonPath('data.task_state', 'ready_to_claim')
            ->assertJsonPath('data.action_url', null);
        $this->assertDatabaseCount('whatsapp_link_tokens', 1);

        foreach ([false, true] as $replay) {
            $this->withToken($token)->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
                ->assertOk()
                ->assertJsonPath('data.already_claimed', $replay)
                ->assertJsonPath('data.new_balance', 83)
                ->assertJsonPath('data.task_state', 'claimed');
        }
        $this->assertDatabaseCount('user_coin_earnings', 1);
        self::assertSame(1, DB::table('wallet_transactions')->where('category', 'task_reward')->count());
    }

    public function test_legacy_mobile_can_finish_a_deferred_whatsapp_reward_with_its_linking_message(): void
    {
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.linking.bot_phone', '201001234567');
        Setting::query()->firstOrCreate()->forceFill(['reward_balance_cap' => 100])->save();
        $user = $this->student('whatsapp-legacy@rokn.test');
        $token = $user->generateApiToken();
        $method = $this->method('link_whatsapp', 43);
        $method->update(['requires_external_visit' => true]);
        $wallet = app(WalletService::class);
        $wallet->credit($user->id, 100, 'test_reward', 'initial-reward');

        foreach ([true, false] as $deferred) {
            $start = $this->withToken($token)
                ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
                ->assertOk()
                ->assertJsonPath('data.task_state', 'started');
            parse_str((string) parse_url((string) $start->json('data.action_url'), PHP_URL_QUERY), $query);
            self::assertStringContainsString('ROKN_LINK_', $query['text']);
            $result = app(WhatsAppLinkService::class)->consumeInbound('201012345678', $query['text']);
            self::assertSame($deferred, $result['reward_deferred']);
            if ($deferred) {
                $wallet->debit($user->id, 60, 'test_purchase', 'spend-reward');
            }
        }

        $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
            ->assertOk()
            ->assertJsonPath('data.task_state', 'claimed');
        $this->assertDatabaseCount('user_coin_earnings', 1);
        $this->assertDatabaseCount('whatsapp_link_tokens', 2);
        self::assertSame(83, $wallet->balances($user->fresh())['total']);
        self::assertSame(1, DB::table('wallet_transactions')->where('category', 'task_reward')->count());
    }

    private function student(string $email): User
    {
        return User::query()->forceCreate([
            'name' => 'Reward Student',
            'name_ar' => 'طالب المكافأة',
            'name_en' => 'Reward Student',
            'email' => $email,
            'role' => 'client',
            'active' => true,
            'social_provider' => 'google',
            'social_id' => 'google:'.$email,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
    }

    private function method(string $actionKey, int $coins): CoinEarningMethod
    {
        return CoinEarningMethod::query()->create([
            'title_ar' => 'مهمة المكافأة',
            'title_en' => 'Reward task',
            'coins_amount' => $coins,
            'action_key' => $actionKey,
            'requires_external_visit' => false,
            'verification_delay_seconds' => 0,
            'sort_order' => 1,
            'is_active' => true,
            'is_repeatable' => false,
        ]);
    }
}
