<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class SimpleApiAuth
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'Token مطلوب',
            ], 401);
        }

        $user = User::withTrashed()->where('api_token', $token)->first();

        if (! $user || $user->trashed() || ! (bool) $user->active) {
            if ($user) {
                User::withTrashed()->whereKey($user->id)->update(['api_token' => null]);
            }

            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'Token غير صحيح',
            ], 401);
        }

        // Set the authenticated user
        auth()->login($user);

        return $next($request);
    }
}
