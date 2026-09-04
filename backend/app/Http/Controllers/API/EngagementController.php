<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserCoinTaskAttempt;
use App\Services\AcquisitionRewardTombstoneService;
use App\Services\ApiResponseService;
use App\Services\EngagementMessageService;
use Illuminate\Http\JsonResponse;

final class EngagementController extends Controller
{
    public function next(
        EngagementMessageService $messages,
        AcquisitionRewardTombstoneService $tombstones,
        ApiResponseService $responses
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();
        $methods = CoinEarningMethod::query()
            ->learnerTask()
            ->withCount('userEarnings')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $methodIds = $methods->pluck('id');
        $earnedMethodIds = $user->coinEarnings()
            ->whereIn('coin_earning_method_id', $methodIds)
            ->pluck('coin_earning_method_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();
        $claimedMethodIds = UserCoinTaskAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('coin_earning_method_id', $methodIds)
            ->where('status', UserCoinTaskAttempt::STATUS_CLAIMED)
            ->pluck('coin_earning_method_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();
        // Tombstones are resolved from the learner's identities, not once per
        // candidate task. Home calls this endpoint frequently; query-per-task
        // selection turns a larger campaign list into avoidable DB fan-out.
        $consumedRewardKeys = $tombstones->consumedRewardKeys($user);

        $method = $methods->first(function (CoinEarningMethod $method) use (
            $tombstones,
            $consumedRewardKeys,
            $earnedMethodIds,
            $claimedMethodIds
        ): bool {
            $rewardKey = $tombstones->rewardKeyForMethod($method);
            if (
                !$method->hasUsableDestination()
                || ($method->total_claim_limit !== null
                    && (int) $method->user_earnings_count >= (int) $method->total_claim_limit)
                || ($rewardKey !== null && in_array($rewardKey, $consumedRewardKeys, true))
            ) {
                return false;
            }
            if ($earnedMethodIds->has((int) $method->id)) {
                return false;
            }

            return !$claimedMethodIds->has((int) $method->id);
        });

        if (!$method) {
            return $responses->success(null, 'لا توجد رسالة الآن');
        }

        $message = $messages->publicMessage('coin_offer', [
            'task' => $method->learnerTitleAr(),
            'coins' => (int) $method->coins_amount,
        ]);
        if (!$message) {
            return $responses->success(null, 'رسائل العملات متوقفة الآن');
        }

        return $responses->success($message + [
            'campaign_key' => 'coin-offer:' . $method->id,
            'task_id' => (string) $method->id,
            'action_key' => (string) $method->action_key,
            'link' => '/wallet',
        ], 'تم تحميل الرسالة المناسبة');
    }
}
