<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DeviceLoginService
{
    public function __construct(private readonly AuthFactory $auth) {}

    public const MAX_ACTIVE_SESSIONS = 25;

    public const POLICY_MULTIPLE = 'multiple_devices';

    public const POLICY_SINGLE = 'single_device';

    public const POLICY_SINGLE_PERMANENT = 'single_device_permanent';

    /**
     * A missing/corrupt setting must not silently remove the device boundary.
     * The rotating single-device policy contains concurrent variable-cost use
     * while still letting a legitimate learner move to a replacement phone.
     */
    public const SAFE_FALLBACK_POLICY = self::POLICY_SINGLE;

    /**
     * Check if user can login from the given device based on device login policy
     *
     * @return array ['allowed' => bool, 'message' => string, 'action' => string]
     */
    public function checkDeviceAccess(User $user, ?string $deviceId): array
    {
        $policy = $this->configuredPolicy();
        $deviceId = trim((string) $deviceId);

        if ($policy !== self::POLICY_MULTIPLE && $deviceId === '') {
            return [
                'allowed' => false,
                'message' => "حدّث التطبيق\nثم حاول تسجيل الدخول",
                'action' => 'deny',
                'device_id' => '',
            ];
        }

        switch ($policy) {
            case self::POLICY_MULTIPLE:
                return $this->handleMultipleDevices($user, $deviceId);

            case self::POLICY_SINGLE:
                return $this->handleSingleDevice($user, $deviceId);

            case self::POLICY_SINGLE_PERMANENT:
                return $this->handleSingleDevicePermanent($user, $deviceId);
        }

        // normalizePolicy() is exhaustive. Keep this closed if a future edit
        // adds a value without adding its enforcement branch here.
        return [
            'allowed' => false,
            'message' => "تعذّر التحقق من الجهاز\nحاول مرة أخرى",
            'action' => 'deny',
            'device_id' => $deviceId,
        ];
    }

    public function configuredPolicy(): string
    {
        try {
            $rawPolicy = Setting::query()->value('device_login_policy');
        } catch (Throwable $exception) {
            report($exception);

            return self::SAFE_FALLBACK_POLICY;
        }

        return self::normalizePolicy($rawPolicy);
    }

    public static function normalizePolicy(mixed $policy): string
    {
        $normalized = strtolower(trim((string) $policy));

        return in_array($normalized, [
            self::POLICY_MULTIPLE,
            self::POLICY_SINGLE,
            self::POLICY_SINGLE_PERMANENT,
        ], true)
            ? $normalized
            : self::SAFE_FALLBACK_POLICY;
    }

    /**
     * Handle multiple devices policy - always allow
     */
    private function handleMultipleDevices(User $user, string $deviceId): array
    {
        return [
            'allowed' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'action' => 'allow_multiple',
            'device_id' => $deviceId,
        ];
    }

    /**
     * Handle single device policy - allow but logout other devices
     */
    private function handleSingleDevice(User $user, string $deviceId): array
    {
        if (empty($user->locked_device_id)) {
            return [
                'allowed' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                // A null lock can be left over from an earlier multiple-device
                // policy. Retire those bearers before issuing the new one.
                'action' => 'logout_others',
                'device_id' => $deviceId,
            ];
        }

        $isDifferentDevice = $user->locked_device_id !== $deviceId;

        return [
            'allowed' => true,
            'message' => $isDifferentDevice
                ? 'تم تسجيل الدخول بنجاح. تم تسجيل الخروج من الأجهزة الأخرى.'
                : 'تم تسجيل الدخول بنجاح',
            // Reissuing a bearer on the same phone must still retire any
            // historical bearer that predates the device lock.
            'action' => 'logout_others',
            'device_id' => $deviceId,
        ];
    }

    /**
     * Handle single device permanent policy - lock to first device
     */
    private function handleSingleDevicePermanent(User $user, string $deviceId): array
    {
        // If no locked device, this is first login - lock to this device
        if (empty($user->locked_device_id)) {
            return [
                'allowed' => true,
                'message' => 'تم تسجيل الدخول بنجاح. تم ربط حسابك بهذا الجهاز.',
                'action' => 'logout_others',
                'device_id' => $deviceId,
            ];
        }

        // Check if current device matches locked device
        if ($user->locked_device_id === $deviceId) {
            return [
                'allowed' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'action' => 'logout_others',
                'device_id' => $deviceId,
            ];
        }

        // Device doesn't match - deny access
        return [
            'allowed' => false,
            'message' => "الحساب مرتبط بجهاز آخر\nتواصل مع الدعم لتغييره",
            'action' => 'deny',
            'device_id' => $deviceId,
        ];
    }

    /**
     * Apply device action after successful authentication
     */
    public function applyDeviceAction(User $user, string $action, string $deviceId): void
    {
        switch ($action) {
            case 'logout_others':
                $user->purgeApiTokens();
                $this->retireOtherDevicePushRegistrations($user, $deviceId);
                $user->forceFill(['locked_device_id' => $deviceId])->save();
                break;

            case 'allow_multiple':
                if ($deviceId !== '') {
                    $user->apiTokens()
                        ->whereHasNotExpired()
                        ->where(function ($query) use ($deviceId): void {
                            $query->where('device_id', $deviceId)
                                // Retire legacy bearers without installation
                                // identity when a known device signs in again.
                                ->orWhereNull('device_id');
                        })
                        ->lockForUpdate()
                        ->get()
                        ->each(static fn (ApiToken $token): mixed => $token->revoke());
                    $user->deviceTokens()
                        ->whereNull('device_id')
                        ->delete();
                }
                break;

            case 'deny':
                // No action needed, access was denied
                break;
        }
    }

    /**
     * Keep every live bearer visible and revocable in the sessions screen.
     * Reinstalls and repeated native sign-ins must not accumulate hidden
     * credentials beyond the 25 rows that API intentionally exposes.
     */
    public function enforceActiveSessionLimit(User $user, string $currentPlainToken): void
    {
        $currentStoredToken = hash('sha256', $currentPlainToken);
        $retiredDeviceIds = collect();

        do {
            $overflow = $user->apiTokens()
                ->whereHasNotExpired()
                ->where('token', '<>', $currentStoredToken)
                ->orderByDesc('issued_at')
                ->orderByDesc('token')
                ->skip(self::MAX_ACTIVE_SESSIONS - 1)
                ->take(100)
                ->lockForUpdate()
                ->get();
            $retiredDeviceIds = $retiredDeviceIds->merge(
                $overflow->pluck('device_id')
                    ->map(static fn ($value): string => trim((string) $value))
                    ->filter()
            );
            $overflow->each(static fn (ApiToken $token): mixed => $token->revoke());
        } while ($overflow->isNotEmpty());

        $retiredDeviceIds = $retiredDeviceIds->unique()->values();
        if ($retiredDeviceIds->isEmpty()) {
            return;
        }
        $stillActiveDeviceIds = $user->apiTokens()
            ->whereHasNotExpired()
            ->whereIn('device_id', $retiredDeviceIds)
            ->pluck('device_id');
        $orphanedDeviceIds = $retiredDeviceIds->diff($stillActiveDeviceIds)->values();
        if ($orphanedDeviceIds->isNotEmpty()) {
            UserDeviceToken::query()
                ->where('user_id', $user->id)
                ->whereIn('device_id', $orphanedDeviceIds)
                ->delete();
        }
    }

    /**
     * The learner's live sessions, one row per installation. A representative
     * session keeps the UUID route contract; revocation resolves it to every
     * session of that device.
     *
     * @return list<array<string, mixed>>
     */
    public function activeDeviceSessions(User $user, ?ApiToken $current): array
    {
        $currentSessionId = trim((string) $current?->session_id);

        return $user->apiTokens()
            ->whereHasNotExpired()
            ->whereNotNull('session_id')
            ->orderByDesc('last_used_at')
            ->orderByDesc('issued_at')
            ->orderByDesc('session_id')
            ->limit(self::MAX_ACTIVE_SESSIONS)
            ->get()
            ->groupBy(static function (ApiToken $token): string {
                $deviceId = trim((string) $token->device_id);

                return $deviceId !== ''
                    ? 'device:'.$deviceId
                    : 'legacy-session:'.$token->session_id;
            })
            ->map(static function ($deviceSessions) use ($currentSessionId): array {
                /** @var ApiToken $latest */
                $latest = $deviceSessions->first();
                /** @var ApiToken|null $currentDeviceSession */
                $currentDeviceSession = $deviceSessions->first(
                    static fn (ApiToken $token): bool => $currentSessionId !== ''
                        && hash_equals($currentSessionId, (string) $token->session_id)
                );
                $display = $currentDeviceSession ?: $latest;

                return [
                    'id' => $display->session_id,
                    'platform' => $display->platform ?: 'other',
                    'device_class' => in_array($display->device_class, ['phone', 'tablet'], true)
                        ? $display->device_class
                        : null,
                    'app_version' => $display->app_version,
                    'app_build' => $display->app_build,
                    'issued_at' => optional($display->issued_at)->toIso8601String(),
                    'last_used_at' => optional($latest->last_used_at ?: $latest->issued_at)->toIso8601String(),
                    'expires_at' => optional($display->expired_at)->toIso8601String(),
                    'current' => $currentDeviceSession !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * End one installation of the learner. Revocation is a desired state: a
     * session that is already gone reports `already_revoked` instead of 404,
     * so retrying after a lost response still succeeds.
     *
     * @return array{signed_out: bool, already_revoked: bool}
     */
    public function revokeDeviceSession(User $user, string $sessionId, ?ApiToken $current): array
    {
        /** @var ApiToken|null $session */
        $session = $user->apiTokens()
            ->where('session_id', $sessionId)
            ->whereHasNotExpired()
            ->first();
        if (! $session) {
            return ['signed_out' => false, 'already_revoked' => true];
        }

        $currentDeviceId = trim((string) $current?->device_id);
        $sessionDeviceId = trim((string) $session->device_id);
        $isCurrent = $current !== null
            && (
                hash_equals((string) $current->session_id, (string) $session->session_id)
                || ($currentDeviceId !== '' && hash_equals($currentDeviceId, $sessionDeviceId))
            );

        DB::transaction(function () use ($session): void {
            $deviceId = trim((string) $session->device_id);
            $sessions = ApiToken::query()
                ->where('user_id', $session->user_id)
                ->whereHasNotExpired()
                ->when(
                    $deviceId !== '',
                    static fn ($query) => $query->where('device_id', $deviceId),
                    static fn ($query) => $query->where('session_id', $session->session_id)
                )
                ->lockForUpdate()
                ->get();
            foreach ($sessions as $token) {
                $token->revoke();
            }
            $this->removeOrphanedPushRegistrations((int) $session->user_id, [$deviceId]);
        }, 3);

        return ['signed_out' => $isCurrent, 'already_revoked' => false];
    }

    /** End every other app session while keeping the current one usable. */
    public function revokeOtherSessions(User $user, ApiToken $current): int
    {
        return DB::transaction(function () use ($user, $current): int {
            $sessions = $user->apiTokens()
                ->whereHasNotExpired()
                ->whereNotNull('session_id')
                ->where('session_id', '<>', $current->session_id)
                ->lockForUpdate()
                ->get();
            $deviceIds = $sessions
                ->pluck('device_id')
                ->map(static fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();
            foreach ($sessions as $session) {
                $session->revoke();
            }
            $this->removeOrphanedPushRegistrations((int) $user->id, $deviceIds);

            return $sessions->count();
        }, 3);
    }

    /**
     * Log out the presented bearer. This installation's push registration is
     * removed unless a replacement bearer on the same device is still live.
     */
    public function signOutCurrentSession(User $user, ?ApiToken $currentToken, ?string $deviceToken): void
    {
        DB::transaction(function () use ($user, $currentToken, $deviceToken): void {
            $pushTokens = UserDeviceToken::query()
                ->where('user_id', $user->id);
            $currentDeviceId = trim((string) $currentToken?->device_id);
            $hasReplacementOnCurrentDevice = $currentToken !== null
                && $currentDeviceId !== ''
                && $user->apiTokens()
                    ->whereHasNotExpired()
                    ->where('token', '<>', $currentToken->getKey())
                    ->where('device_id', $currentDeviceId)
                    ->exists();
            if ($currentDeviceId !== '' && ! $hasReplacementOnCurrentDevice) {
                $pushTokens->where('device_id', $currentDeviceId)->delete();
            } elseif ($currentDeviceId === '' && ! empty($deviceToken)) {
                $pushTokens->where('device_token', $deviceToken)->delete();
            }

            $this->auth->guard('api')->logout();
        });
    }

    /** Dashboard revocation of one session and its installation's push binding. */
    public function revokeSessionFromDashboard(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId): void {
            $session = ApiToken::query()
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->firstOrFail();
            $deviceId = trim((string) $session->device_id);
            $session->revoke();
            if ($deviceId !== '' && Schema::hasColumn('user_device_tokens', 'device_id')) {
                UserDeviceToken::query()
                    ->where('user_id', $session->user_id)
                    ->where('device_id', $deviceId)
                    ->delete();
            }
        }, 3);
    }

    /** @param array<int, string> $deviceIds */
    private function removeOrphanedPushRegistrations(int $userId, array $deviceIds): void
    {
        $deviceIds = array_values(array_filter(array_unique($deviceIds)));
        if ($deviceIds === []) {
            return;
        }

        $activeDeviceIds = ApiToken::query()
            ->where('user_id', $userId)
            ->whereHasNotExpired()
            ->whereIn('device_id', $deviceIds)
            ->pluck('device_id')
            ->map(static fn ($deviceId): string => trim((string) $deviceId))
            ->filter()
            ->unique()
            ->all();
        $orphanedDeviceIds = array_values(array_diff($deviceIds, $activeDeviceIds));
        if ($orphanedDeviceIds === []) {
            return;
        }

        UserDeviceToken::query()
            ->where('user_id', $userId)
            ->whereIn('device_id', $orphanedDeviceIds)
            ->delete();
    }

    /**
     * A one-device policy applies to notification delivery as well as API
     * access. Otherwise a phone whose bearer was revoked can keep receiving
     * private account notifications until its FCM token happens to rotate.
     */
    private function retireOtherDevicePushRegistrations(User $user, string $deviceId): void
    {
        $tokens = $user->deviceTokens();
        $tokens
            ->where(function ($query) use ($deviceId): void {
                $query->whereNull('device_id')->orWhere('device_id', '<>', $deviceId);
            })
            ->delete();
    }
}
