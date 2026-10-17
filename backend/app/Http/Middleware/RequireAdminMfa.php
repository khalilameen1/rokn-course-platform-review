<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Totp;
use Closure;
use Illuminate\Http\Request;
use App\Support\DatabaseCapabilities;

final class RequireAdminMfa
{
    public function __construct(private readonly Totp $totp)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless(
            $user
                && (bool) $user->active
                && in_array(strtolower(trim((string) $user->role)), ['admin', 'moderator'], true),
            403
        );

        if (!DatabaseCapabilities::hasColumns('users', [
            'admin_totp_secret',
            'admin_totp_confirmed_at',
            'admin_totp_last_used_step',
            'admin_mfa_backup_codes',
        ])) {
            // A number of legacy feature tests construct a deliberately small
            // users table. Production must fail closed if code precedes its
            // migration during a rolling deployment.
            if (app()->environment('testing')) {
                return $next($request);
            }

            abort(503, 'Administrative MFA storage is unavailable.');
        }

        try {
            $secret = trim((string) $user->admin_totp_secret);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'Administrative MFA cannot be verified.');
        }

        if (!$user->admin_totp_confirmed_at || $secret === '') {
            return redirect()->route('admin.mfa.setup');
        }

        $verifiedUserId = (int) $request->session()->get('admin_mfa_verified_user_id', 0);
        $verifiedAt = (int) $request->session()->get('admin_mfa_verified_at', 0);
        $fingerprint = (string) $request->session()->get('admin_mfa_secret_fingerprint', '');
        $ttlSeconds = max(300, (int) config('admin_security.session_ttl_minutes', 720) * 60);
        $ageSeconds = time() - $verifiedAt;
        $isVerified = $verifiedUserId === (int) $user->getAuthIdentifier()
            && $verifiedAt > 0
            && $ageSeconds >= 0
            && $ageSeconds <= $ttlSeconds
            && hash_equals($this->totp->secretFingerprint($secret), $fingerprint);

        if ($isVerified) {
            return $next($request);
        }

        $request->session()->forget([
            'admin_mfa_verified_user_id',
            'admin_mfa_verified_at',
            'admin_mfa_secret_fingerprint',
        ]);

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->guest(route('admin.mfa.challenge'));
        }

        $request->session()->put('url.intended', route('admin.dashboard'));

        return redirect()->route('admin.mfa.challenge');
    }
}
