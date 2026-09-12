<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AiConsentService;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AiConsentController extends Controller
{
    public function show(AiConsentService $consent, ApiResponseService $responses): JsonResponse
    {
        return $responses->success($consent->payload(auth('api')->user()), 'إعدادات الاستفسارات ومراجعة المشاريع')
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, AiConsentService $consent, ApiResponseService $responses): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', Rule::in([AiConsentService::VERSION])],
            'accepted' => ['required', 'boolean'],
        ]);
        return $responses->success($consent->record(auth('api')->user(), (bool) $data['accepted']), 'تم حفظ اختيارك')
            ->header('Cache-Control', 'no-store');
    }
}
