<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\DatabaseCapabilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WalletService
{
    /**
     * @return array{cap:int,used:int,remaining:int}
     */
    public function courseRewardContribution(
        int $userId,
        int $courseId,
        int $cap
    ): array {
        $normalizedCap = max(0, $cap);
        $used = max(0, (int) WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('source_type', Course::class)
            ->where('source_id', $courseId)
            ->whereIn('category', [
                'course_purchase',
                'course_chat_upgrade',
                'course_full_track_upgrade',
            ])
            ->sum('reward_amount'));

        return [
            'cap' => $normalizedCap,
            'used' => $used,
            'remaining' => max(0, $normalizedCap - $used),
        ];
    }

    public function coursePaidContribution(int $userId, int $courseId): int
    {
        $key = $userId . ':' . $courseId;

        return max(0, (int) (
            $this->coursePaidContributionTotals([$userId], [$courseId])
                ->get($key)?->paid_total ?? 0
        ));
    }

    /**
     * Paid-floor decisions count only debits that have not subsequently lost
     * their financial entitlement. The reward contribution cap intentionally
     * remains lifetime/course-wide, but reversed package money must not let a
     * learner reopen an AI plan using only newly earned free coins.
     *
     * @param list<int> $userIds
     * @param list<int> $courseIds
     * @return Collection<string,object>
     */
    public function coursePaidContributionTotals(array $userIds, array $courseIds): Collection
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));
        $courseIds = array_values(array_unique(array_filter(
            array_map('intval', $courseIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($userIds === [] || $courseIds === []) {
            return collect();
        }

        $query = WalletTransaction::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('source_id', $courseIds)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('source_type', Course::class)
            ->whereIn('category', [
                'course_purchase',
                'course_chat_upgrade',
                'course_full_track_upgrade',
            ]);
        $this->excludeInvalidatedCourseDebits($query);

        return $query
            ->groupBy('user_id', 'source_id')
            ->selectRaw('user_id, source_id, SUM(paid_amount) as paid_total')
            ->get()
            ->keyBy(static fn ($row): string =>
                ((int) $row->user_id) . ':' . ((int) $row->source_id)
            );
    }

    public function credit(
        int $userId,
        int $amount,
        string $category,
        string $idempotencyKey,
        ?Model $source = null,
        array $metadata = [],
        string $bucket = WalletTransaction::BUCKET_REWARD
    ): WalletTransaction {
        return $this->recordTransaction(
            $userId,
            $amount,
            WalletTransaction::DIRECTION_CREDIT,
            $category,
            $idempotencyKey,
            $source,
            $metadata,
            $bucket
        );
    }

    public function debit(
        int $userId,
        int $amount,
        string $category,
        string $idempotencyKey,
        ?Model $source = null,
        array $metadata = [],
        ?int $maxRewardAmount = null
    ): WalletTransaction {
        return $this->recordTransaction(
            $userId,
            $amount,
            WalletTransaction::DIRECTION_DEBIT,
            $category,
            $idempotencyKey,
            $source,
            $metadata,
            null,
            null,
            null,
            $maxRewardAmount
        );
    }

    /**
     * Credit free value without ever crossing the configured reward-wallet
     * ceiling. The user aggregate lock makes the room calculation and ledger
     * append one operation across concurrent welcome/task claims.
     */
    public function creditRewardWithinConfiguredCap(
        int $userId,
        int $requestedAmount,
        string $category,
        string $idempotencyKey,
        ?Model $source = null,
        array $metadata = []
    ): ?WalletTransaction {
        if ($requestedAmount < 0) {
            throw new \InvalidArgumentException('Wallet amount must be zero or greater.');
        }

        return DB::transaction(function () use (
            $userId,
            $requestedAmount,
            $category,
            $idempotencyKey,
            $source,
            $metadata
        ): ?WalletTransaction {
            /** @var User $user */
            $user = User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $storedRequested = data_get($existing->metadata, 'requested_amount');
                if (
                    $existing->direction !== WalletTransaction::DIRECTION_CREDIT
                    || $existing->category !== $category
                    || $existing->source_type !== ($source ? get_class($source) : null)
                    || (string) ($existing->source_id ?? '') !== (string) ($source?->getKey() ?? '')
                    || ($storedRequested !== null && (int) $storedRequested !== $requestedAmount)
                    || ($storedRequested === null && (int) $existing->amount !== $requestedAmount)
                ) {
                    throw new \UnexpectedValueException(
                        'Wallet reward idempotency key was reused for a different operation.'
                    );
                }

                return $existing;
            }

            [, $rewardBalance] = $this->ledgerBalances($user);
            $rewardCap = max(0, (int) (Setting::query()->value('reward_balance_cap') ?? 1200));
            $rewardRoom = max(0, $rewardCap - $rewardBalance);
            // A one-time offer is indivisible. Silently granting only the
            // remaining room would consume the task while paying less than
            // the amount shown to the learner.
            if ($requestedAmount <= 0 || $requestedAmount > $rewardRoom) {
                return null;
            }
            $creditedAmount = $requestedAmount;

            return $this->recordTransaction(
                $userId,
                $creditedAmount,
                WalletTransaction::DIRECTION_CREDIT,
                $category,
                $idempotencyKey,
                $source,
                array_merge($metadata, [
                    'requested_amount' => $requestedAmount,
                    'reward_balance_cap' => $rewardCap,
                ]),
                WalletTransaction::BUCKET_REWARD
            );
        }, 3);
    }

    /** Refunds preserve the original paid and reward attribution. */
    public function refundDebit(
        int $userId,
        int $amount,
        string $category,
        string $idempotencyKey,
        WalletTransaction $originalDebit,
        ?Model $source = null,
        array $metadata = []
    ): WalletTransaction {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Invalid wallet debit refund.');
        }

        return DB::transaction(function () use (
            $userId,
            $amount,
            $category,
            $idempotencyKey,
            $originalDebit,
            $source,
            $metadata
        ): WalletTransaction {
            // Every wallet mutation serializes on the user aggregate. Keep the
            // remaining refundable allocation behind the same lock, otherwise
            // two distinct refund requests can both observe the full remainder.
            User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $lockedDebit = WalletTransaction::query()
                ->whereKey($originalDebit->getKey())
                ->where('user_id', $userId)
                ->where('direction', WalletTransaction::DIRECTION_DEBIT)
                ->lockForUpdate()
                ->first();
            if (!$lockedDebit || $amount > (int) $lockedDebit->amount) {
                throw new \InvalidArgumentException('Invalid wallet debit refund.');
            }
            $refundSource = $source ?? $lockedDebit;

            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $this->assertRefundReplay(
                    $existing,
                    $amount,
                    $category,
                    $refundSource,
                    (string) $lockedDebit->public_id
                );
                return $existing;
            }

            $previousRefunds = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('direction', WalletTransaction::DIRECTION_CREDIT)
                ->where('metadata->refunded_transaction_id', $lockedDebit->public_id)
                ->get(['paid_amount', 'reward_amount']);
            $remainingReward = max(
                0,
                (int) $lockedDebit->reward_amount - (int) $previousRefunds->sum('reward_amount')
            );
            $remainingPaid = max(
                0,
                (int) $lockedDebit->paid_amount - (int) $previousRefunds->sum('paid_amount')
            );
            if ($amount > $remainingReward + $remainingPaid) {
                throw new \InvalidArgumentException('Wallet debit refund exceeds the remaining allocation.');
            }

            $rewardAmount = min($amount, $remainingReward);
            $paidAmount = min($amount - $rewardAmount, $remainingPaid);

            // Unattributed legacy value remains reward value.
            $rewardAmount += max(0, $amount - $rewardAmount - $paidAmount);

            return $this->recordTransaction(
                $userId,
                $amount,
                WalletTransaction::DIRECTION_CREDIT,
                $category,
                $idempotencyKey,
                $refundSource,
                $metadata + ['refunded_transaction_id' => $lockedDebit->public_id],
                $paidAmount > 0 && $rewardAmount > 0
                    ? WalletTransaction::BUCKET_MIXED
                    : ($paidAmount > 0 ? WalletTransaction::BUCKET_PAID : WalletTransaction::BUCKET_REWARD),
                $paidAmount,
                $rewardAmount
            );
        }, 3);
    }

    private function assertRefundReplay(
        WalletTransaction $existing,
        int $amount,
        string $category,
        Model $source,
        string $originalPublicId
    ): void {
        if (
            $existing->direction !== WalletTransaction::DIRECTION_CREDIT
            || (int) $existing->amount !== $amount
            || !hash_equals((string) $existing->category, $category)
            || (string) $existing->source_type !== get_class($source)
            || (string) ($existing->source_id ?? '') !== (string) $source->getKey()
            || !hash_equals(
                (string) data_get($existing->metadata, 'refunded_transaction_id', ''),
                $originalPublicId
            )
        ) {
            throw new \UnexpectedValueException(
                'Wallet refund idempotency key was reused for a different operation.'
            );
        }
    }

    private function recordTransaction(
        int $userId,
        int $amount,
        string $direction,
        string $category,
        string $idempotencyKey,
        ?Model $source,
        array $metadata,
        ?string $creditBucket,
        ?int $forcedPaidAmount = null,
        ?int $forcedRewardAmount = null,
        ?int $maxRewardDebitAmount = null
    ): WalletTransaction {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Wallet amount must be zero or greater.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 140) {
            throw new \InvalidArgumentException('Wallet idempotency key is required.');
        }

        $fingerprint = $this->operationFingerprint(
            $amount,
            $direction,
            $category,
            $source,
            $creditBucket,
            $forcedPaidAmount,
            $forcedRewardAmount,
            $maxRewardDebitAmount
        );

        return DB::transaction(function () use (
            $userId,
            $amount,
            $direction,
            $category,
            $idempotencyKey,
            $source,
            $metadata,
            $creditBucket,
            $forcedPaidAmount,
            $forcedRewardAmount,
            $maxRewardDebitAmount,
            $fingerprint
        ): WalletTransaction {
            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $this->assertIdempotentReplay($existing, $fingerprint, $amount, $direction, $category, $source);
                return $existing;
            }

            /** @var User $user */
            // Retained financial orders can settle or reverse after account deletion.
            // The user row is anonymized and soft-deleted, but its ledger must remain balanced.
            $user = User::withTrashed()->lockForUpdate()->findOrFail($userId);

            // Recheck idempotency after acquiring the aggregate lock.
            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $this->assertIdempotentReplay($existing, $fingerprint, $amount, $direction, $category, $source);
                return $existing;
            }

            [$paidBalance, $rewardBalance] = $this->ledgerBalances($user);
            if ($direction === WalletTransaction::DIRECTION_DEBIT) {
                $rewardSpendable = $maxRewardDebitAmount === null
                    ? $rewardBalance
                    : min($rewardBalance, max(0, $maxRewardDebitAmount));
                $effectiveSpendable = $paidBalance + $rewardSpendable;
                if ($effectiveSpendable < $amount) {
                    throw new InsufficientWalletBalanceException($amount, $effectiveSpendable);
                }
            }

            if ($direction === WalletTransaction::DIRECTION_CREDIT) {
                if ($forcedPaidAmount !== null || $forcedRewardAmount !== null) {
                    $paidAmount = max(0, (int) $forcedPaidAmount);
                    $rewardAmount = max(0, (int) $forcedRewardAmount);
                    if ($paidAmount + $rewardAmount !== $amount) {
                        throw new \InvalidArgumentException('Wallet credit allocation must equal amount.');
                    }
                } elseif ($creditBucket === WalletTransaction::BUCKET_PAID) {
                    $paidAmount = $amount;
                    $rewardAmount = 0;
                } elseif ($creditBucket === WalletTransaction::BUCKET_REWARD) {
                    $paidAmount = 0;
                    $rewardAmount = $amount;
                } else {
                    throw new \InvalidArgumentException('Wallet credit bucket must be paid or reward.');
                }

                if (
                    $creditBucket === WalletTransaction::BUCKET_REWARD
                    && $forcedPaidAmount === null
                    && $forcedRewardAmount === null
                ) {
                    $rewardCap = max(0, (int) (Setting::query()->value('reward_balance_cap') ?? 1200));
                    if ($rewardBalance + $rewardAmount > $rewardCap) {
                        throw new \DomainException('reward_balance_cap_exceeded');
                    }
                }

                $paidBalance += $paidAmount;
                $rewardBalance += $rewardAmount;
            } else {
                // Debits consume reward value before paid value.
                $rewardLimit = $maxRewardDebitAmount === null
                    ? $amount
                    : max(0, min($amount, $maxRewardDebitAmount));
                $rewardAmount = min($rewardBalance, $amount, $rewardLimit);
                $paidAmount = $amount - $rewardAmount;
                $rewardBalance -= $rewardAmount;
                $paidBalance -= $paidAmount;
            }

            $newBalance = $paidBalance + $rewardBalance;
            $bucket = $paidAmount > 0 && $rewardAmount > 0
                ? WalletTransaction::BUCKET_MIXED
                : ($paidAmount > 0 ? WalletTransaction::BUCKET_PAID : WalletTransaction::BUCKET_REWARD);

            $user->forceFill([
                'wallet_coins' => $newBalance,
                'wallet_purchased_coins' => $paidBalance,
                'wallet_reward_coins' => $rewardBalance,
            ])->save();

            $metadata = array_merge($metadata, [
                'request_fingerprint' => $fingerprint,
                'allocation_policy' => $direction === WalletTransaction::DIRECTION_DEBIT
                    ? 'reward_first_then_paid'
                    : 'source_bucket',
                'paid_coins' => $paidAmount,
                'reward_coins' => $rewardAmount,
            ]);

            return WalletTransaction::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'direction' => $direction,
                'category' => $category,
                'bucket' => $bucket,
                'amount' => $amount,
                'paid_amount' => $paidAmount,
                'reward_amount' => $rewardAmount,
                'balance_after' => $newBalance,
                'paid_balance_after' => $paidBalance,
                'reward_balance_after' => $rewardBalance,
                'source_type' => $source ? get_class($source) : null,
                'source_id' => $source?->getKey(),
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata ?: null,
                'occurred_at' => now(),
            ]);
        });
    }

    private function assertIdempotentReplay(
        WalletTransaction $existing,
        string $fingerprint,
        int $amount,
        string $direction,
        string $category,
        ?Model $source
    ): void {
        $storedFingerprint = (string) data_get($existing->metadata, 'request_fingerprint', '');
        $sameLegacyOperation = (int) $existing->amount === $amount
            && hash_equals((string) $existing->direction, $direction)
            && hash_equals((string) $existing->category, $category)
            && (string) $existing->source_type === ($source ? get_class($source) : '')
            && (string) ($existing->source_id ?? '') === (string) ($source?->getKey() ?? '');

        if (
            ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $fingerprint))
            || ($storedFingerprint === '' && !$sameLegacyOperation)
        ) {
            throw new \UnexpectedValueException(
                'Wallet idempotency key was reused for a different operation.'
            );
        }
    }

    private function operationFingerprint(
        int $amount,
        string $direction,
        string $category,
        ?Model $source,
        ?string $creditBucket,
        ?int $forcedPaidAmount,
        ?int $forcedRewardAmount,
        ?int $maxRewardDebitAmount
    ): string {
        return hash('sha256', json_encode([
            'amount' => $amount,
            'direction' => $direction,
            'category' => $category,
            'source_type' => $source ? get_class($source) : null,
            'source_id' => $source?->getKey(),
            'credit_bucket' => $creditBucket,
            'forced_paid_amount' => $forcedPaidAmount,
            'forced_reward_amount' => $forcedRewardAmount,
            'max_reward_debit_amount' => $maxRewardDebitAmount,
        ], JSON_THROW_ON_ERROR));
    }

    private function excludeInvalidatedCourseDebits(Builder $query): void
    {
        if (!DatabaseCapabilities::hasColumns('orders', [
            'wallet_transaction_id',
            'status',
            'financial_status',
            'reversed_at',
        ])) {
            return;
        }

        $hasHolds = DatabaseCapabilities::hasTable('financial_entitlement_holds');
        $query->whereExists(function ($orders) use ($hasHolds): void {
            $orders->selectRaw('1')
                ->from('orders as wallet_course_order')
                ->whereColumn(
                    'wallet_course_order.wallet_transaction_id',
                    'wallet_transactions.id'
                )
                ->whereColumn(
                    'wallet_course_order.user_id',
                    'wallet_transactions.user_id'
                )
                ->whereColumn(
                    'wallet_course_order.course_id',
                    'wallet_transactions.source_id'
                )
                ->where('wallet_course_order.payment_method', 'wallet_coins')
                ->where('wallet_course_order.status', 'approved')
                ->where('wallet_course_order.financial_status', 'settled')
                ->whereNull('wallet_course_order.reversed_at');
            if ($hasHolds) {
                $orders->whereNotExists(function ($holds): void {
                    $holds->selectRaw('1')
                        ->from('financial_entitlement_holds as wallet_hold')
                        ->whereColumn(
                            'wallet_hold.course_order_id',
                            'wallet_course_order.id'
                        )
                        ->where('wallet_hold.status', 'active');
                });
            }
        });
    }

    /** @return array{total:int,paid:int,reward:int} */
    public function balances(User $user): array
    {
        // Callers frequently render a bearer model that was hydrated before a
        // concurrent payment callback. Read the projection and its append-only
        // tail in one statement so an ordinary credit cannot look like ledger
        // corruption merely because the supplied model instance is stale.
        $snapshot = DB::table('users as wallet_user')
            ->leftJoin('wallet_transactions as wallet_tail', function ($join): void {
                $join->on('wallet_tail.user_id', '=', 'wallet_user.id')
                    ->whereRaw(
                        'wallet_tail.id = (SELECT MAX(wallet_latest.id)'
                        . ' FROM wallet_transactions AS wallet_latest'
                        . ' WHERE wallet_latest.user_id = wallet_user.id)'
                    );
            })
            ->where('wallet_user.id', $user->getKey())
            ->first([
                'wallet_user.wallet_coins',
                'wallet_user.wallet_purchased_coins',
                'wallet_user.wallet_reward_coins',
                'wallet_tail.id as ledger_id',
                'wallet_tail.balance_after as ledger_balance',
                'wallet_tail.paid_balance_after as ledger_paid',
                'wallet_tail.reward_balance_after as ledger_reward',
            ]);
        if (!$snapshot) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())
                ->setModel(User::class, [$user->getKey()]);
        }

        [$paid, $reward] = $this->validatedLedgerProjection(
            (int) $snapshot->wallet_coins,
            (int) $snapshot->wallet_purchased_coins,
            (int) $snapshot->wallet_reward_coins,
            $snapshot->ledger_id === null ? null : (int) $snapshot->ledger_balance,
            $snapshot->ledger_id === null ? null : (int) $snapshot->ledger_paid,
            $snapshot->ledger_id === null ? null : (int) $snapshot->ledger_reward
        );

        return ['total' => $paid + $reward, 'paid' => $paid, 'reward' => $reward];
    }

    /**
     * The ledger tail is authoritative after the first wallet operation. User
     * columns are a locked projection for cheap reads and must match it; never
     * repair or reclassify a mismatch inside a learner request.
     *
     * @return array{0:int,1:int}
     */
    private function ledgerBalances(User $user): array
    {
        $tail = WalletTransaction::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->first(['balance_after', 'paid_balance_after', 'reward_balance_after']);

        return $this->validatedLedgerProjection(
            (int) $user->wallet_coins,
            (int) $user->wallet_purchased_coins,
            (int) $user->wallet_reward_coins,
            $tail ? (int) $tail->balance_after : null,
            $tail ? (int) $tail->paid_balance_after : null,
            $tail ? (int) $tail->reward_balance_after : null
        );
    }

    /** @return array{0:int,1:int} */
    private function validatedLedgerProjection(
        int $projectedTotal,
        int $projectedPaid,
        int $projectedReward,
        ?int $ledgerTotal,
        ?int $ledgerPaid,
        ?int $ledgerReward
    ): array {
        if ($ledgerTotal === null || $ledgerPaid === null || $ledgerReward === null) {
            if ($projectedTotal !== 0 || $projectedPaid !== 0 || $projectedReward !== 0) {
                throw new \LogicException('A non-zero wallet must have a ledger anchor.');
            }

            return [0, 0];
        }

        if (
            $ledgerPaid < 0
            || $ledgerReward < 0
            || $ledgerTotal !== $ledgerPaid + $ledgerReward
            || $ledgerPaid !== $projectedPaid
            || $ledgerReward !== $projectedReward
            || $projectedTotal !== $ledgerPaid + $ledgerReward
        ) {
            throw new \LogicException('Wallet ledger tail does not match its balance projection.');
        }

        return [$ledgerPaid, $ledgerReward];
    }
}
