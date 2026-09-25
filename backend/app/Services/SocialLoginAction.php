<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\ClientDeviceContext;
use App\Auth\SocialLoginCredentials;
use App\Auth\SocialLoginResult;
use App\Auth\VerifiedSocialIdentity;
use App\Exceptions\SocialProviderUnavailableException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Verify, bind and issue one native login; transport and response rendering stay outside. */
final class SocialLoginAction
{
    public function __construct(
        private readonly SocialAuthProviderRegistry $providers,
        private readonly SocialAccountBindingService $accountBinding,
        private readonly NativeLoginSessionService $nativeSessions,
        private readonly SocialOAuthAttemptService $attempts,
        private readonly PushDeviceRegistrationService $pushDevices,
        private readonly WelcomeRewardService $welcomeRewards
    ) {}

    public function login(
        #[\SensitiveParameter] SocialLoginCredentials $credentials,
        ClientDeviceContext $device,
        ?string $preferredLocale = null
    ): SocialLoginResult {
        $provider = $credentials->provider;
        if ($this->providers->browserDeclared()->contains($provider)
            && !$credentials->isBrowserAttempt()) {
            return SocialLoginResult::rejected(
                422, 'social_browser_attempt_required',
                "ابدأ تسجيل الدخول من جديد\nثم أكمله من المتصفح"
            );
        }

        try {
            $socialData = $this->providers->verifyIdentity(
                $provider,
                $credentials->credential,
                $credentials->expectedNonceHash,
                $credentials->appleNonce,
                $credentials->appleAuthorizationCode
            );
        } catch (\Throwable $exception) {
            Log::warning('Social identity verification failed', [
                'provider' => $provider,
                'exception' => get_class($exception),
            ]);
            $unavailable = $this->isTransientSocialProviderFailure($exception);
            return SocialLoginResult::rejected(
                $unavailable ? 503 : 422,
                $unavailable ? 'social_provider_unavailable' : 'social_identity_verification_failed',
                $unavailable
                    ? "خدمة تسجيل الدخول غير متاحة للحظات\nحاول مرة أخرى"
                    : "تعذّر التحقق من الحساب\nابدأ تسجيل الدخول مرة أخرى"
            );
        }

        $attemptStartedAt = $credentials->attemptStartedAt;
        if (!$attemptStartedAt instanceof CarbonInterface) {
            $issuedAt = $socialData['identity_issued_at'] ?? null;
            if (!is_numeric($issuedAt) || (int) $issuedAt <= 0) {
                return SocialLoginResult::rejected(
                    410, 'social_login_fresh_attempt_required',
                    "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى"
                );
            }
            $attemptStartedAt = CarbonImmutable::createFromTimestampUTC((int) $issuedAt);
            if ($attemptStartedAt->isAfter(now()->addMinutes(5))) {
                return SocialLoginResult::rejected(
                    422, 'social_identity_verification_failed', 'تعذّر التحقق من هوية الحساب'
                );
            }
            if ($attemptStartedAt->isBefore(now()->subMinutes(10))) {
                return SocialLoginResult::rejected(
                    410, 'social_login_fresh_attempt_required',
                    "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى"
                );
            }
        }

        $identity = VerifiedSocialIdentity::fromProvider(
            $provider, $socialData, $credentials->displayName
        );
        try {
            $user = $this->accountBinding->bind($identity, $attemptStartedAt, $preferredLocale);
        } catch (QueryException $exception) {
            report($exception);
            return SocialLoginResult::rejected(
                503, 'social_login_unavailable',
                "تعذّر إكمال تسجيل الدخول\nحاول مرة أخرى"
            );
        } catch (\DomainException $exception) {
            report($exception);
            return match ($exception->getMessage()) {
                'social_login_predates_account_deletion' => SocialLoginResult::rejected(
                    410, 'social_login_expired', "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى"
                ),
                'social_account_disabled' => SocialLoginResult::rejected(
                    403, 'account_disabled', "حسابك غير مفعّل\nتواصل مع الدعم"
                ),
                default => SocialLoginResult::rejected(
                    409, 'social_account_conflict',
                    "هذا الحساب مرتبط بهوية أخرى\nتواصل مع الدعم إذا استمرت المشكلة"
                ),
            };
        }

        if ($user->trashed() || !$user->active) {
            return SocialLoginResult::rejected(
                403, 'account_disabled', "حسابك غير مفعّل\nتواصل مع الدعم"
            );
        }

        $issueDeviceSession = fn (): array => $this->nativeSessions->issueWithinTransaction(
            userId: (int) $user->id,
            provider: $identity->provider,
            providerUserId: $identity->providerUserId,
            metadata: $device->sessionMetadata()
        );

        if ($credentials->isBrowserAttempt()) {
            $deviceSession = $this->attempts->whileCompletionClaimIsOwned(
                $credentials->attemptId,
                $credentials->completionClaimId,
                $issueDeviceSession
            );
            if ($deviceSession === null) {
                return SocialLoginResult::rejected(
                    409, 'social_login_in_progress',
                    "جارٍ إكمال تسجيل الدخول\nحاول بعد قليل"
                );
            }
        } else {
            $deviceSession = DB::transaction($issueDeviceSession, 3);
        }

        $deviceAccess = $deviceSession['access'];
        if (!$deviceAccess['allowed']) {
            return SocialLoginResult::rejected(
                403, $deviceAccess['code'] ?? 'device_login_denied', $deviceAccess['message']
            );
        }

        // These are recoverable post-login operations, not identity/session prerequisites.
        try {
            $this->pushDevices->register((int) $user->id, $device);
        } catch (\Throwable $exception) {
            report($exception);
        }
        $welcomeBonusGranted = 0;
        try {
            // Ledger-idempotent: retry on every login after a prior temporary outage.
            $welcomeBonusGranted = $this->welcomeRewards->grant($user, $provider);
        } catch (\Throwable $exception) {
            report($exception);
        }
        $user->refresh();

        return SocialLoginResult::authenticated(
            user: $user,
            provider: $provider,
            apiToken: (string) $deviceSession['api_token'],
            deviceToken: $device->deviceToken ?? $user->deviceTokens()->latest()->value('device_token'),
            welcomeBonusGranted: $welcomeBonusGranted
        );
    }

    private function isTransientSocialProviderFailure(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (
                $current instanceof SocialProviderUnavailableException
                || $current instanceof \Illuminate\Http\Client\ConnectionException
                || $current instanceof \GuzzleHttp\Exception\ConnectException
                || $current instanceof \GuzzleHttp\Exception\ServerException
            ) {
                return true;
            }

            if ($current instanceof \Illuminate\Http\Client\RequestException) {
                $status = $current->response->status();
                if ($status === 429 || $status >= 500) {
                    return true;
                }
            }

            if (
                $current instanceof \GuzzleHttp\Exception\RequestException
                && (!$current->hasResponse() || ($current->getResponse()?->getStatusCode() ?? 0) >= 500)
            ) {
                return true;
            }
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'not configured')
            || str_contains($message, 'public-keys response')
            || str_contains($message, 'no matching apple signing key')
            || str_contains($message, 'graph api version');
    }
}
