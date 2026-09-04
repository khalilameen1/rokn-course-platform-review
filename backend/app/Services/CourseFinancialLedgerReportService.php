<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Order;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Reads course coin spend from the immutable wallet ledger. */
final class CourseFinancialLedgerReportService
{
    private const DEBIT_CATEGORIES = [
        'course_purchase',
        'course_chat_upgrade',
        'course_full_track_upgrade',
    ];

    /**
     * @param Collection<int, Order> $orders
     * @return Collection<int, array{total_coins:int,paid_coins:int,reward_coins:int,complete:bool}>
     */
    public function allocationsForOrders(Collection $orders): Collection
    {
        $walletOrders = $orders->filter(fn (Order $order): bool => in_array(
            $order->payment_method,
            [Order::PAYMENT_METHOD_WALLET, Order::PAYMENT_METHOD_WALLET_COINS],
            true
        ));
        $transactionIds = $walletOrders
            ->pluck('wallet_transaction_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $transactions = WalletTransaction::query()
            ->whereIn('id', $transactionIds)
            ->get()
            ->keyBy('id');
        $duplicateTransactionIds = $transactionIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('wallet_transaction_id', $transactionIds)
                ->groupBy('wallet_transaction_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('wallet_transaction_id')
                ->map(fn ($id): int => (int) $id)
                ->flip();

        return $orders->mapWithKeys(function (Order $order) use (
            $transactions,
            $duplicateTransactionIds
        ): array {
            if (!in_array($order->payment_method, [
                Order::PAYMENT_METHOD_WALLET,
                Order::PAYMENT_METHOD_WALLET_COINS,
            ], true)) {
                return [(int) $order->id => [
                    'total_coins' => 0,
                    'paid_coins' => 0,
                    'reward_coins' => 0,
                    'complete' => true,
                ]];
            }

            $transaction = $transactions->get((int) $order->wallet_transaction_id);
            $hasAmbiguousLink = $order->wallet_transaction_id !== null
                && $duplicateTransactionIds->has((int) $order->wallet_transaction_id);
            if (
                !$transaction
                || $hasAmbiguousLink
                || !$this->belongsToOrder($transaction, $order)
            ) {
                $hasCoinClaim = $order->wallet_transaction_id !== null
                    || (int) $order->total_coins > 0
                    || (int) $order->paid_coins > 0
                    || (int) $order->reward_coins > 0;

                return [(int) $order->id => [
                    'total_coins' => 0,
                    'paid_coins' => 0,
                    'reward_coins' => 0,
                    'complete' => !$hasCoinClaim,
                ]];
            }

            return [(int) $order->id => [
                'total_coins' => (int) $transaction->amount,
                'paid_coins' => (int) $transaction->paid_amount,
                'reward_coins' => (int) $transaction->reward_amount,
                'complete' => true,
            ]];
        });
    }

    /** @param Collection<int, Order> $orders */
    public function attachAllocations(Collection $orders): void
    {
        $allocations = $this->allocationsForOrders($orders);
        foreach ($orders as $order) {
            $allocation = $allocations->get((int) $order->id, [
                'total_coins' => 0,
                'paid_coins' => 0,
                'reward_coins' => 0,
                'complete' => false,
            ]);
            $order->setAttribute('ledger_total_coins', (int) $allocation['total_coins']);
            $order->setAttribute('ledger_paid_coins', (int) $allocation['paid_coins']);
            $order->setAttribute('ledger_reward_coins', (int) $allocation['reward_coins']);
            $order->setAttribute('coin_allocation_complete', (bool) $allocation['complete']);
        }
    }

    /**
     * @param Collection<int, int>|null $courseIds
     * @return Collection<int, array{total_buy_count:int,current_period_buy_count:int,total_coins:int,paid_coins:int,reward_coins:int,incomplete_orders:int}>
     */
    public function courseSummaries(
        ?Collection $courseIds = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null
    ): Collection {
        $query = Order::query()
            ->whereNotNull('course_id')
            ->whereIn('payment_method', [
                Order::PAYMENT_METHOD_WALLET,
                Order::PAYMENT_METHOD_WALLET_COINS,
            ])
            ->financiallyEffective()
            ->when(
                $courseIds !== null,
                fn ($orders) => $orders->whereIn('course_id', $courseIds)
            )
            ->orderBy('id');

        $summary = collect();
        $query->chunkById(500, function ($orders) use (
            $summary,
            $periodStart,
            $periodEnd
        ): void {
            $allocations = $this->allocationsForOrders($orders);
            foreach ($orders as $order) {
                $courseId = (int) $order->course_id;
                $row = $summary->get($courseId, [
                    'total_buy_count' => 0,
                    'current_period_buy_count' => 0,
                    'total_coins' => 0,
                    'paid_coins' => 0,
                    'reward_coins' => 0,
                    'incomplete_orders' => 0,
                ]);
                $allocation = $allocations->get((int) $order->id, [
                    'total_coins' => 0,
                    'paid_coins' => 0,
                    'reward_coins' => 0,
                    'complete' => false,
                ]);
                $row['total_buy_count']++;
                if (
                    $periodStart !== null
                    && $periodEnd !== null
                    && $order->approved_at !== null
                    && $order->approved_at->greaterThanOrEqualTo($periodStart)
                    && $order->approved_at->lessThan($periodEnd)
                ) {
                    $row['current_period_buy_count']++;
                }
                $row['total_coins'] += (int) $allocation['total_coins'];
                $row['paid_coins'] += (int) $allocation['paid_coins'];
                $row['reward_coins'] += (int) $allocation['reward_coins'];
                if (!(bool) $allocation['complete']) {
                    $row['incomplete_orders']++;
                }
                $summary->put($courseId, $row);
            }
        });

        return $summary;
    }

    private function belongsToOrder(WalletTransaction $transaction, Order $order): bool
    {
        return $transaction->direction === WalletTransaction::DIRECTION_DEBIT
            && in_array($transaction->category, self::DEBIT_CATEGORIES, true)
            && $transaction->source_type === Course::class
            && (int) $transaction->source_id === (int) $order->course_id
            && (int) $transaction->user_id === (int) $order->user_id
            && (int) $transaction->amount > 0
            && (int) $transaction->amount
                === (int) $transaction->paid_amount + (int) $transaction->reward_amount;
    }
}
