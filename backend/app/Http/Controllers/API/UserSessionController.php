<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\UserDeviceToken;
use App\Services\DeviceLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class UserSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ApiToken|null $current */
        $current = $request->attributes->get('rokn_api_token');
        $sessions = $request->user()->apiTokens()
            ->whereHasNotExpired()
            ->whereNotNull('session_id')
            ->orderByDesc('last_used_at')
            ->orderByDesc('issued_at')
            ->orderByDesc('session_id')
            ->limit(DeviceLoginService::MAX_ACTIVE_SESSIONS)
            ->get()
            ->map(static fn (ApiToken $token): array => [
                'id' => $token->session_id,
                'platform' => $token->platform ?: 'other',
                'device_class' => in_array($token->device_class, ['phone', 'tablet'], true)
                    ? $token->device_class
                    : null,
                'app_version' => $token->app_version,
                'app_build' => $token->app_build,
                'issued_at' => optional($token->issued_at)->toIso8601String(),
                'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                'expires_at' => optional($token->expired_at)->toIso8601String(),
                'current' => $current !== null && hash_equals((string) $current->session_id, (string) $token->session_id),
            ])
            ->values();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل الأجهزة المسجّل عليها الحساب',
            'data' => $sessions,
        ]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        /** @var ApiToken|null $current */
        $current = $request->attributes->get('rokn_api_token');
        /** @var ApiToken|null $session */
        $session = $request->user()->apiTokens()
            ->where('session_id', $sessionId)
            ->whereHasNotExpired()
            ->first();

        // Revocation is a desired-state operation. If the first successful
        // response was lost, retrying it must not turn that success into a
        // permanent 404 in the devices screen.
        if (!$session) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم إنهاء الجلسة',
                'data' => [
                    'signed_out' => false,
                    'already_revoked' => true,
                ],
                'signed_out' => false,
            ]);
        }

        $isCurrent = $current !== null
            && hash_equals((string) $current->session_id, (string) $session->session_id);
        DB::transaction(function () use ($session): void {
            $deviceId = trim((string) $session->device_id);
            $session->revoke();
            $this->removePushRegistrationForDevices($session->user_id, [$deviceId]);
        }, 3);

        $message = $isCurrent ? 'تم تسجيل الخروج من هذا الجهاز' : 'تم إنهاء الجلسة';

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $message,
            'data' => [
                'signed_out' => $isCurrent,
                'already_revoked' => false,
            ],
            'signed_out' => $isCurrent,
        ]);
    }

    /** End every other app session while keeping this request usable. */
    public function destroyOthers(Request $request): JsonResponse
    {
        /** @var ApiToken|null $current */
        $current = $request->attributes->get('rokn_api_token');
        if (!$current || empty($current->session_id)) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'current_session_unavailable',
                'message' => 'حدّث التطبيق ثم حاول مرة أخرى',
                'data' => null,
            ], 409);
        }

        $revoked = DB::transaction(function () use ($request, $current): int {
            $sessions = $request->user()->apiTokens()
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
            $sessions->each(static fn (ApiToken $session): mixed => $session->revoke());
            $this->removePushRegistrationForDevices((int) $request->user()->id, $deviceIds);

            return $sessions->count();
        }, 3);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $revoked > 0 ? 'تم تسجيل الخروج من الأجهزة الأخرى' : 'لا توجد جلسات أخرى',
            'data' => ['revoked_count' => $revoked],
        ]);
    }

    /** @param array<int, string> $deviceIds */
    private function removePushRegistrationForDevices(int $userId, array $deviceIds): void
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
}
