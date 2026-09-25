<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AppleService;
use App\Exceptions\SocialProviderUnavailableException;
use App\Models\SocialAccount;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AppleServiceNonceTest extends TestCase
{
    use \Tests\Support\AppleIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAppleIdentityFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanupAppleIdentityFixture();
        parent::tearDown();
    }

    public function test_authorization_exchange_is_credential_bound_encrypted_and_retryable(): void
    {
        $nonce = str_repeat('5', 64);
        $token = $this->identityToken(hash('sha256', $nonce));
        Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
            'id_token' => $token, 'refresh_token' => 'private-refresh-token',
        ])]);
        $service = new AppleService();
        $identity = $service->exchange($token, $nonce, 'single-use-code');
        self::assertSame('private-refresh-token', $identity['apple_grant']['apple_refresh_token']);
        self::assertSame('com.rokn.app', $identity['apple_grant']['apple_client_id']);
        self::assertSame($identity, $service->exchange($token, $nonce, 'single-use-code'));
        $tokenRequests = Http::recorded(fn ($request) => $request->url() === 'https://appleid.apple.com/auth/token');
        self::assertCount(1, $tokenRequests);
        $request = $tokenRequests->first()[0];
        self::assertSame('authorization_code', $request['grant_type']);
        self::assertSame('single-use-code', $request['code']);
        $secretParts = explode('.', $request['client_secret']);
        $claims = json_decode(base64_decode(strtr($secretParts[1], '-_', '+/')), true);
        self::assertSame('TESTTEAM01', $claims['iss']);
        self::assertSame('com.rokn.app', $claims['sub']);
        self::assertSame('https://appleid.apple.com', $claims['aud']);
        $receipt = Cache::get('oauth:apple:exchange:v1:'.hash('sha256', 'single-use-code|'.$token));
        self::assertIsString($receipt);
        self::assertStringNotContainsString('private-refresh-token', $receipt);
        $account = new SocialAccount($identity['apple_grant']);
        self::assertSame('private-refresh-token', $account->apple_refresh_token);
        self::assertNotSame('private-refresh-token', $account->getAttributes()['apple_refresh_token']);
        self::assertArrayNotHasKey('apple_refresh_token', $account->toArray());
    }

    public function test_exchange_rejects_a_code_belonging_to_another_identity(): void
    {
        $nonce = str_repeat('6', 64);
        Http::fake(['https://appleid.apple.com/auth/token' => Http::response([
            'id_token' => $this->identityToken(hash('sha256', $nonce), 'another-user'),
            'refresh_token' => 'must-not-be-stored',
        ])]);
        $this->expectExceptionMessage('authorization does not match');
        (new AppleService())->exchange($this->identityToken(hash('sha256', $nonce)), $nonce, 'another-code');
    }

    public function test_token_endpoint_failures_are_sanitized_and_not_successful_signins(): void
    {
        $nonce = str_repeat('7', 64);
        Http::fake(['https://appleid.apple.com/auth/token' => Http::response(['error' => 'private-response'], 503)]);
        $this->expectException(SocialProviderUnavailableException::class);
        $this->expectExceptionMessage('Apple token exchange is unavailable.');
        (new AppleService())->exchange($this->identityToken(hash('sha256', $nonce)), $nonce, 'retry-code');
    }

    public function test_used_or_expired_code_requires_a_new_signin_not_an_infinite_retry(): void
    {
        $nonce = str_repeat('8', 64);
        Http::fake(['https://appleid.apple.com/auth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->expectExceptionMessage('start a fresh sign-in');
        (new AppleService())->exchange($this->identityToken(hash('sha256', $nonce)), $nonce, 'expired-code');
    }

    public function test_apple_discovery_requires_token_lifecycle_credentials(): void
    {
        $registry = app(\App\Services\SocialAuthProviderRegistry::class);
        self::assertTrue($registry->isReady('apple'));
        config(['services.apple.key_file' => storage_path('missing-apple-key.p8')]);
        self::assertFalse($registry->isReady('apple'));
    }

    public function test_correct_raw_nonce_is_bound_to_the_signed_hash_claim(): void
    {
        $rawNonce = str_repeat('1', 64);

        $identity = (new AppleService())->verify(
            $this->identityToken(hash('sha256', $rawNonce)),
            $rawNonce
        );

        self::assertSame('apple-user-123', $identity['id']);
        self::assertSame('learner@example.com', $identity['email']);
        self::assertTrue($identity['email_verified']);
    }

    public function test_mismatched_nonce_is_rejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('nonce does not match');

        (new AppleService())->verify(
            $this->identityToken(hash('sha256', str_repeat('2', 64))),
            str_repeat('3', 64)
        );
    }

    public function test_missing_or_malformed_raw_nonce_is_rejected_before_token_use(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Apple sign-in nonce');

        (new AppleService())->verify('unused-token', '');
    }

    public function test_an_identical_credential_can_retry_but_another_token_cannot_reuse_its_nonce(): void
    {
        $rawNonce = str_repeat('4', 64);
        $token = $this->identityToken(hash('sha256', $rawNonce));
        $service = new AppleService();

        $service->verify($token, $rawNonce);
        self::assertSame('apple-user-123', $service->verify($token, $rawNonce)['id']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('nonce was already used');
        $service->verify(
            $this->identityToken(hash('sha256', $rawNonce), 'another-apple-user'),
            $rawNonce
        );
    }

}
