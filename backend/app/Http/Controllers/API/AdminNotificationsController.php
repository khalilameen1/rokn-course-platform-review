<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminNotificationsResource;
use App\Models\AdminNotification;
use App\Services\ApiResponseService;
use App\Services\EngagementMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminNotificationsController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses) {}

    public function index(): JsonResource
    {
        return $this->responses->resource(
            AdminNotificationsResource::collection(
                // This legacy public feed is announcements only. Transactional
                // and retention templates are internal control-plane content;
                // the two guest-safe messages have explicit keyed endpoints.
                AdminNotification::query()
                    ->available()
                    ->where('surface', 'announcement')
                    ->whereNull('system_key')
                    ->orderBy('priority')
                    ->orderBy('id')
                    ->get()
            ),
            'تم تحميل الإعلانات'
        );
    }

    public function message(
        string $systemKey,
        EngagementMessageService $messages
    ): JsonResponse {
        abort_unless(in_array($systemKey, [
            'guest_registration_prompt',
            'welcome_bonus_received',
        ], true), 404);

        $message = $messages->publicMessage($systemKey);
        if (! $message) {
            return $this->responses->success(null, 'لا توجد رسالة الآن');
        }

        return $this->responses->success($message, 'تم تحميل الرسالة');
    }
}
