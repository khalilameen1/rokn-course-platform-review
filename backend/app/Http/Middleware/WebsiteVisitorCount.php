<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\BusinessClock;
use App\Support\PrivacyFingerprint;
use Closure;
use Illuminate\Http\Request;
use App\Models\Visitor;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WebsiteVisitorCount
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // The app opens the canonical course catalogue as its home journey.
        // The daily cache key below keeps pagination and refreshes idempotent.
        if (
            $request->isMethod('GET')
            && $request->is('api/v1/courses/list')
        ) {
            $this->recordVisitor($request);
        }

        return $next($request);
    }

    /**
     * Record visitor information
     *
     * @param Request $request
     * @return void
     */
    private function recordVisitor(Request $request): void
    {
        try {
            $businessDay = BusinessClock::now()->format('Y-m-d');
            [$dayStart, $dayEnd] = BusinessClock::localDayRangeUtc($businessDay);
            $agent = new Agent();
            $ip = $request->ip();
            if (!$ip) {
                return;
            }

            $visitorKey = PrivacyFingerprint::make($ip);
            if (!$visitorKey) {
                return;
            }
            $cacheKey = sprintf(
                'visitor:daily:%s:%s',
                $businessDay,
                $visitorKey
            );
            if (!Cache::add($cacheKey, true, $dayEnd)) {
                return;
            }

            $browser = $agent->browser();
            $os = $agent->platform();
            $deviceType = $agent->isMobile() ? 'Mobile' : ($agent->isTablet() ? 'Tablet' : 'Desktop');

            // Avoid duplicate for the same day
            $existingVisitor = Visitor::where('ip_address', $visitorKey)
                ->where('visited_at', '>=', $dayStart)
                ->where('visited_at', '<', $dayEnd)
                ->first();

            if (!$existingVisitor) {
                Visitor::create([
                    // HMAC keeps daily uniqueness and aggregate reporting
                    // without storing a recoverable IP or a fingerprinting UA.
                    'ip_address' => $visitorKey,
                    'user_agent' => null,
                    'browser' => $browser . ' ' . $agent->version($browser),
                    'operating_system' => $os . ' ' . $agent->version($os),
                    'device_type' => $deviceType,
                    'visited_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            if (isset($cacheKey)) {
                Cache::forget($cacheKey);
            }
            Log::error('Failed to record visitor', ['exception' => $exception::class]);
        }
    }
}
