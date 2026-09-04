<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\PublicPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicPortfolioController extends Controller
{
    public function show(
        Request $request,
        string $slug,
        PublicPortfolioService $service,
        ApiResponseService $responses
    ): JsonResponse {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $paginated = $request->filled('page') || $request->filled('per_page');
        $portfolio = $service->find(
            $slug,
            $paginated ? (int) ($validated['page'] ?? 1) : null,
            $paginated ? (int) ($validated['per_page'] ?? 24) : null
        );
        if (!$portfolio) {
            return $responses->error('المعرض غير متاح', 404);
        }

        return $responses
            ->success($portfolio, 'تم تحميل المعرض')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
