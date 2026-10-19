<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\TwoFactorAuthenticationProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns a staff account's authenticator enrolment and one-time code use.
 *
 * Follows Laravel Fortify's ConfirmTwoFactorAuthentication and
 * TwoFactorAuthenticatedSessionController: the secret is stored only after a
 * valid code, recovery codes are single use, and a TOTP step is accepted once.
 * Each write locks the user row so concurrent submissions cannot replay a code.
 * Code arithmetic is TwoFactorAuthenticationProvider's; the keyed hashes that
 * bind stored recovery codes and verified sessions to this app live here.
 * Resetting an enrolment stays with AdminMfaResetService.
 */
final readonly class AdminMfaService
{
    public function __construct(private TwoFactorAuthenticationProvider $provider) {}

    /**
     * Confirm a pending enrolment with a code from the authenticator.
     *
     * @return list<string>|null plain recovery codes to show once, or null when the code does not match
     */
    public function confirmEnrolment(User $user, string $secret, string $code): ?array
    {
        $step = $this->provider->verify($secret, $code);
        if ($step === null) {
            return null;
        }

        $recoveryCodes = $this->provider->generateRecoveryCodes();
        DB::transaction(function () use ($user, $secret, $step, $recoveryCodes): void {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getAuthIdentifier());
            abort_if($lockedUser->admin_totp_confirmed_at !== null, 409, 'MFA is already configured.');

            $lockedUser->forceFill([
                'admin_totp_secret' => $secret,
                'admin_totp_confirmed_at' => now(),
                'admin_totp_last_used_step' => $step,
                'admin_mfa_backup_codes' => array_map(
                    fn (string $code): string => $this->hashRecoveryCode($code),
                    $recoveryCodes
                ),
            ])->save();
        });

        return $recoveryCodes;
    }

    /** Accept a six-digit TOTP code or an unused recovery code exactly once. */
    public function verifyChallenge(User $user, string $code): bool
    {
        return preg_match('/^[0-9]{6}$/D', $code) === 1
            ? $this->consumeTotp($user, $code)
            : $this->consumeRecoveryCode($user, $code);
    }

    /** Keyed hash of a recovery code as stored in admin_mfa_backup_codes. */
    public function hashRecoveryCode(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($code)) ?? '');

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /** Binds a verified dashboard session to the secret it was verified with. */
    public function secretFingerprint(string $secret): string
    {
        return hash_hmac('sha256', 'admin-mfa:'.$secret, (string) config('app.key'));
    }

    private function consumeTotp(User $user, string $code): bool
    {
        try {
            $secret = trim((string) $user->admin_totp_secret);
            $step = $secret === '' ? null : $this->provider->verify($secret, $code);
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }

        if ($step === null) {
            return false;
        }

        try {
            return DB::transaction(function () use ($user, $secret, $step): bool {
                /** @var User|null $lockedUser */
                $lockedUser = User::query()->lockForUpdate()->find($user->getAuthIdentifier());
                if (! $lockedUser || ! hash_equals($secret, (string) $lockedUser->admin_totp_secret)) {
                    return false;
                }

                if ($lockedUser->admin_totp_last_used_step !== null
                    && $step <= (int) $lockedUser->admin_totp_last_used_step) {
                    return false;
                }

                $lockedUser->forceFill(['admin_totp_last_used_step' => $step])->save();

                return true;
            });
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        if (preg_match('/^[A-Za-z0-9-]{8,20}$/D', $code) !== 1) {
            return false;
        }

        $candidate = $this->hashRecoveryCode($code);

        return DB::transaction(function () use ($user, $candidate): bool {
            /** @var User|null $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->find($user->getAuthIdentifier());
            if (! $lockedUser) {
                return false;
            }

            $hashes = array_values(array_filter(
                is_array($lockedUser->admin_mfa_backup_codes) ? $lockedUser->admin_mfa_backup_codes : [],
                'is_string'
            ));
            foreach ($hashes as $index => $hash) {
                if (hash_equals($hash, $candidate)) {
                    unset($hashes[$index]);
                    $lockedUser->forceFill(['admin_mfa_backup_codes' => array_values($hashes)])->save();

                    return true;
                }
            }

            return false;
        });
    }
}
