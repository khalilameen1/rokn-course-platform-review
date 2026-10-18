<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApiResponseService;
use Closure;
use Illuminate\Http\Request;

/**
 * Allow a true guest request, but never silently downgrade a presented,
 * expired account credential into guest access.
 *
 * Course details and support are intentionally usable without an account.
 * They are also account-aware when the app sends a bearer. Treating an
 * invalid bearer as "no user" made an enrolled learner suddenly see purchase
 * controls and could attach a support reply to the guest journey while the
 * phone still believed it was signed in.
 */
final readonly class AuthenticateOptionalApiToken
{
    public function __construct(private ApiResponseService $responses) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (trim((string) $request->bearerToken()) === '') {
            return $next($request);
        }

        if (! auth('api')->user()) {
            return $this->responses->error(
                "انتهت جلسة الدخول\nسجّل الدخول مرة أخرى",
                401,
                null,
                ['code' => 'session_expired']
            );
        }

        return $next($request);
    }
}
