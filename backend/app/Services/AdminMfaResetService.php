<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clears a staff account's authenticator enrolment so it must enrol again at
 * the next dashboard request.
 *
 * Mirrors Laravel Fortify's DisableTwoFactorAuthentication (secret, recovery
 * codes and confirmation are cleared together). The remember-me token is also
 * rotated so a lost device cannot resume a remembered session; the existing
 * RequireAdminMfa check already rejects sessions bound to the old secret.
 */
final class AdminMfaResetService
{
    /** @return bool Whether an enrolment existed before the reset. */
    public function reset(User $target): bool
    {
        return DB::transaction(function () use ($target): bool {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());
            if (!in_array(strtolower(trim((string) $locked->role)), ['admin', 'moderator'], true)) {
                throw new \DomainException('Only dashboard staff accounts have dashboard MFA.');
            }
            $hadEnrolment = $locked->admin_totp_confirmed_at !== null
                || trim((string) $locked->getRawOriginal('admin_totp_secret')) !== '';

            $locked->forceFill([
                'admin_totp_secret' => null,
                'admin_totp_confirmed_at' => null,
                'admin_totp_last_used_step' => null,
                'admin_mfa_backup_codes' => null,
                'remember_token' => Str::random(60),
            ])->save();

            return $hadEnrolment;
        }, 3);
    }
}
