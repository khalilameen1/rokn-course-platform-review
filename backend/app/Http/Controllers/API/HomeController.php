<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\PublicAppSettingsService;
use App\Support\RoknLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HomeController extends Controller
{
    public function __construct(
        private readonly PublicAppSettingsService $publicSettings,
        private readonly ApiResponseService $responses
    ) {}

    public function settings(Request $request): JsonResponse
    {
        $settings = $this->publicSettings->snapshot(RoknLocale::fromRequest($request));

        return $this->responses->success([$settings], 'تم تحميل إعدادات التطبيق')->withHeaders([
            'Cache-Control' => 'public, max-age=60, stale-if-error=300',
            'ETag' => '"'.(string) $settings['revision'].'"',
            'Vary' => 'Accept-Language',
        ]);
    }
}
