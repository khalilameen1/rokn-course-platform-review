<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AppleService;
use App\Exceptions\SocialProviderUnavailableException;
use App\Models\SocialAccount;
use Exception;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

final class AppleServiceNonceTest extends TestCase
{
    private const KEY_ID = 'apple-test-key';

    private string $privateKey;
    private ?string $clientKeyFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.apple.client_id' => 'com.rokn.app']);
        Cache::flush();

        $openSslConfig = tempnam(sys_get_temp_dir(), 'rokn-openssl-');
        self::assertIsString($openSslConfig);
        self::assertNotFalse(file_put_contents(
            $openSslConfig,
            "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n"
        ));

        try {
            $key = openssl_pkey_new([
                'config' => $openSslConfig,
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
            $privateKey = '';
            self::assertTrue(openssl_pkey_export($key, $privateKey, null, ['config' => $openSslConfig]));
            $this->privateKey = $privateKey;

            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertArrayHasKey('rsa', $details);
            $rsa = $details['rsa'];
            $clientKey = openssl_pkey_new([
                'config' => $openSslConfig,
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ]);
            self::assertInstanceOf(OpenSSLAsymmetricKey::class, $clientKey);
            self::assertTrue(openssl_pkey_export($clientKey, $clientPem, null, ['config' => $openSslConfig]));
            $this->clientKeyFile = tempnam(sys_get_temp_dir(), 'rokn-apple-test-');
            file_put_contents($this->clientKeyFile, $clientPem);
            config([
                'services.apple.team_id' => 'TESTTEAM01',
                'services.apple.key_id' => 'TESTKEY001',
                'services.apple.key_file' => $this->clientKeyFile,
            ]);
        } finally {
            unlink($openSslConfig);
        }

        Http::fake([
            'https://appleid.apple.com/auth/keys' => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'kid' => self::KEY_ID,
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => $this->base64UrlEncode($rsa['n']),
                    'e' => $this->base64UrlEncode($rsa['e']),
                ]],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->clientKeyFile && is_file($this->clientKeyFile)) {
            unlink($this->clientKeyFile);
        }
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

    private function identityToken(string $nonce, string $subject = 'apple-user-123'): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://appleid.apple.com',
            'sub' => $subject,
            'aud' => 'com.rokn.app',
            'iat' => $now - 1,
            'exp' => $now + 300,
            'nonce' => $nonce,
            'email' => 'Learner@Example.com',
            'email_verified' => true,
        ], $this->privateKey, 'RS256', self::KEY_ID);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
