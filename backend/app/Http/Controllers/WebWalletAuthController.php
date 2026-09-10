<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAuthProviderRegistry;
use App\Services\SocialOAuthAttemptService;
use App\Services\SocialIdentityGuardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class WebWalletAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthProviderRegistry $providers,
        private readonly SocialOAuthAttemptService $attempts
    ) {}

    public function start(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->providers->browserAvailable()->contains($provider), 404);
        $verifier = Str::random(64);
        $request->session()->put('wallet.oauth_verifier', $verifier);

        return redirect()->away($this->providers->browserStartUrl($provider).'?'.http_build_query([
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
            'return_to' => $this->providers->webWalletReturnUrl(),
        ]));
    }

    public function complete(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'code' => 'nullable|string|min:32|max:200',
            'error' => 'nullable|string|max:100',
        ]);
        $verifier = (string) $request->session()->get('wallet.oauth_verifier', '');
        $attempt = isset($input['code']) ? $this->attempts->inspectCompletion($input['code']) : null;
        if (!$attempt || $verifier === ''
            || $attempt->return_to !== $this->providers->webWalletReturnUrl()
            || !$this->providers->browserAvailable()->contains($attempt->provider)
            || !hash_equals((string) $attempt->code_challenge, $this->challenge($verifier))) {
            return $this->failed('لم يكتمل تسجيل الدخول حاول مرة أخرى');
        }
        if ($attempt->completion_consumed_at) {
            // A repeated redirect can replay only this browser's PKCE-bound
            // identity. A lost session cookie requires a fresh sign-in.
            try {
                $receipt = json_decode(Crypt::decryptString((string) $attempt->encrypted_session_response), true, 8, JSON_THROW_ON_ERROR);
                $user = User::query()->whereKey($receipt['web_wallet_user_id'] ?? 0)
                    ->where('active', true)->students()->first();
                return $user ? $this->login($request, $user) : $this->failed('انتهت محاولة الدخول حاول مرة أخرى');
            } catch (\Throwable $exception) {
                report($exception);
                return $this->failed('انتهت محاولة الدخول حاول مرة أخرى');
            }
        }

        $claimed = $this->attempts->claimCompletion($input['code']);
        if (!$claimed) {
            return $this->failed('تسجيل الدخول قيد الإكمال حاول مرة أخرى');
        }

        try {
            $identity = $this->providers->verifyIdentity(
                (string) $claimed->provider,
                Crypt::decryptString((string) $claimed->encrypted_token),
                $claimed->nonce_hash
            );
            // Top-up never creates or links accounts. Use the exact provider
            // identity already linked by the app, not a matching email address.
            $user = $this->attempts->whileCompletionClaimIsOwned(
                $claimed->id,
                (string) $claimed->completion_claim_id,
                function () use ($claimed, $identity): ?User {
                    app(SocialIdentityGuardService::class)->assertLoginStartedAfterLastDeletion(
                        (string) $claimed->provider, $identity['id'], $claimed->created_at
                    );
                    $account = SocialAccount::query()
                        ->where('provider', $claimed->provider)
                        ->where('provider_user_id', $identity['id'])
                        ->first();
                    $user = $account ? User::query()->whereKey($account->user_id)
                        ->where('active', true)->students()->lockForUpdate()->first() : null;
                    $receipt = $user ? Crypt::encryptString(json_encode([
                        'web_wallet_user_id' => (int) $user->id,
                    ], JSON_THROW_ON_ERROR)) : null;
                    if (!$this->attempts->finalizeCompletion($claimed, $receipt)) {
                        return null;
                    }
                    return $user;
                }
            );
            if (!$user) {
                return $this->failed('استخدم نفس حساب الدخول المرتبط بتطبيق ركن');
            }

            return $this->login($request, $user);
        } catch (\DomainException $exception) {
            $this->attempts->finalizeCompletion($claimed);
            return $this->failed('انتهت محاولة الدخول حاول مرة أخرى');
        } catch (\Throwable $exception) {
            report($exception);
            $this->attempts->releaseCompletion($claimed);
            return $this->failed('تعذّر تسجيل الدخول حاول مرة أخرى');
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('student')->logout();
        $request->session()->forget('wallet');
        $request->session()->regenerate(true);
        return redirect()->route('web-wallet.index');
    }

    private function login(Request $request, User $user): RedirectResponse
    {
        Auth::guard('student')->login($user);
        $request->session()->forget('wallet.intent');
        $request->session()->regenerateToken();
        return redirect()->route('web-wallet.index');
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('web-wallet.index')->with('error', $message);
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
