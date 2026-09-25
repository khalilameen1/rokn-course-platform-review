<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\LearningRewardConfigurationService;
use App\Services\LearningRewardService;
use Illuminate\Http\JsonResponse;

final class LearningRewardController extends Controller
{
    public function configuration(
        LearningRewardConfigurationService $configuration,
        ApiResponseService $responses
    ): JsonResponse {
        return $responses->success(
            $configuration->configuration(),
            'تم تحميل نظام عملات ركن'
        );
    }

    public function daily(
        LearningRewardService $rewards,
        ApiResponseService $responses
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();

        return $responses->success(
            $rewards->claimDaily($user),
            'تم تحديث مكافأة اليوم'
        );
    }
}
