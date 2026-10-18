<?php

namespace App\Http\Controllers\API;

use App\Auth\SocialLoginCredentials;
use App\Exceptions\SocialProviderUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\ClientDeviceInput;
use App\Http\Resources\StudentProfileResource;
use App\Http\Responses\SocialLoginResponse;
use App\Models\ApiToken;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\ApiResponseService;
use App\Services\DeviceLoginService;
use App\Services\PushDeviceRegistrationService;
use App\Services\SocialAuthProviderRegistry;
use App\Services\SocialLoginAction;
use App\Services\WelcomeRewardOfferService;
use App\Support\RoknLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SignController extends Controller
{
    public function __construct(
        private readonly SocialAuthProviderRegistry $socialProviders,
        private readonly SocialLoginAction $login,
        private readonly PushDeviceRegistrationService $pushDevices,
        private readonly WelcomeRewardOfferService $welcomeOffer,
        private readonly ApiResponseService $responses,
        private readonly DeviceLoginService $devices,
        private readonly AccountDeletionService $accountDeletion
    ) {}

    /**
     * Social Login with provider-side token verification.
     *
     * @return JsonResponse
     */
    public function socialLogin(Request $request)
    {
        $nonceRules = $request->input('provider') === 'apple'
            ? ['bail', 'required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/']
            : ['nullable', 'string', 'max:255'];

        $validated = $request->validate([
            'provider' => [
                'required',
                'string',
                // Discovery and token exchange must use the same readiness
                // decision. A declared but incomplete provider is not a login
                // method and must never reach a verifier with half a config.
                Rule::in($this->socialProviders->available()->all()),
            ],
            'token' => 'required|string|max:10000',
            'provider_name' => 'nullable|string|max:255',
            'nonce' => $nonceRules,
            'authorization_code' => $request->input('provider') === 'apple'
                ? 'required|string|max:4096' : 'nullable|string|max:4096',
            ...ClientDeviceInput::rules(),
            'preferred_locale' => 'nullable|string|in:ar,en',
        ]);

        $localeInput = $validated['preferred_locale']
            ?? ($request->hasHeader('Accept-Language') ? $request->header('Accept-Language') : null);
        $preferredLocale = $localeInput === null
            ? null
            : (RoknLocale::normalize($localeInput) ?? RoknLocale::fromRequest($request));

        return SocialLoginResponse::make($this->login->login(
            SocialLoginCredentials::native(
                provider: $validated['provider'],
                credential: $validated['token'],
                displayName: $validated['provider_name'] ?? null,
                appleNonce: $validated['nonce'] ?? null,
                appleAuthorizationCode: $validated['authorization_code'] ?? null
            ),
            ClientDeviceInput::fromValidated($request, $validated),
            $preferredLocale
        ), $request);
    }

    public function authMethods()
    {
        $providers = $this->socialProviders->available();
        $settings = null;
        $welcomeBonus = 0;
        try {
            $discovery = Cache::remember('auth-methods:dynamic:v2', 60, function (): array {
                $settings = Setting::query()->first();

                return ['settings' => $settings];
            });
            $settings = $discovery['settings'];
        } catch (\Throwable $exception) {
            // Provider discovery must stay usable during a rolling migration
            // of optional reward/settings tables. The login transaction still
            // fails normally if the core identity schema itself is unavailable.
            report($exception);
            try {
                $settings = Setting::query()->first();
            } catch (\Throwable $databaseException) {
                report($databaseException);
            }
        }

        $publicApiUrl = $this->socialProviders->publicApiUrl();
        $preferredProvider = strtolower(trim((string) ($settings?->recommended_social_provider
            ?: config('social_auth.recommended_provider', 'google'))));
        $recommendedProvider = $providers->contains($preferredProvider)
            ? $preferredProvider
            : $providers->first();
        try {
            // Read the same rule/settings as registration credit. An independent
            // offer cache made discovery lag behind dashboard changes.
            $welcomeBonus = max(0, $this->welcomeOffer->amountForProvider());
            $recommendedTotal = $recommendedProvider
                ? max(0, $this->welcomeOffer->amountForProvider($recommendedProvider))
                : 0;
        } catch (\Throwable $exception) {
            // Login discovery is core availability. Rewards are optional copy
            // and may be omitted while their ledger/settings tables recover.
            report($exception);
            $welcomeBonus = 0;
            $recommendedTotal = 0;
        }
        $providerBonus = max(0, $recommendedTotal - $welcomeBonus);
        if ($recommendedProvider && $recommendedTotal === 0) {
            // Do not put a generic welcome promise beside the primary login
            // method when that provider's indivisible offer cannot be paid.
            $welcomeBonus = 0;
            $providerBonus = 0;
        }
        $badgeAr = trim((string) ($settings?->recommended_provider_badge_ar ?? ''));
        $badgeEn = trim((string) ($settings?->recommended_provider_badge_en ?? ''));

        return $this->responses->success([
            'providers' => $providers,
            'authorization_api_url' => $publicApiUrl,
            'authorization_urls' => $this->socialProviders->browserAvailable()
                ->mapWithKeys(fn (string $provider) => [
                    $provider => $this->socialProviders->browserStartUrl($provider),
                ]),
            'native_only_providers' => $this->socialProviders->nativeOnlyAvailable(),
            'otp_enabled' => false,
            'password_login_visible' => false,
            'welcome_bonus_coins' => max(0, $welcomeBonus),
            'recommended_provider_bonus_coins' => $providerBonus,
            'recommended_provider_total_coins' => $recommendedTotal,
            'recommended_provider' => $recommendedProvider,
            'recommendation_badge' => $this->recommendationBadge(
                $recommendedProvider, $recommendedTotal, $badgeAr,
                (string) config('social_auth.recommended_provider_badge_ar')
            ),
            'recommendation_badge_en' => $this->recommendationBadge(
                $recommendedProvider, $recommendedTotal, $badgeEn,
                (string) config('social_auth.recommended_provider_badge_en')
            ),
        ], 'تم تحميل طرق الدخول');
    }

    private function recommendationBadge(?string $provider, int $coins, string $authored, string $fallback): ?string
    {
        if (! $provider) {
            return null;
        }
        if ($authored === '') {
            return $fallback;
        }
        // A preference badge is independent of a reward. Only explicit reward
        // templates require an offer the canonical ledger can actually credit.
        if ($coins <= 0 && str_contains($authored, '{coins}')) {
            return null;
        }

        return str_replace('{coins}', (string) $coins, $authored);
    }

    /**
     * Logout user
     *
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        $validated = $request->validate([
            'device_token' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user();
        /** @var ApiToken|null $currentToken */
        $currentToken = $request->attributes->get('rokn_api_token');
        $this->devices->signOutCurrentSession($user, $currentToken, $validated['device_token'] ?? null);

        return $this->responses->success(null, 'تم تسجيل الخروج بنجاح');
    }

    /**
     * Delete user account
     *
     * @return JsonResponse
     */
    public function deleteAccount(Request $request)
    {
        $user = auth('api')->user();

        if (! $this->hasFreshSocialReauthentication($request, $user)) {
            return $this->responses->error(
                "أكد هويتك من جديد\nاستخدم حساب تسجيل الدخول نفسه",
                403,
                null,
                ['code' => 'social_reauthentication_required']
            );
        }

        try {
            $cleanup = $this->accountDeletion->delete($user);
        } catch (SocialProviderUnavailableException) {
            return $this->responses->error(
                "تعذّر إلغاء تفويض Apple الآن\nلم نحذف حسابك، حاول مرة أخرى",
                503,
                null,
                ['code' => 'apple_revocation_unavailable']
            );
        }

        $cleanupPending = $cleanup['local_cleanup_pending'] || $cleanup['remote_portfolio_cleanup_pending'];
        if ($cleanupPending) {
            Log::notice('Deleted account has deferred file cleanup.', [
                'deleted_user_id' => $user->id,
                'local_cleanup_pending' => $cleanup['local_cleanup_pending'],
                'remote_portfolio_cleanup_pending' => $cleanup['remote_portfolio_cleanup_pending'],
            ]);
        }

        return $this->responses->success(
            null,
            $cleanupPending
                ? "تم تعطيل الحساب ومسح بياناته من التطبيق\nنستكمل حذف الملفات من التخزين"
                : 'تم حذف الحساب وبياناته الشخصية بنجاح',
            $cleanupPending ? 202 : 200,
            ['deletion_status' => $cleanupPending ? 'cleanup_pending' : 'completed']
        );
    }

    private function hasFreshSocialReauthentication(Request $request, User $user): bool
    {
        $plainToken = trim((string) ($request->bearerToken() ?: ''));
        if ($plainToken === '') {
            return false;
        }

        $token = ApiToken::query()
            ->where('user_id', $user->id)
            ->where('token', hash('sha256', $plainToken))
            ->whereHasNotExpired()
            ->first();
        $issuedAt = $token?->issued_at;
        $window = max(60, min(600, (int) config('social_auth.account_deletion_reauth_seconds', 300)));
        if (! $issuedAt || $issuedAt->isBefore(now()->subSeconds($window)) || $issuedAt->isFuture()) {
            return false;
        }

        // The presented bearer must be minted in the same short window as a
        // provider verification for this exact user. Linked identities belong
        // to social_accounts; the provider used for this session belongs to
        // the bearer, never to the mutable user profile.
        $provider = strtolower(trim((string) $token->auth_provider));
        $providerUserId = trim((string) $token->auth_provider_user_id);
        if ($provider === '' || $providerUserId === '') {
            return false;
        }

        return SocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->where('last_verified_at', '>=', now()->subSeconds($window))
            ->where('last_verified_at', '<=', $issuedAt->copy()->addSeconds(5))
            ->exists();
    }

    /**
     * Standalone endpoint to save or refresh device token
     *
     * @return JsonResponse
     */
    public function updateDeviceToken(Request $request)
    {
        $validated = $request->validate(ClientDeviceInput::rules(requirePushToken: true));

        $user = $request->user();

        /** @var ApiToken|null $currentToken */
        $currentToken = $request->attributes->get('rokn_api_token');
        $currentDeviceId = trim((string) $currentToken?->device_id);
        // The authenticated bearer owns the installation, never a stale payload.
        // Registration/rotation does not alter the learner's notification consent.
        $this->pushDevices->register(
            (int) $user->id,
            ClientDeviceInput::fromValidated($request, $validated, $currentDeviceId)
        );
        $user->refresh();

        return $this->responses->success([
            'device_token' => $request->input('device_token'),
            'user' => (new StudentProfileResource($user))->withoutLearningSnapshot(),
        ], 'تم حفظ رمز التنبيهات بنجاح');
    }

    /**
     * Remove this installation's push token without logging the learner out.
     * Turning notifications off must stop delivery immediately and must not
     * leave a reusable token attached to the account.
     */
    public function deleteDeviceToken(Request $request)
    {
        $validated = $request->validate([
            'device_token' => 'required|string|max:500',
        ]);

        $this->pushDevices->unregister((int) $request->user()->id, $validated['device_token']);

        return $this->responses->success(null, 'تم إيقاف تنبيهات هذا الجهاز');
    }
}
