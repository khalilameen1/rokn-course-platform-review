<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Order;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
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
        // Aggregate in the database: one grouped statement instead of walking
        // every wallet course order in PHP. The joined ledger row is admitted
        // only under the exact rules of belongsToOrder(), and a debit linked
        // to more than one live order stays unattributed, as in
        // allocationsForOrders(). Per-order screens keep using that method.
        $linked = '(wallet_ledger.id IS NOT NULL AND NOT EXISTS ('
            . 'SELECT 1 FROM orders AS sibling_orders'
            . ' WHERE sibling_orders.wallet_transaction_id = orders.wallet_transaction_id'
            . ' AND sibling_orders.id <> orders.id'
            . ' AND sibling_orders.deleted_at IS NULL))';
        $coinClaim = '(orders.wallet_transaction_id IS NOT NULL'
            . ' OR COALESCE(orders.total_coins, 0) > 0'
            . ' OR COALESCE(orders.paid_coins, 0) > 0'
            . ' OR COALESCE(orders.reward_coins, 0) > 0)';
        $countsPeriod = $periodStart !== null && $periodEnd !== null;
        $periodBindings = $countsPeriod
            ? [$this->databaseInstant($periodStart), $this->databaseInstant($periodEnd)]
            : [];

        $rows = Order::query()
            ->leftJoin('wallet_transactions as wallet_ledger', function ($join): void {
                $join->on('wallet_ledger.id', '=', 'orders.wallet_transaction_id')
                    ->on('wallet_ledger.source_id', '=', 'orders.course_id')
                    ->on('wallet_ledger.user_id', '=', 'orders.user_id')
                    ->where('wallet_ledger.direction', '=', WalletTransaction::DIRECTION_DEBIT)
                    ->whereIn('wallet_ledger.category', self::DEBIT_CATEGORIES)
                    ->where('wallet_ledger.source_type', '=', Course::class)
                    ->where('wallet_ledger.amount', '>', 0)
                    ->whereRaw(
                        'wallet_ledger.amount = wallet_ledger.paid_amount + wallet_ledger.reward_amount'
                    );
            })
            ->whereNotNull('orders.course_id')
            ->whereIn('orders.payment_method', [
                Order::PAYMENT_METHOD_WALLET,
                Order::PAYMENT_METHOD_WALLET_COINS,
            ])
            // The scope's unqualified columns exist only on orders, never on
            // wallet_transactions, so they stay unambiguous after the join.
            ->financiallyEffective()
            ->when(
                $courseIds !== null,
                fn ($orders) => $orders->whereIn('orders.course_id', $courseIds)
            )
            ->groupBy('orders.course_id')
            // Preserve the first-seen course order of the former id-ordered walk.
            ->orderByRaw('MIN(orders.id)')
            ->selectRaw('orders.course_id AS course_id')
            ->selectRaw('COUNT(*) AS total_buy_count')
            ->selectRaw(
                $countsPeriod
                    ? 'SUM(CASE WHEN orders.approved_at >= ? AND orders.approved_at < ? THEN 1 ELSE 0 END)'
                        . ' AS current_period_buy_count'
                    : '0 AS current_period_buy_count',
                $periodBindings
            )
            ->selectRaw("SUM(CASE WHEN {$linked} THEN wallet_ledger.amount ELSE 0 END) AS total_coins")
            ->selectRaw("SUM(CASE WHEN {$linked} THEN wallet_ledger.paid_amount ELSE 0 END) AS paid_coins")
            ->selectRaw("SUM(CASE WHEN {$linked} THEN wallet_ledger.reward_amount ELSE 0 END) AS reward_coins")
            ->selectRaw(
                "SUM(CASE WHEN NOT {$linked} AND {$coinClaim} THEN 1 ELSE 0 END) AS incomplete_orders"
            )
            ->toBase()
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [(int) $row->course_id => [
            'total_buy_count' => (int) $row->total_buy_count,
            'current_period_buy_count' => (int) $row->current_period_buy_count,
            'total_coins' => (int) $row->total_coins,
            'paid_coins' => (int) $row->paid_coins,
            'reward_coins' => (int) $row->reward_coins,
            'incomplete_orders' => (int) $row->incomplete_orders,
        ]]);
    }

    /** Stored timestamps use the application timezone (see config/app.php). */
    private function databaseInstant(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i:s');
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
