<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CourseCheckout;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseCheckoutQuoteService;
use App\Services\EngagementMessageService;
use App\Services\EngagementTaskReadService;
use App\Services\NotificationDeliveryPolicy;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EngagementController extends Controller
{
    public function next(Request $request, EngagementMessageService $messages,
        EngagementTaskReadService $tasks, WalletService $wallet,
        CourseCheckoutQuoteService $quotes, ApiResponseService $responses): JsonResponse
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
        if ($context && (!isset($input['course_id']) || !isset($input['access_plan_code']))) {
            throw ValidationException::withMessages(['course_id' => ['سياق الاشتراك غير مكتمل']]);
        }
        $candidate = DB::transaction(function () use ($user, $input, $context, $quotes, $wallet, $tasks): ?array {
            if (!$user->active) return null;
            $terms = null;
            if ($context) {
                if (!NotificationDeliveryPolicy::allowsInbox($user, 'coin_offer')) return null;
                // Never turn payment recovery into a reward-task detour.
                if (CourseCheckout::query()->where('user_id', $user->id)->awaitingSettlement()->exists()) return null;
                try {
                    // Read projection, not CourseCheckoutService::create: no
                    // checkout, order, debit or enrollment is created here.
                    $terms = $quotes->calculate($user, [
                        'course_id' => (int) $input['course_id'],
                        'access_plan_code' => $input['access_plan_code'],
                        'mode' => 'purchase', 'channel' => 'direct',
                    ], false, false);
                } catch (\DomainException | ModelNotFoundException | ValidationException) {
                    return null;
                }
                if ($terms['reward_opportunity_coins'] <= 0) return null;
            }
            $balances = $terms['wallet'] ?? $wallet->balances($user);
            $method = $tasks->next($user, $wallet->rewardCreditRoom((int) $balances['reward']));
            return $method ? ['method' => $method, 'terms' => $terms] : null;
        });
        if (!$candidate) return $responses->success(null, 'لا توجد رسالة الآن');
        $method = $candidate['method'];
        $message = $messages->publicMessage('coin_offer', [
            'task' => $method->learnerTitleAr(), 'coins' => (int) $method->coins_amount,
        ]);
        if (!$message) return $responses->success(null, 'رسائل العملات متوقفة الآن');
        $terms = $candidate['terms'];
        return $responses->success(array_replace($message, [
            'campaign_key' => 'coin-offer:' . $method->id,
            'task_id' => (string) $method->id,
            'action_key' => (string) $method->action_key,
            'link' => '/wallet',
            ...($terms ? ['purchase_exit' => [
                'course_id' => (string) $terms['course_id'],
                'access_plan_code' => (string) $terms['access_plan_code'],
                // Full task credit must fit the wallet; only this portion
                // can reduce this course's shortfall. Never promise cash.
                'additional_discount_coins' => min((int) $method->coins_amount, $terms['reward_opportunity_coins']),
            ]] : []),
        ]), 'تم تحميل الرسالة المناسبة');
    }
}
