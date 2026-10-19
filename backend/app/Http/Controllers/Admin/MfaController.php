<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\TwoFactorAuthenticationProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminMfaCodeRequest;
use App\Models\User;
use App\Services\AdminMfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class MfaController extends Controller
{
    private const GENERIC_ERROR = 'The verification code is invalid or has already been used.';

    public function __construct(
        private readonly TwoFactorAuthenticationProvider $provider,
        private readonly AdminMfaService $mfa
    ) {}

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
            $secret = $this->provider->generateSecretKey();
            $request->session()->put([
                'admin_mfa_setup_secret_ciphertext' => Crypt::encryptString($secret),
                'admin_mfa_setup_user_id' => (int) $user->getAuthIdentifier(),
                'admin_mfa_setup_started_at' => time(),
            ]);
        }

        $issuer = trim((string) config('admin_security.issuer', 'Rokn')) ?: 'Rokn';

        return $this->noStoreView('admin.auth.mfa-setup', [
            'secret' => $secret,
            'otpauthUri' => $this->provider->qrCodeUrl($issuer, (string) $user->email, $secret),
        ]);
    }

    public function confirmSetup(AdminMfaCodeRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $submittedCode = $validated['code'];
        $user = $request->user();
        abort_unless($user, 403);

        $secret = $this->pendingSetupSecret($request);
        $startedAt = (int) $request->session()->get('admin_mfa_setup_started_at', 0);
        $belongsToUser = (int) $request->session()->get('admin_mfa_setup_user_id', 0)
            === (int) $user->getAuthIdentifier();
        $setupTtl = max(300, (int) config('admin_security.setup_ttl_minutes', 15) * 60);
        $recoveryCodes = ($belongsToUser && $secret !== '' && $startedAt > 0 && (time() - $startedAt) <= $setupTtl)
            ? $this->mfa->confirmEnrolment($user, $secret, $submittedCode)
            : null;

        if ($recoveryCodes === null) {
            return $this->invalidCodeResponse();
        }

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

    public function verifyChallenge(AdminMfaCodeRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        abort_unless($user, 403);

        $code = trim($validated['code']);
        if (! $this->mfa->verifyChallenge($user, $code)) {
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

    private function markVerified(Request $request, int $userId, string $secret): void
    {
        $request->session()->put([
            'admin_mfa_verified_user_id' => $userId,
            'admin_mfa_verified_at' => time(),
            'admin_mfa_secret_fingerprint' => $this->mfa->secretFingerprint($secret),
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
