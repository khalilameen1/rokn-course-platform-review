<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SocialAuthProviderRegistry;
use App\Services\WebWalletSignInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class WebWalletAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthProviderRegistry $providers,
        private readonly WebWalletSignInService $signIn
    ) {}

    public function start(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->providers->browserAvailable()->contains($provider), 404);
        $verifier = Str::random(64);
        $request->session()->put('wallet.oauth_verifier', $verifier);

        return redirect()->away($this->providers->browserStartUrl($provider).'?'.http_build_query([
            'code_challenge' => $this->signIn->challenge($verifier),
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
        $result = $this->signIn->complete(
            $input['code'] ?? null,
            (string) $request->session()->get('wallet.oauth_verifier', '')
        );
        if ($result instanceof User) {
            return $this->login($request, $result);
        }

        return $this->failed(match ($result) {
            WebWalletSignInService::FAILED_ATTEMPT => 'لم يكتمل تسجيل الدخول حاول مرة أخرى',
            WebWalletSignInService::IN_PROGRESS => 'تسجيل الدخول قيد الإكمال حاول مرة أخرى',
            WebWalletSignInService::NOT_LINKED => 'استخدم نفس حساب الدخول المرتبط بتطبيق ركن',
            WebWalletSignInService::UNAVAILABLE => 'تعذّر تسجيل الدخول حاول مرة أخرى',
            default => 'انتهت محاولة الدخول حاول مرة أخرى',
        });
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
}
