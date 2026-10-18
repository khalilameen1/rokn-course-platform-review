<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AdminSessionIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    public function __construct(private readonly AdminSessionIdentity $sessionIdentity)
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('throttle:admin-login-route')->only('login');
    }

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'email';
    }

    /**
     * @return RedirectResponse|Redirector
     */
    protected function authenticated(Request $request, $user)
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        // login() has already rejected every non-dashboard identity.
        return redirect()->route('admin.dashboard');
    }

    /**
     * @return RedirectResponse|Redirector
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $credentials['active'] = true;
        $key = $this->loginRateLimitKey($credentials['email'], (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect()->back()
                ->withErrors(['email' => 'محاولات كثيرة. حاول مرة أخرى بعد قليل.'])
                ->onlyInput('email');
        }

        if (! auth()->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            // Keep the response identical for an unknown email and a wrong
            // password so the dashboard cannot be used to enumerate accounts.
            return redirect()->back()
                ->withErrors(['email' => 'بيانات الدخول غير صحيحة.'])
                ->onlyInput('email');
        }

        $role = strtolower(trim((string) auth()->user()?->role));
        if (! in_array($role, ['admin', 'moderator'], true)) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            RateLimiter::hit($key, 60);

            return redirect()->back()
                ->withErrors(['email' => 'بيانات الدخول غير صحيحة.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(
            AdminSessionIdentity::SESSION_KEY,
            $this->sessionIdentity->fingerprint(auth()->user())
        );

        // Password authentication is only the first factor for dashboard
        // roles. Never inherit a prior user's MFA state through a reused
        // browser session.
        $request->session()->forget([
            'admin_mfa_verified_user_id',
            'admin_mfa_verified_at',
            'admin_mfa_secret_fingerprint',
            'admin_mfa_setup_secret_ciphertext',
            'admin_mfa_setup_user_id',
            'admin_mfa_setup_started_at',
            'admin_mfa_new_recovery_codes_ciphertext',
        ]);

        return $this->authenticated($request, auth()->user());
    }

    private function loginRateLimitKey(string $email, string $ip): string
    {
        // This short lock is scoped to one credential source. The route-level
        // email limits still cap distributed attempts without allowing one
        // remote address to keep a known administrator locked out indefinitely.
        return 'admin-login:'.hash('sha256', Str::lower(trim($email)).'|'.trim($ip));
    }

    /**
     * The user has logged out of the application.
     *
     * @return mixed
     */
    protected function loggedOut(Request $request)
    {
        return redirect()->route('login');
    }
}
