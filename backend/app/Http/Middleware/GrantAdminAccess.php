<?php

namespace App\Http\Middleware;

use App\Auth\AdminPermissionMatrix;
use Closure;
use Illuminate\Http\Request;

final readonly class GrantAdminAccess
{
    public function __construct(
        private AdminPermissionMatrix $permissions,
        private VerifyDashboardSession $session,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        return $this->session->handle($request, function (Request $request) use ($next) {
            abort_unless(
                $this->permissions->allows(
                    $request->user(),
                    $request->route()?->getName(),
                    $request->method()
                ),
                403
            );

            return $next($request);
        });
    }
}
