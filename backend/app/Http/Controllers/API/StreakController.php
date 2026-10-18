<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;

final class StreakController extends Controller
{
    /**
     * Get streak data for the authenticated user.
     */
    public function index(ApiResponseService $responses): JsonResponse
    {
        $user = auth('api')->user();

        if (! $user) {
            return $responses->error('سجّل الدخول أولًا', 401);
        }

        return $responses->success(
            StreakService::getStreakDataForUser($user->id),
            'تم تحميل الاستمرارية'
        );
    }
}
