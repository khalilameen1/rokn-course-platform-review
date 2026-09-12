<?php

namespace App\Services;

use App\Exceptions\SocialProviderUnavailableException;
use App\Models\SocialAccount;
use App\Models\User;
use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

final class AppleService
{
    private const APPLE_KEYS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';
    private const CACHE_KEY = 'oauth:apple:jwks:v1';
    private const NONCE_CACHE_PREFIX = 'oauth:apple:nonce:v1:';

    public function verify(string $identityToken, string $rawNonce): array
    {
        return $this->verifyToken($identityToken, $rawNonce, true);
    }

    private function verifyToken(string $identityToken, string $rawNonce, bool $claimNonce): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $rawNonce) !== 1) {
            throw new Exception('Invalid Apple sign-in nonce.');
        }

        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            throw new Exception('Invalid Apple identity token format.');
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
        $kid = trim((string) ($header['kid'] ?? ''));
        if ($kid === '' || ($header['alg'] ?? null) !== 'RS256') {
            throw new Exception('Invalid Apple identity token header.');
        }

        $decoded = JWT::decode($identityToken, $this->keyFor($kid));
        $issuedAt = $decoded->iat ?? null;
        $expiresAt = $decoded->exp ?? null;
        if (
            ($decoded->iss ?? null) !== self::ISSUER
            || empty($decoded->sub)
            || !is_int($issuedAt)
            || !is_int($expiresAt)
            || $issuedAt <= 0
            || $expiresAt <= time()
            || $issuedAt >= $expiresAt
        ) {
            throw new Exception('Invalid Apple identity token claims.');
        }

        $expectedAudiences = array_values(array_filter(array_map(
            'trim', explode(',', (string) config('services.apple.client_id'))
        )));
        if ($expectedAudiences === []) {
            throw new Exception('Apple client ID is not configured.');
        }
        $tokenAudiences = is_array($decoded->aud ?? null)
            ? $decoded->aud
            : [(string) ($decoded->aud ?? '')];
        if (array_intersect($expectedAudiences, $tokenAudiences) === []) {
            throw new Exception('Apple identity token audience does not match.');
        }

        $claim = $decoded->nonce ?? null;
        $expectedNonce = hash('sha256', $rawNonce);
        if (
            !is_string($claim)
            || preg_match('/\A[a-f0-9]{64}\z/', $claim) !== 1
            || !hash_equals($expectedNonce, $claim)
        ) {
            throw new Exception('Apple identity token nonce does not match.');
        }

        // Bind the nonce to this exact signed credential across all API
        // instances. The mobile client retains the token until its session is
        // durable, so an identical retry after a database/network failure must
        // remain valid. A different token can never reuse the nonce.
        $nonceKey = self::NONCE_CACHE_PREFIX . $expectedNonce;
        $credentialHash = hash('sha256', $identityToken);
        $claimedCredentialHash = $claimNonce ? Cache::get($nonceKey) : $credentialHash;
        if ($claimedCredentialHash === null) {
            if (Cache::add(
                $nonceKey,
                $credentialHash,
                now()->addSeconds($expiresAt - time())
            )) {
                $claimedCredentialHash = $credentialHash;
            } else {
                $claimedCredentialHash = Cache::get($nonceKey);
            }
        }
        if (
            !is_string($claimedCredentialHash)
            || !hash_equals($claimedCredentialHash, $credentialHash)
        ) {
            throw new Exception('Apple identity token nonce was already used.');
        }

        $email = isset($decoded->email) && filter_var($decoded->email, FILTER_VALIDATE_EMAIL)
            ? strtolower((string) $decoded->email)
            : null;
        $verifiedClaim = $decoded->email_verified ?? false;

        return [
            'id' => (string) $decoded->sub,
            'client_id' => array_values(array_intersect($expectedAudiences, $tokenAudiences))[0],
            'identity_issued_at' => $issuedAt,
            'name' => null,
            'email' => $email,
            'email_verified' => $email !== null && in_array($verifiedClaim, [true, 1, 'true', '1'], true),
            'is_private_email' => filter_var($decoded->is_private_email ?? false, FILTER_VALIDATE_BOOLEAN),
            'picture' => null,
        ];
    }

    public function exchange(string $identityToken, string $rawNonce, string $authorizationCode): array
    {
        $identity = $this->verify($identityToken, $rawNonce);
        if ($authorizationCode === '' || strlen($authorizationCode) > 4096) {
            throw new Exception('Apple authorization code is required.');
        }
        // Codes are single use. Keep an encrypted, credential-bound receipt so
        // retrying after local transaction/response loss does not redeem twice.
        $receiptKey = 'oauth:apple:exchange:v1:'.hash('sha256', $authorizationCode.'|'.$identityToken);
        try {
            $grant = Cache::lock($receiptKey.':lock', 30)->block(5, function () use (
                $receiptKey, $identity, $rawNonce, $authorizationCode
            ): array {
                $cached = Cache::get($receiptKey);
                if (is_string($cached)) {
                    return json_decode(Crypt::decryptString($cached), true, 16, JSON_THROW_ON_ERROR);
                }
                try {
                    $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(10)
                        ->post('https://appleid.apple.com/auth/token', [
                            'client_id' => $identity['client_id'],
                            'client_secret' => $this->clientSecret($identity['client_id']),
                            'grant_type' => 'authorization_code',
                            'code' => $authorizationCode,
                        ]);
                } catch (\Throwable) {
                    // Never attach an HTTP exception containing a secret form body.
                    throw new SocialProviderUnavailableException('Apple token exchange is temporarily unavailable.');
                }
                if (!$response->successful()) {
                    if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
                        throw new Exception('Apple authorization expired; start a fresh sign-in.');
                    }
                    throw new SocialProviderUnavailableException('Apple token exchange is unavailable.');
                }
                $tokens = $response->json();
                if (!is_array($tokens) || !is_string($tokens['refresh_token'] ?? null)
                    || trim($tokens['refresh_token']) === '' || !is_string($tokens['id_token'] ?? null)) {
                    throw new SocialProviderUnavailableException('Apple returned an incomplete token response.');
                }
                $exchangedIdentity = $this->verifyToken($tokens['id_token'], $rawNonce, false);
                if (!hash_equals($identity['id'], $exchangedIdentity['id'])
                    || !hash_equals($identity['client_id'], $exchangedIdentity['client_id'])) {
                    throw new Exception('Apple authorization does not match the signed identity.');
                }
                $grant = [
                    'apple_client_id' => $identity['client_id'],
                    'apple_refresh_token' => $tokens['refresh_token'],
                ];
                Cache::put($receiptKey, Crypt::encryptString(json_encode($grant, JSON_THROW_ON_ERROR)), now()->addMinutes(10));
                return $grant;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new SocialProviderUnavailableException('Apple sign-in is already being completed.');
        }
        return [...$identity, 'apple_grant' => $grant];
    }

    public function revokeForAccountDeletion(User $user): void
    {
        foreach (SocialAccount::query()->where('user_id', $user->id)->where('provider', 'apple')->get() as $account) {
            $token = trim((string) $account->apple_refresh_token);
            // Apple's TN3194 still requires deletion for legacy users whose
            // tokens were never retained. New sign-ins cannot omit a grant.
            if ($token === '') {
                continue;
            }
            try {
                $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(10)
                    ->post('https://appleid.apple.com/auth/revoke', [
                        'client_id' => $account->apple_client_id,
                        'client_secret' => $this->clientSecret((string) $account->apple_client_id),
                        'token' => $token,
                        'token_type_hint' => 'refresh_token',
                    ]);
            } catch (\Throwable) {
                throw new SocialProviderUnavailableException('Apple authorization revocation is temporarily unavailable.');
            }
            if ($response->status() !== 200) {
                throw new SocialProviderUnavailableException('Apple authorization revocation is unavailable.');
            }
            // Apple returns 200 for an already invalidated token too. Keep the
            // encrypted token until local deletion commits, making a rollback
            // or lost response safe to retry without a new Apple sign-in.
        }
    }

    public function revocationConfigured(): bool
    {
        $keyFile = (string) config('services.apple.key_file');
        return trim((string) config('services.apple.team_id')) !== ''
            && trim((string) config('services.apple.key_id')) !== ''
            && $keyFile !== '' && is_file($keyFile) && is_readable($keyFile);
    }

    private function clientSecret(string $clientId): string
    {
        $allowedClients = array_map('trim', explode(',', (string) config('services.apple.client_id')));
        if (!$this->revocationConfigured() || !in_array($clientId, $allowedClients, true)) {
            throw new SocialProviderUnavailableException('Apple token lifecycle credentials are not configured.');
        }
        try {
            return JWT::encode([
                'iss' => (string) config('services.apple.team_id'),
                'iat' => time(),
                'exp' => time() + 300,
                'aud' => self::ISSUER,
                'sub' => $clientId,
            ], file_get_contents((string) config('services.apple.key_file')), 'ES256', (string) config('services.apple.key_id'));
        } catch (\Throwable) {
            throw new SocialProviderUnavailableException('Apple client secret could not be generated.');
        }
    }

    private function keyFor(string $kid): \Firebase\JWT\Key
    {
        $keys = JWK::parseKeySet($this->appleJwks(false), 'RS256');
        if (isset($keys[$kid])) {
            return $keys[$kid];
        }

        // Apple can rotate signing keys before the normal cache TTL.
        $keys = JWK::parseKeySet($this->appleJwks(true), 'RS256');
        if (!isset($keys[$kid])) {
            throw new Exception('No matching Apple signing key was found.');
        }

        return $keys[$kid];
    }

    private function appleJwks(bool $forceRefresh): array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addHours(6), function (): array {
            $payload = Http::acceptJson()
                ->timeout(8)
                ->retry(2, 150)
                ->get(self::APPLE_KEYS_URL)
                ->throw()
                ->json();
            if (!is_array($payload) || !isset($payload['keys']) || !is_array($payload['keys'])) {
                throw new Exception('Invalid Apple public-keys response.');
            }

            return $payload;
        });
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new Exception('Invalid base64url value.');
        }

        return $decoded;
    }
}
