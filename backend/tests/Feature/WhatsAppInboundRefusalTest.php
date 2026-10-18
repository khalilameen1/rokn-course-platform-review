<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendUserPushNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserWhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WhatsApp linking refuses with coded exceptions (WhatsAppLinkRejected,
 * CoinTaskRejected); the webhook and the coin-task API branch on the code.
 */
final class WhatsAppInboundRefusalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendUserPushNotification::class, SendWhatsAppMessage::class]);
        Http::preventStrayRequests();
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.linking.bot_phone', '201001234567');
        config()->set('whatsapp.linking.webhook_secret', 'refusal-test-secret');
        config()->set('whatsapp.whatspie.api_key', '');
    }

    public function test_a_number_owned_by_another_account_is_not_linked_and_the_sender_is_told_why(): void
    {
        $owner = $this->student('number-owner@rokn.test');
        UserWhatsAppConnection::query()->forceCreate([
            'user_id' => $owner->id,
            'phone_e164' => '+201012345678',
            'declared_at' => now(),
            'ownership_verified' => true,
            'verified_at' => now(),
            'marketing_opt_in' => false,
            'consent_source' => 'whatsapp_link_message',
        ]);
        $student = $this->student('second-account@rokn.test');
        $method = $this->method();
        $start = $this->withToken($student->generateApiToken())
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
            ->assertOk();
        parse_str((string) parse_url((string) $start->json('data.action_url'), PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/whatsapp/webhook?token=refusal-test-secret', [
            'from' => '201012345678',
            'message' => (string) ($query['text'] ?? ''),
        ])
            ->assertOk()
            ->assertJsonPath('matched', false);

        $this->assertDatabaseMissing('user_whatsapp_connections', ['user_id' => $student->id]);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $student->id]);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Queue::assertPushed(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job): bool {
            $fields = (fn (): array => [$this->phone, $this->message])->call($job);

            return $fields[0] === '201012345678'
                && str_contains($fields[1], 'هذا الرقم مرتبط بحساب ركن آخر');
        });
    }

    public function test_coin_task_start_keeps_its_codes_when_whatsapp_linking_refuses(): void
    {
        $student = $this->student('bot-unavailable@rokn.test');
        $method = $this->method();
        $token = $student->generateApiToken();

        config()->set('whatsapp.linking.bot_phone', '');
        $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'whatsapp_bot_unavailable');

        config()->set('whatsapp.enabled', false);
        $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/'.$method->id.'/start')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'task_unavailable');
    }

    private function student(string $email): User
    {
        return User::query()->forceCreate([
            'name' => 'WhatsApp Student', 'email' => $email,
            'role' => 'client', 'active' => true,
            'wallet_coins' => 0, 'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
        ]);
    }

    private function method(): CoinEarningMethod
    {
        return CoinEarningMethod::query()->create([
            'title_ar' => 'اربط واتسابك بركن', 'title_en' => 'Link WhatsApp',
            'action_key' => 'link_whatsapp', 'coins_amount' => 43,
            'requires_external_visit' => true, 'verification_delay_seconds' => 0,
            'is_active' => true, 'is_repeatable' => false,
        ]);
    }
}
