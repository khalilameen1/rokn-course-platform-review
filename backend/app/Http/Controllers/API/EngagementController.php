<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\EngagementOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EngagementController extends Controller
{
    public function next(Request $request, EngagementOfferService $offers, ApiResponseService $responses): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        // Missing context keeps the existing generic API. Partial context must
        // never fall back to an unrelated generic task offer.
        $input = $request->validate([
            'course_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'access_plan_code' => ['sometimes', 'required', 'string', 'max:40'],
        ]);
        $context = isset($input['course_id']) || isset($input['access_plan_code']);
        if ($context && (! isset($input['course_id']) || ! isset($input['access_plan_code']))) {
            throw ValidationException::withMessages(['course_id' => ['سياق الاشتراك غير مكتمل']]);
        }

        $offer = $offers->next($user, $context ? $input : null);

        return $responses->success($offer['data'], match ($offer['state']) {
            EngagementOfferService::OFFER => 'تم تحميل الرسالة المناسبة',
            EngagementOfferService::PAUSED => 'رسائل العملات متوقفة الآن',
            default => 'لا توجد رسالة الآن',
        });
    }
}
