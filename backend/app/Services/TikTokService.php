<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SocialProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class TikTokService
{
    /**
     * Resolve the identity from TikTok itself. No user id supplied by the app is trusted.
     */
    public function verify(string $accessToken): array
    {
        $userInfoUrl = trim((string) config('social_auth.tiktok.user_info_url'));
        if ($userInfoUrl === '') {
            throw new SocialProviderUnavailableException('TikTok user-info endpoint is not configured.');
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(max(1, min(10, (int) config('services.tiktok.connect_timeout_seconds', 3))))
                ->timeout(max(3, min(30, (int) config('social_auth.timeout_seconds', 10))))
                ->get($userInfoUrl, [
                    'fields' => 'open_id,union_id,display_name,avatar_url',
                ]);
        } catch (ConnectionException $exception) {
            throw new SocialProviderUnavailableException(
                'TikTok identity service is unreachable.',
                0,
                $exception
            );
        } catch (Throwable $exception) {
            throw new SocialProviderUnavailableException(
                'TikTok identity request failed.',
                0,
                $exception
            );
        }

        if (! $response->successful()) {
            if ($response->serverError() || $response->status() === 429) {
                throw new SocialProviderUnavailableException('TikTok identity service is unavailable.');
            }
            throw new RuntimeException('TikTok rejected the access token.');
        }

        $payload = $response->json();
        $errorCode = (string) data_get($payload, 'error.code', '');
        $user = data_get($payload, 'data.user');

        if ($errorCode !== '' && $errorCode !== 'ok') {
            throw new RuntimeException('TikTok rejected the access token.');
        }
        if (! is_array($user) || empty($user['open_id'])) {
            throw new RuntimeException('TikTok returned an invalid identity response.');
        }

        return [
            'id' => (string) $user['open_id'],
            // The user-info response does not attest when a direct/native
            // login began. Browser OAuth supplies a server-owned attempt time.
            'identity_issued_at' => null,
            'name' => isset($user['display_name']) ? (string) $user['display_name'] : null,
            'email' => null,
            'email_verified' => false,
            'picture' => isset($user['avatar_url']) ? (string) $user['avatar_url'] : null,
        ];
    }
}
