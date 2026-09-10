<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class StudentWalletSession
{
    public function handle(Request $request, Closure $next, string $access = 'optional'): Response
    {
        $origin = rtrim((string) (config('social_auth.web_wallet_url') ?: config('app.url')), '/');
        // OAuth must return to the host which owns the browser cookie. This
        // also handles www and the Laravel origin without sharing cookies.
        if (rtrim($request->getSchemeAndHttpHost(), '/') !== $origin) {
            return redirect()->away($origin.($request->isMethod('GET') ? $request->getRequestUri() : '/recharge'), 303);
        }
        $user = $request->user('student');
        if ($user && (!$user->active || $user->trashed() || strtolower((string) $user->role) !== 'client')) {
            Auth::guard('student')->logout();
            $user = null;
        }

        $response = !$user && $access === 'required'
            ? ($request->expectsJson()
                ? response()->json(['message' => 'سجّل الدخول للمتابعة'], 401)
                : redirect()->route('web-wallet.index')->with('error', 'سجّل الدخول للمتابعة'))
            : $next($request);

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
