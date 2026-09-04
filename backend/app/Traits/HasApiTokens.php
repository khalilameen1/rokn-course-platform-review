<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

trait HasApiTokens
{
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class, 'user_id');
    }

    public function generateApiToken(
        ?string $verifiedSocialProvider = null,
        ?string $verifiedSocialProviderUserId = null,
        array $session = []
    ): string
    {
        $verifiedSocialProvider = strtolower(trim((string) $verifiedSocialProvider));
        $verifiedSocialProviderUserId = trim((string) $verifiedSocialProviderUserId);
        if ($verifiedSocialProvider === '' || $verifiedSocialProviderUserId === '') {
            $verifiedSocialProvider = null;
            $verifiedSocialProviderUserId = null;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $plainToken = bin2hex(random_bytes(40));

            try {
                $attributes = [
                    'token' => hash('sha256', $plainToken),
                    'issued_at' => now(),
                    'expired_at' => now()->addDays(
                        (int) config('multiple-tokens-auth.token.life_length', 60)
                    ),
                    'session_id' => (string) Str::uuid(),
                    'device_id' => $this->sessionDeviceId($session),
                    'platform' => $this->sessionPlatform($session),
                    'device_class' => $this->sessionDeviceClass($session),
                    'app_version' => $this->sessionValue($session['app_version'] ?? '', 32) ?: null,
                    'app_build' => $this->sessionValue($session['app_build'] ?? '', 16) ?: null,
                    // A linked account can be verified through more than one
                    // provider. Bind the identity used for this login to this
                    // bearer instead of reading a mutable provider from users.
                    'auth_provider' => $verifiedSocialProvider,
                    'auth_provider_user_id' => $verifiedSocialProviderUserId,
                    'last_used_at' => now(),
                ];
                $this->apiTokens()->create($attributes);

                return $plainToken;
            } catch (QueryException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to issue a unique API token.');
    }

    public function purgeApiTokens(): void
    {
        $this->apiTokens()->delete();
    }

    private function sessionValue(mixed $value, int $maxLength): string
    {
        $value = trim((string) $value);
        // Session metadata is deliberately coarse and excludes hardware IDs,
        // advertising IDs, names, IP addresses and raw user-agent strings.
        $value = preg_replace('/[^0-9A-Za-z._-]/', '', $value) ?? '';

        return mb_substr($value, 0, $maxLength);
    }

    private function sessionDeviceId(array $session): ?string
    {
        $deviceId = trim((string) ($session['device_id'] ?? ''));
        return Str::isUuid($deviceId) ? $deviceId : null;
    }

    private function sessionPlatform(array $session): string
    {
        $platform = strtolower($this->sessionValue($session['platform'] ?? 'other', 16));

        return in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'other';
    }

    private function sessionDeviceClass(array $session): ?string
    {
        $deviceClass = strtolower($this->sessionValue($session['device_class'] ?? '', 12));

        return in_array($deviceClass, ['phone', 'tablet'], true)
            ? $deviceClass
            : null;
    }
}
