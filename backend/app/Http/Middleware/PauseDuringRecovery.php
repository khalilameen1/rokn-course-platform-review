<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PauseDuringRecovery
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('operations.disaster_recovery_mode', false)) {
            return $next($request);
        }

        return response()->json([
            'status' => 503,
            'success' => false,
            'code' => 'recovery_in_progress',
        ], 503, ['Retry-After' => '300']);
    }
}
