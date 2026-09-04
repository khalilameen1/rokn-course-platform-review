<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\ApiToken;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

/** Authenticates one active, hashed bearer token. */
final class ApiTokenGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        UserProvider $provider,
        private readonly Request $request
    ) {
        $this->provider = $provider;
    }

    public function user()
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $plainToken = trim((string) ($this->request->bearerToken() ?: ''));
        if ($plainToken === '') {
            return null;
        }

        $apiToken = $this->findToken($plainToken);
        if (!$apiToken) {
            return null;
        }

        $user = $this->provider->retrieveById($apiToken->user_id);
        if (!$user || !(bool) $user->getAuthIdentifier() || !(bool) ($user->active ?? false)) {
            $apiToken->revoke();
            return null;
        }

        if ($apiToken->shouldExtendLife()) {
            $apiToken->forceFill([
                'expired_at' => now()->addDays((int) config('multiple-tokens-auth.token.life_length', 60)),
            ])->save();
        }

        $this->markSessionActive($apiToken);
        $this->request->attributes->set('rokn_api_token', $apiToken);

        return $this->user = $user;
    }

    public function validate(array $credentials = [])
    {
        $plainToken = trim((string) ($credentials['token'] ?? ''));
        $apiToken = $plainToken === '' ? null : $this->findToken($plainToken);
        if (!$apiToken) {
            return false;
        }

        $user = $this->provider->retrieveById($apiToken->user_id);
        if (!$user || !(bool) ($user->active ?? false)) {
            $apiToken->revoke();
            return false;
        }

        return true;
    }

    public function logout()
    {
        $plainToken = trim((string) ($this->request->bearerToken() ?: ''));
        if ($plainToken !== '') {
            $this->findToken($plainToken)?->revoke();
        }

        $this->user = null;
    }

    private function findToken(string $plainToken): ?ApiToken
    {
        return ApiToken::query()
            ->where('token', hash('sha256', $plainToken))
            ->whereHasNotExpired()
            ->first();
    }

    private function markSessionActive(ApiToken $apiToken): void
    {
        if ($apiToken->last_used_at !== null && $apiToken->last_used_at->isAfter(now()->subMinutes(5))) {
            return;
        }

        $usedAt = now();
        ApiToken::query()
            ->whereKey($apiToken->getKey())
            ->where(function ($query): void {
                $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', now()->subMinutes(5));
            })
            ->update(['last_used_at' => $usedAt]);
        $apiToken->last_used_at = $usedAt;
    }
}
