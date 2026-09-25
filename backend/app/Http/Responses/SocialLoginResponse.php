<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Auth\SocialLoginResult;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One mobile login envelope for native Apple and completed browser OAuth. */
final class SocialLoginResponse
{
    public static function make(SocialLoginResult $result, Request $request): JsonResponse
    {
        if (!$result->isSuccessful()) {
            return response()->json([
                'status' => $result->status,
                'success' => false,
                'code' => $result->code,
                'message' => $result->message,
                'data' => null,
            ], $result->status);
        }

        $profile = (new StudentProfileResource($result->user))
            ->withoutLearningSnapshot()
            ->resolve($request);
        // A linked provider may differ from the provider which created the user.
        $profile['social_provider'] = $result->provider;

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $result->message,
            'data' => [
                'user' => $profile,
                'api_token' => $result->apiToken,
                'device_token' => $result->deviceToken,
                'welcome_bonus_granted' => $result->welcomeBonusGranted,
            ],
        ]);
    }
}
