<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoinEarningMethodResource;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use App\Models\UserCoinTaskAttempt;
use App\Services\AcquisitionRewardTombstoneService;
use App\Services\StudentNotificationService;
use App\Services\WalletService;
use App\Services\WhatsAppLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CoinEarningMethodController extends Controller
{
    public function __construct(
        private readonly AcquisitionRewardTombstoneService $tombstones
    ) {
    }

    public function index(): JsonResponse
    {
        // Registration credit is granted automatically during verified social
        // login. It must never appear as a second, manually claimable task.
        $methods = CoinEarningMethod::learnerTask()
            ->withCount('userEarnings')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (CoinEarningMethod $method): bool {
                $hasCapacity = $method->total_claim_limit === null
                    || (int) $method->user_earnings_count < (int) $method->total_claim_limit;

                return $method->hasUsableDestination() && $hasCapacity;
            })
            ->values();
        $setting = Setting::first() ?? new Setting();
        $user = auth('api')->user();

        $earnings = collect();
        $attempts = collect();
        if ($user) {
            $earnings = $user->coinEarnings()
                ->whereIn('coin_earning_method_id', $methods->pluck('id'))
                ->get()
                ->keyBy('coin_earning_method_id');
            $attempts = $user->coinTaskAttempts()
                ->whereIn('coin_earning_method_id', $methods->pluck('id'))
                ->get()
                ->keyBy('coin_earning_method_id');
        }

        $tombstones = $this->tombstones;
        $consumedRewardKeys = $user ? $tombstones->consumedRewardKeys($user) : [];
        $methods->each(function (CoinEarningMethod $method) use (
            $earnings,
            $attempts,
            $tombstones,
            $consumedRewardKeys
        ): void {
            $attempt = $attempts->get($method->id);
            $tombstoneKey = $tombstones->rewardKeyForMethod($method);
            $claimed = $earnings->has($method->id)
                || $attempt?->status === UserCoinTaskAttempt::STATUS_CLAIMED
                || ($tombstoneKey !== null && in_array($tombstoneKey, $consumedRewardKeys, true));
            $state = 'available';
            if ($claimed) {
                $state = 'claimed';
            } elseif ($attempt && $attempt->claim_available_at?->isFuture()) {
                $state = 'started';
            } elseif ($attempt) {
                $state = 'ready_to_claim';
            }

            $method->is_consumed = $claimed;
            $method->task_state = $state;
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل طرق ربح العملات',
            'how_to_use_coins' => $setting->how_to_use_coins,
            'coin_rules' => $setting->how_to_use_coins,
            'data' => CoinEarningMethodResource::collection($methods),
        ]);
    }

    /**
     * Record the external visit before the learner leaves the app. Returning to
     * claim is a separate action, matching the intended two-tap UX.
     */
    public function start(CoinEarningMethod $method, WhatsAppLinkService $whatsAppLinks): JsonResponse
    {
        $user = auth('api')->user();
        $actionUrl = $method->resolvedActionUrl();
        if (
            !$method->isLearnerTask()
            || !$method->hasClaimCapacity()
        ) {
            return $this->error('المهمة غير متاحة', 404, 'task_unavailable');
        }
        if ($this->tombstones->userHasConsumedMethod($user, $method)) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'استلمت مكافأة هذه المهمة من قبل',
                'data' => ['task_state' => 'claimed', 'action_url' => $actionUrl],
            ]);
        }
        if ($method->action_key === 'link_whatsapp') {
            try {
                $data = $whatsAppLinks->createLink($user, $method);
            } catch (\DomainException $exception) {
                return $this->error(
                    $exception->getMessage() === 'whatsapp_bot_unavailable'
                        ? "ربط واتساب غير متاح الآن\nحاول لاحقًا"
                        : 'المهمة غير متاحة',
                    $exception->getMessage() === 'whatsapp_bot_unavailable' ? 503 : 404,
                    $exception->getMessage()
                );
            }

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => $data['task_state'] === 'claimed'
                    ? 'استلمت مكافأة هذه المهمة من قبل'
                    : "أرسل الرسالة الجاهزة عبر واتساب\nتُضاف العملات عند وصولها",
                'data' => $data,
            ]);
        }
        if ($method->requires_external_visit && $actionUrl === null) {
            return $this->error('المهمة غير متاحة', 404, 'task_unavailable');
        }

        [$attempt, $alreadyClaimed] = DB::transaction(function () use ($user, $method): array {
            // Two fast taps must resolve to one immutable attempt on every DB driver.
            \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);

            $attempt = UserCoinTaskAttempt::firstOrCreate(
                ['user_id' => $user->id, 'coin_earning_method_id' => $method->id],
                [
                    'public_id' => (string) Str::uuid(),
                    'status' => UserCoinTaskAttempt::STATUS_STARTED,
                    'started_at' => now(),
                    'claim_available_at' => now()->addSeconds(max(0, (int) $method->verification_delay_seconds)),
                ]
            );

            $alreadyClaimed = $attempt->status === UserCoinTaskAttempt::STATUS_CLAIMED
                || $user->coinEarnings()
                    ->where('coin_earning_method_id', $method->id)
                    ->exists();

            return [$attempt, $alreadyClaimed];
        });

        if ($alreadyClaimed) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'استلمت مكافأة هذه المهمة من قبل',
                'data' => [
                    'task_state' => 'claimed',
                    'action_url' => $actionUrl,
                ],
            ]);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'المهمة جاهزة',
            'data' => [
                'attempt_id' => $attempt->public_id,
                'task_state' => $attempt->claim_available_at?->isFuture() ? 'started' : 'ready_to_claim',
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function claim(Request $request, WalletService $walletService): JsonResponse
    {
        $request->validate([
            'method_id' => 'required|integer|exists:coin_earning_methods,id',
        ]);

        $user = auth('api')->user();
        // A successful claim is an immutable receipt. Resolve retired campaigns
        // too so a retry after a lost HTTP response can still acknowledge the
        // committed reward instead of turning it into a misleading 404.
        $method = CoinEarningMethod::withTrashed()->findOrFail($request->integer('method_id'));
        $historicalActionKey = trim((string) $method->action_key);
        if (
            $historicalActionKey === ''
            || $historicalActionKey === 'register'
            || (int) $method->coins_amount <= 0
        ) {
            abort(404);
        }
        if ($this->tombstones->userHasConsumedMethod($user, $method)) {
            $freshUser = $user->fresh();
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'استلمت مكافأة هذه المهمة من قبل',
                'data' => [
                    'already_claimed' => true,
                    'earned_amount' => 0,
                    'new_balance' => $walletService->balances($freshUser)['total'],
                    'task_state' => 'claimed',
                ],
            ]);
        }
        try {
            $result = DB::transaction(function () use ($user, $method, $walletService): array {
                // Serializes two rapid claim taps even before an attempt row exists.
                \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);

                $attempt = UserCoinTaskAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('coin_earning_method_id', $method->id)
                    ->lockForUpdate()
                    ->first();
                $earning = $user->coinEarnings()
                    ->where('coin_earning_method_id', $method->id)
                    ->first();

                // Historical success wins over today's catalogue state and
                // current verification settings. This is the recovery path for
                // a response lost just before a campaign was stopped or retired.
                if ($earning || $attempt?->status === UserCoinTaskAttempt::STATUS_CLAIMED) {
                    return [
                        'already_claimed' => true,
                        'earned_amount' => (int) ($earning?->amount ?? $method->coins_amount),
                        'new_balance' => $walletService->balances($user->fresh())['total'],
                    ];
                }

                $methodQuery = CoinEarningMethod::withTrashed();
                // Unlimited campaigns have no shared financial aggregate.
                // Only a finite global quota needs the campaign-row lock;
                // otherwise every learner claiming the same task would queue
                // behind one unrelated catalogue row.
                if ($method->total_claim_limit !== null) {
                    $methodQuery->lockForUpdate();
                }
                $lockedMethod = $methodQuery->findOrFail($method->id);
                if ($lockedMethod->trashed() || !$lockedMethod->isLearnerTask()) {
                    throw new \DomainException('task_unavailable');
                }
                if (
                    $lockedMethod->action_key === 'link_whatsapp'
                    && !$user->whatsappConnection()
                        ->where('ownership_verified', true)
                        ->whereNotNull('verified_at')
                        ->exists()
                ) {
                    throw new \DomainException('whatsapp_not_verified');
                }
                if (!$lockedMethod->hasClaimCapacity()) {
                    throw new \DomainException('task_quota_reached');
                }

                if ($method->requires_external_visit && !$attempt) {
                    throw new \DomainException('task_not_started');
                }
                if ($attempt?->claim_available_at?->isFuture()) {
                    throw new \DomainException('claim_not_ready');
                }

                if (!$attempt) {
                    $attempt = UserCoinTaskAttempt::create([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'coin_earning_method_id' => $method->id,
                        'status' => UserCoinTaskAttempt::STATUS_STARTED,
                        'started_at' => now(),
                        'claim_available_at' => now(),
                    ]);
                }

                $transaction = $walletService->creditRewardWithinConfiguredCap(
                    $user->id,
                    (int) $lockedMethod->coins_amount,
                    'task_reward',
                    "coin-task:{$user->id}:{$lockedMethod->id}",
                    $lockedMethod,
                    [
                        'action_key' => $lockedMethod->action_key,
                        'campaign_key' => $lockedMethod->campaign_key,
                        'reward_timezone' => \App\Support\BusinessClock::timezoneName(),
                    ]
                );
                if (!$transaction) {
                    throw new \DomainException('reward_balance_full');
                }

                $user->coinEarnings()->firstOrCreate(
                    ['coin_earning_method_id' => $lockedMethod->id],
                    ['amount' => $transaction->amount]
                );
                $attempt->update([
                    'status' => UserCoinTaskAttempt::STATUS_CLAIMED,
                    'claimed_at' => now(),
                ]);

                // The inbox receipt belongs to the same durable operation as
                // the wallet credit. Push delivery remains an after-commit side
                // effect inside StudentNotificationService, but there is no
                // crash window that can leave a credited task without its inbox
                // notification forever.
                StudentNotificationService::notifyUser(
                    $user->fresh(),
                    StudentNotificationService::TYPE_COINS_CLAIMED,
                    'وصلت مكافأتك',
                    'Coins Claimed',
                    'أضفنا ' . $transaction->amount . " عملة إلى محفظتك\nافتح المحفظة لمعرفة التفاصيل",
                    $transaction->amount . ' coins have been added to your wallet',
                    null,
                    CoinEarningMethod::class,
                    $lockedMethod->id,
                    'coins-claimed:' . $user->id . ':' . $lockedMethod->id,
                    [
                        'coins' => (int) $transaction->amount,
                        'task' => (string) ($lockedMethod->title_ar ?: $lockedMethod->title_en),
                    ]
                );

                return [
                    'already_claimed' => false,
                    'earned_amount' => (int) $transaction->amount,
                    'new_balance' => $transaction->balance_after,
                ];
            });
        } catch (\DomainException $exception) {
            $code = $exception->getMessage();
            return $this->error(
                match ($code) {
                    'task_not_started' => 'لم تبدأ المهمة بعد',
                    'task_quota_reached' => 'انتهت مكافآت هذه الحملة',
                    'task_unavailable' => 'هذه المهمة غير متاحة الآن',
                    'whatsapp_not_verified' => 'لم يكتمل ربط واتساب بعد',
                    'reward_balance_full' => "استخدم بعض عملات المكافآت أولًا\nثم استلم هذه المكافأة",
                    default => 'لم تكتمل المهمة بعد',
                },
                409,
                $code
            );
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $result['already_claimed']
                ? 'استلمت مكافأة هذه المهمة من قبل'
                : 'وصلت العملات إلى محفظتك',
            'data' => $result + ['task_state' => 'claimed'],
        ]);
    }

    private function error(string $message, int $status, string $code): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $status);
    }
}
