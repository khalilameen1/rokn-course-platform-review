<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\ApiResponseService;
use App\Services\DeviceLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserSessionController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly DeviceLoginService $devices
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->responses->success(
            $this->devices->activeDeviceSessions($request->user(), $this->currentToken($request)),
            'تم تحميل الأجهزة المسجّل عليها الحساب'
        );
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $result = $this->devices->revokeDeviceSession(
            $request->user(),
            $sessionId,
            $this->currentToken($request)
        );

        return $this->responses->success(
            $result,
            $result['signed_out'] ? 'تم تسجيل الخروج من هذا الجهاز' : 'تم إنهاء الجلسة',
            200,
            ['signed_out' => $result['signed_out']]
        );
    }

    /** End every other app session while keeping this request usable. */
    public function destroyOthers(Request $request): JsonResponse
    {
        $current = $this->currentToken($request);
        if (! $current || empty($current->session_id)) {
            return $this->responses->error(
                'حدّث التطبيق ثم حاول مرة أخرى',
                409,
                null,
                ['code' => 'current_session_unavailable']
            );
        }

        $revoked = $this->devices->revokeOtherSessions($request->user(), $current);

        return $this->responses->success(
            ['revoked_count' => $revoked],
            $revoked > 0 ? 'تم تسجيل الخروج من الأجهزة الأخرى' : 'لا توجد جلسات أخرى'
        );
    }

    private function currentToken(Request $request): ?ApiToken
    {
        $current = $request->attributes->get('rokn_api_token');

        return $current instanceof ApiToken ? $current : null;
    }
}
