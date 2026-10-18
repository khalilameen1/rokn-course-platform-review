<?php

namespace App\Http\Middleware;

use App\Auth\AdminPermissionMatrix;
use App\Auth\AdminSessionIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class GrantAdminAccess
{
    public function __construct(
        private readonly AdminPermissionMatrix $permissions,
        private readonly AdminSessionIdentity $sessionIdentity
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }
        if (! (bool) $user->active) {
            return $this->logout($request);
        }

        if ($request->hasSession()) {
            $expectedIdentity = $this->sessionIdentity->fingerprint($user);
            $sessionIdentity = (string) $request->session()->get(
                AdminSessionIdentity::SESSION_KEY,
                ''
            );
            if ($sessionIdentity === '') {
                // Sessions created before this guard was deployed are pinned
                // on their first request. New logins are pinned immediately.
                $request->session()->put(AdminSessionIdentity::SESSION_KEY, $expectedIdentity);
            } elseif (! hash_equals($expectedIdentity, $sessionIdentity)) {
                return $this->logout($request);
            }
        }

        abort_unless(
            $this->permissions->allows(
                (string) $user->role,
                $request->route()?->getName(),
                $request->method()
            ),
            403
        );

        return $next($request);
    }

    private function logout(Request $request)
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت جلسة لوحة التحكم',
            ], 401);
        }

        return redirect()->route('login');
    }
}
