<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    public function __construct()
    {
        $this->middleware('guest');
        $this->middleware('throttle:admin-password-reset')->only('sendResetLinkEmail');
    }

    public function sendResetLinkEmail(Request $request)
    {
        $this->validateEmail($request);
        $email = Str::lower(trim((string) $request->input('email')));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('active', true)
            ->whereRaw('LOWER(role) IN (?, ?)', ['admin', 'moderator'])
            ->first(['email', 'role']);

        try {
            $this->broker()->sendResetLink([
                'email' => $user?->email ?? $email,
                'role' => $user?->getRawOriginal('role') ?? '__dashboard_only__',
                'active' => true,
            ]);
        } catch (Throwable $exception) {
            // Password recovery must not expose a mail-provider outage or turn
            // it into a public 500. Operators still get a useful, non-PII key.
            Log::warning('Dashboard password reset notification could not be sent.', [
                'email_hash' => hash('sha256', $email),
                'exception' => $exception::class,
            ]);
        }

        // Unknown, learner, inactive, throttled, and eligible addresses receive
        // the same browser response. Delivery is the only observable outcome.
        return $this->sendResetLinkResponse($request, Password::RESET_LINK_SENT);
    }
}
