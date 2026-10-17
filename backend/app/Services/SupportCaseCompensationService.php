<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackReport;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/** Binds verified order compensation to a case; the order ledger owns the credit. */
final class SupportCaseCompensationService
{
    public function __construct(
        private readonly OrderLifecycleService $orders,
        private readonly SupportCaseService $cases
    ) {
    }

    /**
     * Coins that can still be credited for a verified service failure.
     *
     * Read-only mirror of the eligibility OrderLifecycleService::compensateCourseOrder
     * enforces under lock, so the form shows the same ceiling the ledger will
     * accept. Zero means the order cannot be compensated.
     */
    public function remainingCompensableCoins(?Order $order): int
    {
        if (
            !$order
            || !$order->course_id
            || $order->package_id
            || $order->payment_method !== Order::PAYMENT_METHOD_WALLET_COINS
            || $order->status !== Order::STATUS_APPROVED
            || $order->financial_status !== Order::FINANCIAL_SETTLED
            || !$order->wallet_transaction_id
        ) {
            return 0;
        }
        $debit = WalletTransaction::query()->find($order->wallet_transaction_id);
        if (
            !$debit
            || $debit->direction !== WalletTransaction::DIRECTION_DEBIT
            || (int) $debit->user_id !== (int) $order->user_id
            || (int) $debit->amount !== (int) $order->total_coins
        ) {
            return 0;
        }
        $alreadyCompensated = (int) WalletTransaction::query()
            ->where('user_id', $order->user_id)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('category', 'course_service_compensation')
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->sum('amount');

        return max(0, (int) $debit->amount - $alreadyCompensated);
    }

    /** @param array<string, mixed> $validated Validated amount, note and case version. */
    public function compensate(FeedbackReport $feedback, array $validated, ?int $actorId): void
    {
        abort_unless($feedback->order_id, 422);
        $eventKey = 'support-case-compensation:'.$feedback->id.':'.hash('sha256', $validated['amount'].'|'.trim($validated['note']));

        DB::transaction(function () use ($feedback, $validated, $eventKey, $actorId): void {
            if ($feedback->user_id) {
                User::withTrashed()->whereKey($feedback->user_id)->lockForUpdate()->firstOrFail();
            }
            $order = Order::query()->lockForUpdate()->findOrFail($feedback->order_id);
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($feedback->id);
            abort_if((int) $locked->version !== (int) $validated['version'], 409, "تغيّرت الحالة\nحدّث الصفحة قبل تسجيل التعويض");
            abort_unless(
                (int) $order->id === (int) $locked->order_id
                && (int) $order->user_id === (int) $locked->user_id,
                422
            );
            $this->orders->compensateCourseOrder(
                $order,
                (int) $validated['amount'],
                trim($validated['note']),
                $eventKey,
                $actorId
            );
            $locked->update(['resolution_kind' => 'compensated', 'version' => (int) $locked->version + 1]);
            $this->cases->event($locked, $actorId, 'compensated', null, null, [
                'order_id' => $locked->order_id,
                'compensation_event_key' => $eventKey,
            ]);
        }, 3);
    }
}
