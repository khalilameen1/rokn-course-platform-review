<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GoogleService;
use App\Services\SocialOAuthAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SocialLoginBindingEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'social_auth.providers' => ['google'],
            'social_auth.return_urls' => ['rokn://auth'],
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
    }

    public function test_browser_completion_creates_one_account_and_replays_the_same_native_session(): void
    {
        $this->app->bind(\App\Http\Controllers\API\SignController::class,
            static fn () => throw new \LogicException('Browser completion must not call a sibling controller.'));
        $this->verifiedGoogle();
        $payload = $this->completionPayload();
        $payload['role'] = 'admin';
        $payload['active'] = false;
        $first = $this->withHeaders([
            'Accept-Language' => 'en', 'X-Rokn-Platform' => 'android',
            'X-Rokn-Device-Class' => 'phone', 'X-Rokn-App-Version' => '1.0.61',
            'X-Rokn-App-Build' => '62',
        ])
            ->postJson('/api/v1/social-auth/complete', $payload)->assertOk()
            ->assertJsonPath('data.user.social_provider', 'google');
        $token = $first->json('data.api_token');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        $user = User::query()->sole();
        self::assertSame('learner@example.test', $user->email);
        self::assertSame('client', $user->role);
        self::assertTrue((bool) $user->active);
        self::assertFalse($user->isFillable('role'));
        self::assertSame('en', $user->preferred_locale);
        self::assertSame('google-native-id', SocialAccount::query()->sole()->provider_user_id);
        self::assertSame(1, DB::table('api_tokens')->where('token', hash('sha256', $token))->count());
        $stored = DB::table('api_tokens')->sole();
        self::assertSame($payload['device_id'], $stored->device_id);
        self::assertSame('android', $stored->platform);
        self::assertSame('phone', $stored->device_class);
        self::assertSame('1.0.61', $stored->app_version);
        self::assertSame('62', $stored->app_build);
        $walletBefore = DB::table('wallet_transactions')->count();
        $this->postJson('/api/v1/social-auth/complete', $payload)->assertOk()
            ->assertJsonPath('data.api_token', $token);
        self::assertSame(1, User::query()->count());
        self::assertSame(1, SocialAccount::query()->count());
        self::assertSame(1, DB::table('api_tokens')->count());
        self::assertSame($walletBefore, DB::table('wallet_transactions')->count());
        Http::assertNothingSent();
    }

    public function test_account_link_conflict_keeps_completion_retryable_without_issuing_a_session(): void
    {
        User::query()->forceCreate([
            'name' => 'Existing learner', 'email' => 'learner@example.test',
            'password' => 'unused', 'role' => 'client', 'active' => true,
            'email_verified_at' => null,
        ]);
        $this->verifiedGoogle();
        $payload = $this->completionPayload();
        $this->postJson('/api/v1/social-auth/complete', $payload)->assertStatus(409)
            ->assertJsonPath('code', 'social_account_conflict');
        self::assertSame(1, User::query()->count());
        self::assertSame(0, SocialAccount::query()->count());
        self::assertSame(0, DB::table('api_tokens')->count());
        $attempt = app(SocialOAuthAttemptService::class)->inspectCompletion($payload['code']);
        self::assertNotNull($attempt);
        self::assertNull($attempt->completion_consumed_at);
        self::assertNull($attempt->completion_claim_id);
        Http::assertNothingSent();
    }

    public function test_transient_session_failure_does_not_burn_social_completion_code(): void
    {
        config([
            'social_auth.providers' => ['google'],
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(
            base64_encode(hash('sha256', $verifier, true)),
            '+/',
            '-_'
        ), '=');
        $completionCode = str_repeat('c', 64);
        $deviceId = (string) Str::uuid();
        $attempts = app(\App\Services\SocialOAuthAttemptService::class);
        $attempt = $attempts->begin(
            str_repeat('s', 64),
            'google',
            'rokn://auth',
            $challenge
        );
        $attempts->issueCompletion(
            $attempt,
            $completionCode,
            \Illuminate\Support\Facades\Crypt::encryptString('provider-token')
        );

        $verificationCalls = 0;
        $this->mock(\App\Services\GoogleService::class)->shouldReceive('verify')->twice()
            ->andReturnUsing(function () use (&$verificationCalls): array {
                if (++$verificationCalls === 1) {
                    throw new \Illuminate\Http\Client\ConnectionException('Temporary provider outage');
                }
                return [
                    'id' => 'retryable-google-identity',
                    'name' => 'Retry learner',
                    'email' => 'retry-learner@example.test',
                    'email_verified' => true,
                ];
            });

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => $completionCode,
            'code_verifier' => $verifier,
            'device_id' => $deviceId,
            'device_os' => 'android',
            'device_type' => 'android',
        ])->assertStatus(503);

        $this->assertDatabaseHas('social_oauth_attempts', [
            'id' => $attempt->id,
            'completion_processing_at' => null,
            'completion_consumed_at' => null,
        ]);

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => $completionCode,
            'code_verifier' => $verifier,
            'device_id' => $deviceId,
            'device_os' => 'android',
            'device_type' => 'android',
        ])->assertOk();

        $this->assertDatabaseMissing('social_oauth_attempts', [
            'id' => $attempt->id,
            'completion_consumed_at' => null,
        ]);
        self::assertNull($attempt->fresh()->encrypted_token);
    }

    private function verifiedGoogle(): void
    {
        $this->mock(GoogleService::class)->shouldReceive('verify')->once()->andReturn([
            'id' => 'google-native-id', 'name' => 'Provider learner',
            'email' => 'LEARNER@example.test', 'email_verified' => true,
        ]);
    }

    private function completionPayload(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = Str::random(64);
        $attempts = app(SocialOAuthAttemptService::class);
        $attempt = $attempts->begin(Str::random(64), 'google', 'rokn://auth', $challenge);
        $attempts->issueCompletion($attempt, $code, Crypt::encryptString('provider-token'));
        return ['code' => $code, 'code_verifier' => $verifier,
            'device_id' => (string) Str::uuid(), 'device_os' => 'android'];
    }
}
