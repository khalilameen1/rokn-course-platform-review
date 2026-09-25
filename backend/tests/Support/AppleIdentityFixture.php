<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/** Disposable signing keys and a fake Apple JWKS endpoint; no account credentials. */
trait AppleIdentityFixture
{
    private const KEY_ID = 'apple-test-key';

    private string $privateKey;
    private ?string $clientKeyFile = null;

    private function configureAppleIdentityFixture(): void
    {
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

    private function cleanupAppleIdentityFixture(): void
    {
        if ($this->clientKeyFile && is_file($this->clientKeyFile)) {
            unlink($this->clientKeyFile);
        }
    }

    private function identityToken(string $nonce, string $subject = 'apple-user-123', ?int $issuedAt = null): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://appleid.apple.com',
            'sub' => $subject,
            'aud' => 'com.rokn.app',
            'iat' => $issuedAt ?? $now - 1,
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
