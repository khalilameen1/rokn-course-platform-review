<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AppleIdentityFixture;
use Tests\TestCase;

final class AppleNativeLoginSessionTest extends TestCase
{
    use RefreshDatabase;
    use AppleIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['social_auth.providers' => ['apple']]);
        $this->configureAppleIdentityFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanupAppleIdentityFixture();
        parent::tearDown();
    }

    public function test_native_apple_login_and_identical_retry_preserve_identity_and_exchange_once(): void
    {
        $payload = $this->nativePayload();
        $response = $this->postJson('/api/v1/social-login', $payload)->assertOk()
            ->assertJsonPath('data.user.name', 'طالب رُكن')
            ->assertJsonPath('data.user.social_provider', 'apple')
            ->assertJsonPath('data.user.preferred_locale', 'en');
        $bearer = $response->json('data.api_token');
        self::assertTrue(DB::table('api_tokens')->where('token', hash('sha256', $bearer))
            ->where('auth_provider', 'apple')->where('auth_provider_user_id', 'apple-user-123')->exists());
        $link = SocialAccount::query()->sole();
        self::assertSame('com.rokn.app', $link->apple_client_id);
        self::assertSame('native-refresh-fixture', $link->apple_refresh_token);
        self::assertNotSame('native-refresh-fixture', $link->getRawOriginal('apple_refresh_token'));
        $walletCount = DB::table('wallet_transactions')->count();
        $this->postJson('/api/v1/social-login', $payload)->assertOk()
            ->assertJsonPath('data.welcome_bonus_granted', 0);
        self::assertSame(1, User::query()->count());
        self::assertSame(1, SocialAccount::query()->count());
        self::assertSame($walletCount, DB::table('wallet_transactions')->count());
        self::assertCount(1, Http::recorded(fn ($request) =>
            $request->url() === 'https://appleid.apple.com/auth/token'));
    }

    public function test_old_signed_identity_cannot_use_caller_supplied_browser_context_to_skip_freshness(): void
    {
        $payload = $this->nativePayload(time() - 700);
        $payload += [
            'social_attempt_started_at' => now()->toISOString(),
            'social_browser_attempt_verified' => true,
            'social_oauth_attempt_id' => 1,
            'social_oauth_completion_claim_id' => (string) Str::uuid(),
        ];
        $this->postJson('/api/v1/social-login', $payload)->assertStatus(410)
            ->assertJsonPath('code', 'social_login_fresh_attempt_required');
        self::assertSame(0, User::query()->count());
        self::assertSame(0, DB::table('api_tokens')->count());
    }

    public function test_wrong_native_nonce_is_rejected_before_an_authorization_exchange(): void
    {
        $payload = $this->nativePayload();
        $payload['nonce'] = str_repeat('9', 64);
        $this->postJson('/api/v1/social-login', $payload)->assertStatus(422)
            ->assertJsonPath('code', 'social_identity_verification_failed');
        self::assertSame(0, User::query()->count());
        self::assertCount(0, Http::recorded(fn ($request) =>
            $request->url() === 'https://appleid.apple.com/auth/token'));
    }

    private function nativePayload(?int $issuedAt = null): array
    {
        $nonce = str_repeat('4', 64);
        $token = $this->identityToken(hash('sha256', $nonce), issuedAt: $issuedAt);
        Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
            'id_token' => $token, 'refresh_token' => 'native-refresh-fixture',
        ])]);
        return [
            'provider' => 'apple', 'token' => $token, 'nonce' => $nonce,
            'authorization_code' => 'native-auth-code', 'provider_name' => 'طالب رُكن',
            'device_id' => (string) Str::uuid(), 'device_os' => 'ios',
            'device_token' => 'apple-push-fixture', 'preferred_locale' => 'en',
        ];
    }
}
