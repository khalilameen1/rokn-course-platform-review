<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\PlaybackMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlaybackMetricsController extends Controller
{
    public function index(
        Request $request,
        PlaybackMetricsService $metrics,
        ApiResponseService $responses
    ): JsonResponse {
        $validated = $request->validate([
            'hours' => 'nullable|integer|min:1|max:'
                .max(1, (int) config('playback.metrics_max_window_hours', 720)),
            'lesson_id' => 'nullable|integer|exists:lessons,id',
        ]);

        return $responses->success(
            $metrics->summary(
                (int) ($validated['hours'] ?? 24),
                isset($validated['lesson_id']) ? (int) $validated['lesson_id'] : null
            ),
            'Playback metrics retrieved successfully'
        );
    }
}
