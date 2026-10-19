<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AdminSessionIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The same identity boundary for the classic dashboard and Filament/Livewire. */
final readonly class VerifyDashboardSession
{
    public function __construct(private AdminSessionIdentity $identity) {}

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            return redirect()->guest(route('login'));
        }
        $expected = $this->identity->fingerprint($user);
        $pinned = (string) $request->session()->get(AdminSessionIdentity::SESSION_KEY, '');
        if (! $user->active || ($pinned !== '' && ! hash_equals($expected, $pinned))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'انتهت جلسة لوحة التحكم'], 401)
                : redirect()->route('login');
        }
        $request->session()->put(AdminSessionIdentity::SESSION_KEY, $expected);

        return $next($request);
    }
}
