<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CoinTaskRejected;
use App\Http\Controllers\Controller;
use App\Http\Resources\CoinEarningMethodResource;
use App\Models\CoinEarningMethod;
use App\Services\ApiResponseService;
use App\Services\CoinTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CoinEarningMethodController extends Controller
{
    private const ALREADY_CLAIMED = 'استلمت مكافأة هذه المهمة من قبل';

    public function __construct(
        private readonly CoinTaskService $tasks,
        private readonly ApiResponseService $responses
    ) {}

    public function index(): JsonResponse
    {
        $catalogue = $this->tasks->catalogue(auth('api')->user());

        return $this->responses->success(
            CoinEarningMethodResource::collection($catalogue['methods']),
            'تم تحميل طرق ربح العملات',
            200,
            [
                'how_to_use_coins' => $catalogue['coin_rules'],
                'coin_rules' => $catalogue['coin_rules'],
            ]
        );
    }

    public function start(Request $request, CoinEarningMethod $method): JsonResponse
    {
        try {
            $started = $this->tasks->start(
                auth('api')->user(),
                $method,
                $request->boolean('supports_ready_claim')
            );
        } catch (CoinTaskRejected $rejection) {
            $botUnavailable = $rejection->reason === CoinTaskRejected::WHATSAPP_BOT_UNAVAILABLE;

            return $this->responses->error(
                $botUnavailable ? "ربط واتساب غير متاح الآن\nحاول لاحقًا" : 'المهمة غير متاحة',
                $botUnavailable ? 503 : 404,
                null,
                ['code' => $rejection->reason]
            );
        }

        $message = match (true) {
            $started['data']['task_state'] === 'claimed' => self::ALREADY_CLAIMED,
            $started['state'] === CoinTaskService::LINKING => "أرسل الرسالة الجاهزة عبر واتساب\nتُضاف العملات عند وصولها",
            default => 'المهمة جاهزة',
        };

        return $this->responses->success($started['data'], $message);
    }

    public function claim(Request $request): JsonResponse
    {
        $request->validate([
            'method_id' => 'required|integer|exists:coin_earning_methods,id',
        ]);

        try {
            $claim = $this->tasks->claim(auth('api')->user(), $request->integer('method_id'));
        } catch (CoinTaskRejected $rejection) {
            return $this->responses->error(
                match ($rejection->reason) {
                    CoinTaskRejected::TASK_NOT_STARTED => 'لم تبدأ المهمة بعد',
                    CoinTaskRejected::TASK_QUOTA_REACHED => 'انتهت مكافآت هذه الحملة',
                    CoinTaskRejected::TASK_UNAVAILABLE => 'هذه المهمة غير متاحة الآن',
                    CoinTaskRejected::WHATSAPP_NOT_VERIFIED => 'لم يكتمل ربط واتساب بعد',
                    CoinTaskRejected::REWARD_BALANCE_FULL => "استخدم بعض عملات المكافآت أولًا\nثم استلم هذه المكافأة",
                    default => 'لم تكتمل المهمة بعد',
                },
                409,
                null,
                ['code' => $rejection->reason]
            );
        }

        return $this->responses->success(
            $claim,
            $claim['already_claimed'] ? self::ALREADY_CLAIMED : 'وصلت العملات إلى محفظتك'
        );
    }
}
