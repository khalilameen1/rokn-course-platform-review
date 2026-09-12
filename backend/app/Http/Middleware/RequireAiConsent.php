<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AiConsentService;
use Closure;
use Illuminate\Http\Request;

final class RequireAiConsent
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('api')->user();
        if (!$user || !app(AiConsentService::class)->accepted((int) $user->id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'code' => AiConsentService::REQUIRED,
                'message' => AiConsentService::REQUIRED_MESSAGE,
                'data' => ['consent_version' => AiConsentService::VERSION],
            ], 403);
        }
        return $next($request);
    }
}
