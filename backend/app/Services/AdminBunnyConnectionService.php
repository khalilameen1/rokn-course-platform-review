<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/** Resolves credentials only on an explicit connection test, never in browser state. */
final class AdminBunnyConnectionService
{
    public function test(#[\SensitiveParameter] array $data): array
    {
        $settings = Setting::query()->first();
        $apiKey = $data['api_key'] ?? null;
        $apiKey = $apiKey ?: config('bunny.stream_api_key') ?: $settings?->bunny_api_key_secret;
        $libraryId = ($data['library_id'] ?? null) ?: config('bunny.library_id') ?: $settings?->bunny_library_id;
        if (! $apiKey || ! $libraryId) {
            return ['success' => false, 'code' => 'credentials_missing', 'message' => 'بيانات Bunny غير مكتملة.'];
        }

        return BunnyService::testConnection($apiKey, $libraryId);
    }
}
