<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\DatabaseCapabilities;

final class RateLimitObservabilityService
{
    public function record(Request $request, string $bucketKey, int $retryAfter): void
    {
        if (!DatabaseCapabilities::hasTable('rate_limit_events')) {
            return;
        }

        $now = now();
        $window = $now->copy()->startOfMinute();
        $actorType = $request->user()
            ? 'user'
            : (trim((string) $request->bearerToken()) !== ''
                ? 'token'
                : ($this->installationId($request) !== '' ? 'installation' : 'ip'));
        $route = (string) ($request->route()?->getName() ?: $request->path());
        $identity = [
            'bucket_key_hash' => hash('sha256', $bucketKey),
            'route_name' => mb_substr($route, 0, 190),
            'window_started_at' => $window,
        ];

        $updated = DB::table('rate_limit_events')
            ->where($identity)
            ->increment('hit_count', 1, [
                'retry_after_seconds' => max(0, $retryAfter),
                'updated_at' => $now,
            ]);
        if ($updated > 0) {
            return;
        }

        $inserted = DB::table('rate_limit_events')->insertOrIgnore($identity + [
            'actor_type' => $actorType,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'method' => mb_substr($request->method(), 0, 10),
            'hit_count' => 1,
            'retry_after_seconds' => max(0, $retryAfter),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            DB::table('rate_limit_events')
                ->where($identity)
                ->increment('hit_count', 1, [
                    'retry_after_seconds' => max(0, $retryAfter),
                    'updated_at' => $now,
                ]);
        }
    }

    private function installationId(Request $request): string
    {
        $value = strtolower(trim((string) $request->header('X-Rokn-Installation')));
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        ) === 1 ? $value : '';
    }
}
