<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ClientDeviceContext;
use App\Auth\SocialLoginCredentials;
use App\Http\Controllers\API\SignController;
use App\Models\ApiToken;
use App\Models\SocialOAuthAttempt;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Models\WalletTransaction;
use App\Services\GoogleService;
use App\Services\SocialLoginAction;
use App\Services\SocialOAuthAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SocialLoginActionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'social_auth.providers' => ['google'],
            'services.google.client_id' => 'fixture-client',
            'services.google.client_secret' => 'fixture-secret',
        ]);
        $this->app->bind(SignController::class,
            static fn () => throw new \LogicException('Application login cannot depend on a controller.'));
    }

    public function test_application_login_uses_a_copied_claim_and_returns_session_facts_without_http(): void
    {
        [$attempt, $code] = $this->claimedAttempt();
        $credentials = SocialLoginCredentials::fromClaimedAttempt($attempt, 'fixture-credential');
        $nonceHash = $attempt->nonce_hash;
        $attempt->nonce_hash = 'changed-after-capture';
        $attempt->provider = 'facebook';
        $this->verifiedGoogle($nonceHash);
        $deviceId = (string) Str::uuid();
        $result = app(SocialLoginAction::class)->login($credentials, new ClientDeviceContext(
            deviceId: $deviceId, deviceToken: 'push-fixture', deviceType: 'phone',
            deviceOs: 'android 16', platform: 'android', deviceClass: 'phone',
            appVersion: '1.0.61', appBuild: '62'
        ), 'en');

        self::assertTrue($result->isSuccessful());
        self::assertSame(200, $result->status);
        self::assertSame('google', $result->provider);
        self::assertSame('en', $result->user->preferred_locale);
        self::assertSame('push-fixture', $result->deviceToken);
        self::assertGreaterThan(0, $result->welcomeBonusGranted);
        $token = ApiToken::query()->sole();
        self::assertSame(hash('sha256', $result->apiToken), $token->token);
        self::assertSame('verified-subject', $token->auth_provider_user_id);
        self::assertSame($deviceId, $token->device_id);
        self::assertSame('62', $token->app_build);
        self::assertSame('android', UserDeviceToken::query()->sole()->device_os);
        $persistedAttempt = app(SocialOAuthAttemptService::class)->inspectCompletion($code);
        self::assertNull($persistedAttempt->completion_consumed_at,
            'HTTP completion, not account/session creation, owns receipt finalization.');
        Http::assertNothingSent();
    }

    public function test_native_input_cannot_promote_a_browser_provider_into_a_verified_attempt(): void
    {
        $this->mock(GoogleService::class)->shouldNotReceive('verify');
        $result = app(SocialLoginAction::class)->login(
            SocialLoginCredentials::native('google', 'unverified-credential'), new ClientDeviceContext()
        );
        self::assertFalse($result->isSuccessful());
        self::assertSame('social_browser_attempt_required', $result->code);
        self::assertSame(0, User::query()->count());
        self::assertSame(0, ApiToken::query()->count());
    }

    public function test_browser_credentials_cannot_be_built_from_an_unclaimed_model(): void
    {
        $this->expectException(\LogicException::class);
        SocialLoginCredentials::fromClaimedAttempt(new SocialOAuthAttempt(), 'unused');
    }

    public function test_reclaimed_attempt_cannot_issue_a_session_or_post_login_rewards(): void
    {
        [$attempt, $code] = $this->claimedAttempt();
        $credentials = SocialLoginCredentials::fromClaimedAttempt($attempt, 'fixture-credential');
        $this->verifiedGoogle($attempt->nonce_hash);
        DB::table('social_oauth_attempts')->where('id', $attempt->id)
            ->update(['completion_processing_at' => now()->subMinutes(3)]);
        $replacement = app(SocialOAuthAttemptService::class)->claimCompletion($code);
        self::assertNotSame($credentials->completionClaimId, $replacement->completion_claim_id);
        $result = app(SocialLoginAction::class)->login($credentials, new ClientDeviceContext(
            deviceId: (string) Str::uuid(), deviceToken: 'must-not-be-registered'
        ));
        self::assertSame('social_login_in_progress', $result->code);
        self::assertSame(0, ApiToken::query()->count());
        self::assertSame(0, UserDeviceToken::query()->count());
        self::assertSame(0, WalletTransaction::query()->count());
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_disposition_does_not_create_local_identity(
        bool $outage, int $status, string $code
    ): void {
        [$attempt] = $this->claimedAttempt();
        $error = $outage
            ? new \Illuminate\Http\Client\ConnectionException('offline')
            : new \RuntimeException('invalid identity signature');
        $this->mock(GoogleService::class)->shouldReceive('verify')->once()->andThrow($error);
        $result = app(SocialLoginAction::class)->login(
            SocialLoginCredentials::fromClaimedAttempt($attempt, 'fixture-credential'),
            new ClientDeviceContext(deviceId: (string) Str::uuid())
        );
        self::assertSame($status, $result->status);
        self::assertSame($code, $result->code);
        self::assertSame(0, User::query()->count());
        self::assertSame(0, ApiToken::query()->count());
    }

    public static function providerFailures(): array
    {
        return [[true, 503, 'social_provider_unavailable'], [false, 422, 'social_identity_verification_failed']];
    }

    public function test_failed_push_registration_does_not_replace_a_verified_login_with_failure(): void
    {
        [$attempt] = $this->claimedAttempt();
        $this->verifiedGoogle($attempt->nonce_hash);
        UserDeviceToken::creating(static fn () => throw new \RuntimeException('push write unavailable'));
        $result = app(SocialLoginAction::class)->login(
            SocialLoginCredentials::fromClaimedAttempt($attempt, 'fixture-credential'),
            new ClientDeviceContext(deviceId: (string) Str::uuid(), deviceToken: 'failed-push')
        );
        self::assertTrue($result->isSuccessful());
        self::assertSame(1, ApiToken::query()->count());
        self::assertSame(0, UserDeviceToken::query()->count());
        self::assertGreaterThan(0, $result->welcomeBonusGranted);
    }

    public function test_failed_welcome_credit_does_not_revoke_the_new_bearer(): void
    {
        [$attempt] = $this->claimedAttempt();
        $this->verifiedGoogle($attempt->nonce_hash);
        WalletTransaction::creating(static fn () => throw new \RuntimeException('welcome ledger unavailable'));
        $result = app(SocialLoginAction::class)->login(
            SocialLoginCredentials::fromClaimedAttempt($attempt, 'fixture-credential'),
            new ClientDeviceContext(deviceId: (string) Str::uuid())
        );
        self::assertTrue($result->isSuccessful());
        self::assertSame(0, $result->welcomeBonusGranted);
        self::assertSame(hash('sha256', $result->apiToken), ApiToken::query()->sole()->token);
        self::assertSame(0, WalletTransaction::query()->count());
    }

    private function verifiedGoogle(?string $nonceHash): void
    {
        $this->mock(GoogleService::class)->shouldReceive('verify')->once()
            ->with('fixture-credential', $nonceHash)->andReturn([
                'id' => 'verified-subject', 'name' => 'Learner',
                'email' => 'learner@example.test', 'email_verified' => true,
            ]);
    }

    private function claimedAttempt(): array
    {
        $attempts = app(SocialOAuthAttemptService::class);
        $code = Str::random(64);
        $attempt = $attempts->begin(Str::random(64), 'google', 'rokn://auth', Str::random(43), 'nonce-fixture');
        $attempts->issueCompletion($attempt, $code, Crypt::encryptString('fixture-credential'));
        return [$attempts->claimCompletion($code), $code];
    }
}
