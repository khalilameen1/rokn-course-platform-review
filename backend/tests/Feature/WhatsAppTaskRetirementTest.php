<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\SendUserPushNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CoinEarningMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WhatsAppTaskRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendUserPushNotification::class, SendWhatsAppMessage::class]);
        Http::preventStrayRequests();
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.linking.bot_phone', '201001234567');
        config()->set('whatsapp.linking.webhook_secret', 'local-reward-test');
        config()->set('whatsapp.whatspie.api_key', '');
    }

    #[DataProvider('campaignQuotas')]
    public function test_message_opened_before_dashboard_deletion_still_links_but_does_not_credit_the_retired_reward(?int $quota): void
    {
        [$student, $token, $method, $message] = $this->openTask($quota);
        $this->deleteFromDashboard($method);

        $this->withToken($token)->getJson('/api/v1/coin-earning-methods')
            ->assertOk()->assertJsonMissing(['id' => $method->id]);
        $this->withToken($token)->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertStatus(409)->assertJsonPath('code', 'task_unavailable');

        $this->postInbound($message)
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('coins_added', 0)
            ->assertJsonPath('reward_unavailable', true)
            ->assertJsonPath('reward_deferred', false);

        $this->assertDatabaseHas('user_whatsapp_connections', [
            'user_id' => $student->id,
            'phone_e164' => '+201012345678',
            'ownership_verified' => true,
        ]);
        $this->assertDatabaseMissing('whatsapp_link_tokens', ['consumed_at' => null]);
        $this->assertDatabaseMissing('user_coin_earnings', ['user_id' => $student->id]);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $student->id]);
        $this->assertDatabaseMissing('student_notifications', [
            'user_id' => $student->id,
            'delivery_key' => 'whatsapp-linked:' . $student->id,
        ]);
        $this->withToken($token)->getJson('/api/v1/wallet')
            ->assertOk()->assertJsonPath('data.balance', 0)
            ->assertJsonCount(0, 'data.recent_transactions');

        // The accepted token remains idempotent and cannot credit on replay.
        $this->postInbound($message)->assertOk()->assertJsonPath('coins_added', 0);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $student->id]);
    }

    public static function campaignQuotas(): array
    {
        return ['unlimited' => [null], 'finite capacity remaining' => [2]];
    }

    #[DataProvider('unavailableStates')]
    public function test_stopped_or_expired_campaign_preserves_linking_without_a_reward(string $state): void
    {
        [$student, $token, $method, $message] = $this->openTask();
        $method->forceFill($state === 'stopped'
            ? ['is_active' => false]
            : ['ends_at' => now()->subSecond()])->save();
        $this->postInbound($message)->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('coins_added', 0)
            ->assertJsonPath('reward_unavailable', true);
        $this->assertDatabaseHas('user_whatsapp_connections', [
            'user_id' => $student->id, 'ownership_verified' => true,
        ]);
        $this->withToken($token)->getJson('/api/v1/coin-earning-methods')
            ->assertOk()->assertJsonMissing(['id' => $method->id]);
        $this->withToken($token)->getJson('/api/v1/wallet')
            ->assertOk()->assertJsonPath('data.balance', 0);
    }

    public static function unavailableStates(): array
    {
        return ['stopped' => ['stopped'], 'expired' => ['expired']];
    }

    public function test_a_link_opened_before_another_learner_takes_the_last_reward_still_verifies_without_over_crediting(): void
    {
        [$student, $token, $method, $message] = $this->openTask(1);
        $otherStudent = User::query()->forceCreate([
            'name' => 'Other Student', 'email' => 'quota-winner@rokn.test',
            'role' => 'client', 'active' => true,
            'social_provider' => 'google', 'social_id' => 'quota-winner-google',
            'wallet_coins' => 0, 'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
        ]);
        $this->app['auth']->forgetGuards();
        $otherStart = $this->withToken($otherStudent->generateApiToken())
            ->postJson('/api/v1/coin-earning-methods/' . $method->id . '/start')
            ->assertOk();
        parse_str((string) parse_url((string) $otherStart->json('data.action_url'), PHP_URL_QUERY), $query);
        $this->postInbound((string) $query['text'], '201022345678')
            ->assertOk()->assertJsonPath('coins_added', 43);

        $this->postInbound($message)->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('coins_added', 0)
            ->assertJsonPath('reward_unavailable', true);
        $this->assertDatabaseHas('user_whatsapp_connections', [
            'user_id' => $student->id, 'ownership_verified' => true,
        ]);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $student->id]);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/coin-earning-methods')
            ->assertOk()->assertJsonMissing(['id' => $method->id]);
        $this->withToken($token)->getJson('/api/v1/wallet')
            ->assertOk()->assertJsonPath('data.balance', 0);
    }

    public function test_active_reward_stays_paid_and_replayable_after_the_dashboard_deletes_its_campaign(): void
    {
        [$student, $token, $method, $message] = $this->openTask();
        $this->postInbound($message)->assertOk()
            ->assertJsonPath('coins_added', 43)
            ->assertJsonPath('reward_unavailable', false);
        $this->withToken($token)->getJson('/api/v1/coin-earning-methods')
            ->assertOk()->assertJsonFragment(['id' => $method->id, 'task_state' => 'claimed']);
        $this->deleteFromDashboard($method);

        $this->postInbound($message)->assertOk()
            ->assertJsonPath('coins_added', 0)
            ->assertJsonPath('already_claimed', true)
            ->assertJsonPath('reward_unavailable', false);
        $this->withToken($token)->postJson('/api/v1/claim-coins', ['method_id' => $method->id])
            ->assertOk()->assertJsonPath('data.task_state', 'claimed')
            ->assertJsonPath('data.already_claimed', true)
            ->assertJsonPath('data.new_balance', 43);
        $this->withToken($token)->getJson('/api/v1/wallet')
            ->assertOk()->assertJsonPath('data.balance', 43)
            ->assertJsonCount(1, 'data.recent_transactions');
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('user_coin_earnings', 1);
        $this->assertDatabaseCount('student_notifications', 1);
        $this->assertDatabaseHas('user_whatsapp_connections', [
            'user_id' => $student->id, 'ownership_verified' => true,
        ]);
    }

    /** @return array{User, string, CoinEarningMethod, string} */
    private function openTask(?int $quota = null): array
    {
        $student = User::query()->forceCreate([
            'name' => 'Reward Student', 'email' => 'retired-task@rokn.test',
            'role' => 'client', 'active' => true,
            'social_provider' => 'google', 'social_id' => 'retired-task-google',
            'wallet_coins' => 0, 'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
        ]);
        $token = $student->generateApiToken();
        $method = CoinEarningMethod::query()->create([
            'title_ar' => 'اربط واتسابك بركن', 'title_en' => 'Link WhatsApp',
            'action_key' => 'link_whatsapp', 'coins_amount' => 43,
            'requires_external_visit' => true, 'verification_delay_seconds' => 0,
            'is_active' => true, 'is_repeatable' => false,
            'total_claim_limit' => $quota,
        ]);
        $start = $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/' . $method->id . '/start', [
                'supports_ready_claim' => true,
            ])->assertOk()->assertJsonPath('data.task_state', 'started');
        parse_str((string) parse_url((string) $start->json('data.action_url'), PHP_URL_QUERY), $query);
        $message = (string) ($query['text'] ?? '');
        self::assertStringContainsString('ROKN_LINK_', $message);

        return [$student, $token, $method, $message];
    }

    private function deleteFromDashboard(CoinEarningMethod $method): void
    {
        $admin = User::query()->forceCreate([
            'name' => 'Reward Admin', 'email' => 'reward-admin@rokn.test',
            'role' => 'admin', 'active' => true,
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $edit = $this->actingAs($admin, 'web')
            ->get(route('admin.coin-earning-methods.edit', $method))->assertOk();
        $this->delete(route('admin.coin-earning-methods.destroy', $method), [
            'editor_version' => $edit->viewData('editorVersion'),
        ])->assertRedirect(route('admin.coin-earning-methods.index'));
        $this->assertSoftDeleted('coin_earning_methods', ['id' => $method->id]);
    }

    private function postInbound(string $message, string $sender = '201012345678'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/whatsapp/webhook?token=local-reward-test', [
            'from' => $sender,
            'message' => $message,
        ]);
    }
}
