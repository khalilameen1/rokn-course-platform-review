<?php

namespace App\Http\Middleware;

use App\Auth\AdminPermissionMatrix;
use Closure;
use Illuminate\Http\Request;

final class RequireAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless(
            $user
                && (bool) $user->active
                && app(AdminPermissionMatrix::class)->allows($user, $request->route()?->getName(), $request->method()),
            403
        );

        return $next($request);
    }
}
