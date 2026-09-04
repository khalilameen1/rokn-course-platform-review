<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PublicAppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\RoknLocale;

final class HomeController extends Controller
{
    public function __construct(
        private readonly PublicAppSettingsService $publicSettings
    ) {
    }

    public function settings(Request $request): JsonResponse
    {
        $settings = $this->publicSettings->snapshot(RoknLocale::fromRequest($request));

        return response()->json([
            "status" => 200,
            "success" => true,
            "message" => "تم تحميل إعدادات التطبيق",
            "data" => [$settings]
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60, stale-if-error=300',
            'ETag' => '"'.(string) $settings['revision'].'"',
            'Vary' => 'Accept-Language',
        ]);
    }

}
