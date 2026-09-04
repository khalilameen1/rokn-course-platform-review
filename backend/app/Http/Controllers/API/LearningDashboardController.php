<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\LearningDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LearningDashboardController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly LearningDashboardService $dashboard
    ) {
    }

    public function courses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'cursor' => 'nullable|string|max:2048',
        ]);
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success(
            $this->dashboard->forUser(
                $user,
                (int) ($validated['per_page'] ?? 100),
                $validated['cursor'] ?? null
            ),
            'تم تحميل كورساتك'
        );
    }
}
