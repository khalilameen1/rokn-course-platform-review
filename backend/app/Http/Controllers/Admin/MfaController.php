<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Totp;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    private const GENERIC_ERROR = 'The verification code is invalid or has already been used.';

    private readonly Totp $totp;

    public function __construct(Totp $totp)
    {
        $this->totp = $totp;
    }

    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($user->admin_totp_confirmed_at && $this->confirmedSecret($user) !== '') {
            return redirect()->route('admin.mfa.challenge');
        }

        $startedAt = (int) $request->session()->get('admin_mfa_setup_started_at', 0);
        $setupTtl = max(300, (int) config('admin_security.setup_ttl_minutes', 15) * 60);
        $secret = $this->pendingSetupSecret($request);
        $belongsToUser = (int) $request->session()->get('admin_mfa_setup_user_id', 0)
            === (int) $user->getAuthIdentifier();

        if (! $belongsToUser || $secret === '' || $startedAt <= 0 || (time() - $startedAt) > $setupTtl) {
            $secret = $this->totp->generateSecret();
            $request->session()->put([
                'admin_mfa_setup_secret_ciphertext' => Crypt::encryptString($secret),
                'admin_mfa_setup_user_id' => (int) $user->getAuthIdentifier(),
                'admin_mfa_setup_started_at' => time(),
            ]);
        }

        return $this->noStoreView('admin.auth.mfa-setup', [
            'secret' => $secret,
            'otpauthUri' => $this->totp->otpauthUri($secret, (string) $user->email),
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            ]);
        } catch (ValidationException $exception) {
            $request->request->remove('code');
            throw $exception;
        }
        $submittedCode = $validated['code'];
        $request->request->remove('code');
        $user = $request->user();
        abort_unless($user, 403);

        $secret = $this->pendingSetupSecret($request);
        $startedAt = (int) $request->session()->get('admin_mfa_setup_started_at', 0);
        $belongsToUser = (int) $request->session()->get('admin_mfa_setup_user_id', 0)
            === (int) $user->getAuthIdentifier();
        $setupTtl = max(300, (int) config('admin_security.setup_ttl_minutes', 15) * 60);
        $step = ($belongsToUser && $secret !== '' && $startedAt > 0 && (time() - $startedAt) <= $setupTtl)
            ? $this->totp->matchingStep($secret, $submittedCode)
            : null;

        if ($step === null) {
            return $this->invalidCodeResponse();
        }

        $recoveryCodes = $this->totp->generateRecoveryCodes();
        DB::transaction(function () use ($user, $secret, $step, $recoveryCodes): void {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getAuthIdentifier());
            abort_if($lockedUser->admin_totp_confirmed_at, 409, 'MFA is already configured.');

            $lockedUser->forceFill([
                'admin_totp_secret' => $secret,
                'admin_totp_confirmed_at' => now(),
                'admin_totp_last_used_step' => $step,
                'admin_mfa_backup_codes' => array_map(
                    fn (string $code): string => $this->totp->hashRecoveryCode($code),
                    $recoveryCodes
                ),
            ])->save();
        });

        $request->session()->regenerate(true);
        $this->markVerified($request, (int) $user->getAuthIdentifier(), $secret);
        $request->session()->forget([
            'admin_mfa_setup_secret_ciphertext',
            'admin_mfa_setup_user_id',
            'admin_mfa_setup_started_at',
        ]);
        // The next page consumes the encrypted recovery-code payload once.
        $request->session()->put(
            'admin_mfa_new_recovery_codes_ciphertext',
            Crypt::encryptString(json_encode($recoveryCodes, JSON_THROW_ON_ERROR))
        );

        return redirect()->route('admin.mfa.backup-codes')
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (! $user->admin_totp_confirmed_at || $this->confirmedSecret($user) === '') {
            return redirect()->route('admin.mfa.setup');
        }

        return $this->noStoreView('admin.auth.mfa-challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'code' => ['required', 'string', 'min:6', 'max:32'],
            ]);
        } catch (ValidationException $exception) {
            $request->request->remove('code');
            throw $exception;
        }
        $user = $request->user();
        abort_unless($user, 403);

        $code = trim($validated['code']);
        $request->request->remove('code');
        $verified = preg_match('/^[0-9]{6}$/D', $code) === 1
            ? $this->consumeTotp($user, $code)
            : $this->consumeRecoveryCode($user, $code);

        if (! $verified) {
            return $this->invalidCodeResponse();
        }

        $freshUser = $user->fresh();
        abort_unless($freshUser, 403);
        $secret = $this->confirmedSecret($freshUser);
        abort_if($secret === '', 503, 'Administrative MFA cannot be verified.');

        $request->session()->regenerate(true);
        $this->markVerified($request, (int) $user->getAuthIdentifier(), $secret);

        return redirect()->intended(route('admin.dashboard'))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function backupCodes(Request $request): Response|RedirectResponse
    {
        $ciphertext = (string) $request->session()->pull('admin_mfa_new_recovery_codes_ciphertext', '');
        try {
            $codes = $ciphertext === ''
                ? null
                : json_decode(Crypt::decryptString($ciphertext), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $codes = null;
        }
        if (! is_array($codes) || $codes === [] || array_filter($codes, 'is_string') !== $codes) {
            return redirect()->route('admin.dashboard');
        }

        return $this->noStoreView('admin.auth.mfa-backup-codes', ['codes' => $codes]);
    }

    private function consumeTotp(User $user, string $code): bool
    {
        try {
            $secret = trim((string) $user->admin_totp_secret);
            $step = $secret === '' ? null : $this->totp->matchingStep($secret, $code);
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

        $candidate = $this->totp->hashRecoveryCode($code);

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

    private function markVerified(Request $request, int $userId, string $secret): void
    {
        $request->session()->put([
            'admin_mfa_verified_user_id' => $userId,
            'admin_mfa_verified_at' => time(),
            'admin_mfa_secret_fingerprint' => $this->totp->secretFingerprint($secret),
        ]);
        if (Schema::hasColumn('users', 'last_dashboard_login_at')) {
            User::query()->whereKey($userId)->update(['last_dashboard_login_at' => now()]);
        }
    }

    private function pendingSetupSecret(Request $request): string
    {
        $ciphertext = (string) $request->session()->get('admin_mfa_setup_secret_ciphertext', '');
        if ($ciphertext === '') {
            return '';
        }

        try {
            return Crypt::decryptString($ciphertext);
        } catch (\Throwable) {
            $request->session()->forget('admin_mfa_setup_secret_ciphertext');

            return '';
        }
    }

    private function confirmedSecret(User $user): string
    {
        try {
            return trim((string) $user->admin_totp_secret);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'Administrative MFA cannot be verified.');
        }
    }

    private function invalidCodeResponse(): RedirectResponse
    {
        return redirect()->back()
            ->withErrors(['code' => self::GENERIC_ERROR])
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /** @param array<string, mixed> $data */
    private function noStoreView(string $view, array $data = []): Response
    {
        return response()
            ->view($view, $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
