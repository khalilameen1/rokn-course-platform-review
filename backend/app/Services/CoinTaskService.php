<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CoinTaskRejected;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCoinTaskAttempt;
use App\Models\UserWhatsAppConnection;
use App\Support\BusinessClock;
use App\Support\StudentNotificationIntent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The learner side of coin tasks: the visible catalogue, starting a task
 * (the external visit) and claiming its one-time reward. Wallet balances are
 * credited only through WalletService; WhatsApp-linked tasks start through
 * WhatsAppLinkService. Lock order is the learner, then the attempt row, then
 * the campaign row when it has a finite quota.
 */
final readonly class CoinTaskService
{
    public const CLAIMED = 'claimed';

    public const LINKING = 'linking';

    public const STARTED = 'started';

    public function __construct(
        private AcquisitionRewardTombstoneService $tombstones,
        private StudentNotificationService $notifications,
        private WalletService $wallet,
        private WhatsAppLinkService $whatsAppLinks
    ) {}

    /**
     * Registration credit is granted automatically during verified social
     * login. It must never appear as a second, manually claimable task.
     *
     * @return array{methods: Collection<int, CoinEarningMethod>, coin_rules: mixed}
     */
    public function catalogue(?User $user): array
    {
        $methods = CoinEarningMethod::query()
            ->where(function ($query) use ($user): void {
                $query->where(fn ($available) => $available->learnerTask());
                if ($user) {
                    $query->orWhere(function ($completed) use ($user): void {
                        $completed->whereNotIn('action_key', CoinEarningMethod::AUTOMATIC_ACTION_KEYS)
                            ->where('coins_amount', '>', 0)
                            ->whereHas('userEarnings', fn ($earnings) => $earnings->where('user_id', $user->id));
                    });
                }
            })
            ->withExists(['userEarnings as completed_by_learner' => fn ($earnings) => $earnings->where('user_id', $user->id ?? 0)])
            ->withCount('userEarnings')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (CoinEarningMethod $method): bool {
                $hasCapacity = $method->total_claim_limit === null
                    || (int) $method->user_earnings_count < (int) $method->total_claim_limit;

                return $method->completed_by_learner || ($method->hasUsableDestination() && $hasCapacity);
            })
            ->values();
        $setting = Setting::first() ?? new Setting;

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
        $whatsAppVerified = $user && $methods->contains('action_key', 'link_whatsapp')
            && UserWhatsAppConnection::query()->where('user_id', $user->id)->verified()->exists();
        $consumedRewardKeys = $user ? $tombstones->consumedRewardKeys($user) : [];
        $methods->each(function (CoinEarningMethod $method) use (
            $earnings,
            $attempts,
            $tombstones,
            $whatsAppVerified,
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
            } elseif ($attempt && (
                $attempt->claim_available_at?->isFuture()
                || ($method->action_key === 'link_whatsapp' && ! $whatsAppVerified)
            )) {
                $state = 'started';
            } elseif ($attempt) {
                $state = 'ready_to_claim';
            }

            $method->is_consumed = $claimed;
            $method->task_state = $state;
        });

        return ['methods' => $methods, 'coin_rules' => $setting->how_to_use_coins];
    }

    /**
     * Record the external visit before the learner leaves the app. Returning to
     * claim is a separate action, matching the intended two-tap UX.
     *
     * @return array{state: string, data: array<string, mixed>}
     *
     * @throws CoinTaskRejected
     */
    public function start(User $user, CoinEarningMethod $method, bool $supportsReadyClaim): array
    {
        $actionUrl = $method->resolvedActionUrl();
        if (! $method->isLearnerTask() || ! $method->hasClaimCapacity()) {
            throw new CoinTaskRejected(CoinTaskRejected::TASK_UNAVAILABLE);
        }
        if ($this->tombstones->userHasConsumedMethod($user, $method)) {
            return ['state' => self::CLAIMED, 'data' => ['task_state' => 'claimed', 'action_url' => $actionUrl]];
        }
        if ($method->action_key === 'link_whatsapp') {
            try {
                return [
                    'state' => self::LINKING,
                    'data' => $this->whatsAppLinks->createLink($user, $method, $supportsReadyClaim),
                ];
            } catch (\DomainException $exception) {
                throw CoinTaskRejected::from($exception);
            }
        }
        if ($method->requires_external_visit && $actionUrl === null) {
            throw new CoinTaskRejected(CoinTaskRejected::TASK_UNAVAILABLE);
        }

        [$attempt, $alreadyClaimed] = DB::transaction(function () use ($user, $method): array {
            // Two fast taps must resolve to one immutable attempt on every DB driver.
            User::query()->lockForUpdate()->findOrFail($user->id);

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
            return ['state' => self::CLAIMED, 'data' => ['task_state' => 'claimed', 'action_url' => $actionUrl]];
        }

        return ['state' => self::STARTED, 'data' => [
            'attempt_id' => $attempt->public_id,
            'task_state' => $attempt->claim_available_at?->isFuture() ? 'started' : 'ready_to_claim',
            'action_url' => $actionUrl,
        ]];
    }

    /**
     * Credit the task's one-time reward. A successful claim is an immutable
     * receipt: a retry after a lost response acknowledges it, even when the
     * campaign was stopped or retired since.
     *
     * @return array{already_claimed: bool, earned_amount: int, new_balance: int|float, task_state: string}
     *
     * @throws CoinTaskRejected
     */
    public function claim(User $user, int $methodId): array
    {
        // Resolve retired campaigns too so a retry after a lost HTTP response
        // can still acknowledge the committed reward instead of a misleading 404.
        $method = CoinEarningMethod::withTrashed()->findOrFail($methodId);
        $historicalActionKey = trim((string) $method->action_key);
        if (
            $historicalActionKey === ''
            || $historicalActionKey === 'register'
            || (int) $method->coins_amount <= 0
        ) {
            throw (new ModelNotFoundException)->setModel(CoinEarningMethod::class, [$methodId]);
        }
        if ($this->tombstones->userHasConsumedMethod($user, $method)) {
            return [
                'already_claimed' => true,
                'earned_amount' => 0,
                'new_balance' => $this->wallet->balances($user->fresh())['total'],
                'task_state' => 'claimed',
            ];
        }

        try {
            $result = DB::transaction(fn (): array => $this->claimWithinTransaction($user, $method));
        } catch (\DomainException $exception) {
            throw CoinTaskRejected::from($exception);
        }

        return $result + ['task_state' => 'claimed'];
    }

    /** @return array{already_claimed: bool, earned_amount: int, new_balance: int|float} */
    private function claimWithinTransaction(User $user, CoinEarningMethod $method): array
    {
        // Serializes two rapid claim taps even before an attempt row exists.
        User::query()->lockForUpdate()->findOrFail($user->id);

        $attempt = UserCoinTaskAttempt::query()
            ->where('user_id', $user->id)
            ->where('coin_earning_method_id', $method->id)
            ->lockForUpdate()
            ->first();
        $earning = $user->coinEarnings()
            ->where('coin_earning_method_id', $method->id)
            ->first();

        // Historical success wins over today's catalogue state and current
        // verification settings. This is the recovery path for a response
        // lost just before a campaign was stopped or retired.
        if ($earning || $attempt?->status === UserCoinTaskAttempt::STATUS_CLAIMED) {
            return [
                'already_claimed' => true,
                'earned_amount' => (int) ($earning->amount ?? $method->coins_amount),
                'new_balance' => $this->wallet->balances($user->fresh())['total'],
            ];
        }

        $methodQuery = CoinEarningMethod::withTrashed();
        // Unlimited campaigns have no shared financial aggregate. Only a
        // finite global quota needs the campaign-row lock; otherwise every
        // learner claiming the same task would queue behind one unrelated
        // catalogue row.
        if ($method->total_claim_limit !== null) {
            $methodQuery->lockForUpdate();
        }
        $lockedMethod = $methodQuery->findOrFail($method->id);
        if ($lockedMethod->trashed() || ! $lockedMethod->isLearnerTask()) {
            throw new CoinTaskRejected(CoinTaskRejected::TASK_UNAVAILABLE);
        }
        if (
            $lockedMethod->action_key === 'link_whatsapp'
            && ! UserWhatsAppConnection::query()
                ->where('user_id', $user->id)
                ->verified()
                ->exists()
        ) {
            throw new CoinTaskRejected(CoinTaskRejected::WHATSAPP_NOT_VERIFIED);
        }
        if (! $lockedMethod->hasClaimCapacity()) {
            throw new CoinTaskRejected(CoinTaskRejected::TASK_QUOTA_REACHED);
        }

        if ($lockedMethod->requires_external_visit && ! $attempt) {
            throw new CoinTaskRejected(CoinTaskRejected::TASK_NOT_STARTED);
        }
        if ($attempt?->claim_available_at?->isFuture()) {
            throw new CoinTaskRejected(CoinTaskRejected::CLAIM_NOT_READY);
        }

        if (! $attempt) {
            $attempt = UserCoinTaskAttempt::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'coin_earning_method_id' => $method->id,
                'status' => UserCoinTaskAttempt::STATUS_STARTED,
                'started_at' => now(),
                'claim_available_at' => now(),
            ]);
        }

        $transaction = $this->wallet->creditRewardWithinConfiguredCap(
            $user->id,
            (int) $lockedMethod->coins_amount,
            'task_reward',
            "coin-task:{$user->id}:{$lockedMethod->id}",
            $lockedMethod,
            [
                'action_key' => $lockedMethod->action_key,
                'campaign_key' => $lockedMethod->campaign_key,
                'reward_timezone' => BusinessClock::timezoneName(),
            ]
        );
        if (! $transaction) {
            throw new CoinTaskRejected(CoinTaskRejected::REWARD_BALANCE_FULL);
        }

        $user->coinEarnings()->firstOrCreate(
            ['coin_earning_method_id' => $lockedMethod->id],
            ['amount' => $transaction->amount]
        );
        $attempt->update([
            'status' => UserCoinTaskAttempt::STATUS_CLAIMED,
            'claimed_at' => now(),
        ]);

        // The inbox receipt belongs to the same durable operation as the
        // wallet credit. Push delivery remains an after-commit side effect
        // inside StudentNotificationService, but there is no crash window that
        // can leave a credited task without its inbox notification forever.
        $this->notifications->notifyUser(
            $user->fresh(),
            new StudentNotificationIntent(
                notificationType: StudentNotificationService::TYPE_COINS_CLAIMED,
                titleAr: 'وصلت مكافأتك',
                titleEn: 'Coins Claimed',
                messageAr: 'أضفنا '.$transaction->amount." عملة إلى محفظتك\nافتح المحفظة لمعرفة التفاصيل",
                messageEn: $transaction->amount.' coins have been added to your wallet',
                link: null,
                notifiableType: CoinEarningMethod::class,
                notifiableId: $lockedMethod->id,
                deliveryKey: 'coins-claimed:'.$user->id.':'.$lockedMethod->id,
                templateVariables: [
                    'coins' => (int) $transaction->amount,
                    'task' => (string) ($lockedMethod->title_ar ?: $lockedMethod->title_en),
                ]
            )
        );

        return [
            'already_claimed' => false,
            'earned_amount' => (int) $transaction->amount,
            'new_balance' => $transaction->balance_after,
        ];
    }
}
