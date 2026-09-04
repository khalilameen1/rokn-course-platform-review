<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bill;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\OrderFinancialEvent;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

final readonly class OrderLifecycleService
{
    public function __construct(
        private WalletService $wallet,
        private FinancialProvenanceService $provenance
    ) {
    }

    /** Approval side effects use order-scoped idempotency keys. */
    public function approve(
        Order $order,
        ?int $actorId = null,
        ?string $notes = null,
        bool $providerVerified = false
    ): Order
    {
        return DB::transaction(function () use (
            $order,
            $actorId,
            $notes,
            $providerVerified
        ): Order {
            // Financial callbacks may arrive after the user has deleted their account.
            // Keep the anonymized aggregate lockable without reopening account access.
            User::withTrashed()->lockForUpdate()->findOrFail($order->user_id);
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertOrderShape($locked);

            if ($locked->reversed_at || in_array($locked->financial_status, [
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVERSED,
                Order::FINANCIAL_PARTIALLY_RECOVERED,
            ], true)) {
                throw new \DomainException('A financially reversed order cannot be approved again.');
            }

            if ($locked->status !== Order::STATUS_APPROVED) {
                if (
                    $actorId !== null
                    && !$providerVerified
                    && $locked->status !== Order::STATUS_PENDING
                ) {
                    throw new \DomainException(
                        'Only a pending manual order can be approved by an administrator.'
                    );
                }
                if (
                    $locked->requiresProviderVerification()
                    && !$providerVerified
                ) {
                    throw new \DomainException(
                        'Provider-controlled orders require verified provider evidence.'
                    );
                }
                if (
                    $locked->course_id
                    && $locked->payment_method === Order::PAYMENT_METHOD_WALLET_COINS
                ) {
                    throw new \DomainException(
                        'Wallet course orders can only be created by the wallet purchase flow.'
                    );
                }

                $locked->forceFill([
                    'status' => Order::STATUS_APPROVED,
                    'financial_status' => Order::FINANCIAL_SETTLED,
                    'approved_at' => now(),
                    'approved_by' => $actorId,
                    'notes' => $notes ?? $locked->notes,
                ])->save();
            } elseif ($locked->financial_status !== Order::FINANCIAL_SETTLED) {
                // Operational approval is not a finance-review resolution.
                // Replaying approve must never erase a provider conflict or
                // another non-settled financial state.
                throw new \DomainException(
                    'An approved order with a non-settled financial state cannot be approved again.'
                );
            }

            if ($locked->package_id) {
                $this->fulfillPackage($locked);
            } else {
                $this->fulfillCourse($locked);
            }
            if ($locked->course_id) {
                $this->syncBill($locked, Bill::PAYMENT_STATUS_PAID);
            }
            $this->recordEvent($locked, 'approved', 'approval', $actorId, $notes);

            return $locked->fresh($locked->package_id
                ? ['package', 'user']
                : ['bill', 'course', 'user']);
        }, 3);
    }

    /** Only pending orders can be rejected. */
    public function rejectPending(Order $order, ?int $actorId = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($actorId !== null && $locked->requiresProviderVerification()) {
                throw new \DomainException(
                    'Provider-controlled orders cannot be changed manually.'
                );
            }
            if ($locked->status === Order::STATUS_REJECTED) {
                return $this->freshWithReceipt($locked);
            }
            if ($locked->status !== Order::STATUS_PENDING) {
                throw new \DomainException(
                    'Only a pending order can be rejected.'
                );
            }

            $locked->forceFill([
                'status' => Order::STATUS_REJECTED,
                'financial_status' => Order::FINANCIAL_REJECTED,
                'approved_at' => null,
                'approved_by' => null,
                'notes' => $reason ?? $locked->notes,
            ])->save();
            if ($locked->course_id) {
                $this->syncBill($locked, Bill::PAYMENT_STATUS_CANCELLED);
            }
            $this->recordEvent($locked, 'rejected', 'rejection', $actorId, $reason);

            return $this->freshWithReceipt($locked);
        }, 3);
    }

    public function cancelPending(Order $order, ?int $actorId = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($actorId !== null && $locked->requiresProviderVerification()) {
                throw new \DomainException(
                    'Provider-controlled orders cannot be changed manually.'
                );
            }
            if ($locked->status === Order::STATUS_CANCELLED) {
                return $this->freshWithReceipt($locked);
            }
            if ($locked->status !== Order::STATUS_PENDING) {
                throw new \DomainException(
                    'Only a pending order can be cancelled.'
                );
            }
            $locked->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'financial_status' => Order::FINANCIAL_CANCELLED,
                'notes' => $reason ?? $locked->notes,
            ])->save();
            if ($locked->course_id) {
                $this->syncBill($locked, Bill::PAYMENT_STATUS_CANCELLED);
            }
            $this->recordEvent($locked, 'cancelled', 'cancellation', $actorId, $reason);

            return $this->freshWithReceipt($locked);
        }, 3);
    }

    /** Record an external reversal exactly once with paid-source attribution. */
    public function registerReversal(
        Order $order,
        string $type,
        string $reason,
        string $eventKey,
        ?int $actorId = null,
        ?string $provider = null,
        ?string $externalEventId = null,
        array $payload = []
    ): Order {
        $allowed = [
            Order::FINANCIAL_REFUNDED,
            Order::FINANCIAL_CHARGEBACK,
            Order::FINANCIAL_REVERSED,
        ];
        if (!in_array($type, $allowed, true) || trim($eventKey) === '') {
            throw new \InvalidArgumentException('Invalid financial reversal event.');
        }

        return DB::transaction(function () use (
            $order,
            $type,
            $reason,
            $eventKey,
            $actorId,
            $provider,
            $externalEventId,
            $payload
        ): Order {
            User::withTrashed()->lockForUpdate()->findOrFail($order->user_id);
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = OrderFinancialEvent::query()
                ->where('order_id', $locked->id)
                ->where('event_key', $eventKey)
                ->first();
            if ($existing) {
                return $this->freshWithReceipt($locked);
            }

            $locked->loadMissing('package');
            $wasFulfilled = $locked->status === Order::STATUS_APPROVED;
            $atRisk = $locked->package_id
                ? max(0, (int) $locked->package_coins)
                : max(0, (int) $locked->total_coins);
            $result = !$wasFulfilled
                ? ['recovered' => 0, 'unrecovered' => 0, 'holds' => 0]
                : ($locked->package_id
                    ? $this->provenance->applyPackageReversal($locked, $reason)
                    : ['recovered' => 0, 'unrecovered' => $atRisk, 'holds' => 0]);
            $locked->forceFill([
                // A provider reversal may win the race against capture. Close
                // an unfulfilled order under this same lock so a delayed
                // capture cannot mint coins between cancellation and marking
                // the reversal. Fulfilled orders still enter finance review
                // because their paid lots and derived entitlements are being
                // recovered atomically above.
                'status' => $wasFulfilled ? $locked->status : Order::STATUS_CANCELLED,
                'financial_status' => $wasFulfilled
                    ? Order::FINANCIAL_REVIEW_REQUIRED
                    : $type,
                'reversed_at' => $locked->reversed_at ?: now(),
                'reversal_reason' => $reason,
                'recovered_coins' => (int) $result['recovered'],
                'unrecovered_coins' => (int) $result['unrecovered'],
            ])->save();
            if ($locked->course_id) {
                $this->syncBill($locked, Bill::PAYMENT_STATUS_CANCELLED);
            }
            $this->recordEvent(
                $locked,
                $type,
                $eventKey,
                $actorId,
                $reason,
                $provider,
                $externalEventId,
                $payload,
                (int) $result['recovered'],
                (int) $result['unrecovered']
            );

            return $this->freshWithReceipt($locked);
        }, 3);
    }

    /**
     * Persist provider evidence which changes the financial truth but cannot
     * be applied safely without an exact amount. In particular, a partial
     * refund must never reclaim the whole coin lot merely because the provider
     * sent a coarse status before its settlement breakdown.
     */
    public function flagExternalFinancialReview(
        Order $order,
        string $eventType,
        string $reason,
        string $eventKey,
        ?string $provider = null,
        ?string $externalEventId = null,
        array $payload = []
    ): Order {
        if (trim($eventType) === '' || trim($eventKey) === '') {
            throw new \InvalidArgumentException('Invalid financial review event.');
        }

        return DB::transaction(function () use (
            $order,
            $eventType,
            $reason,
            $eventKey,
            $provider,
            $externalEventId,
            $payload
        ): Order {
            User::withTrashed()->lockForUpdate()->findOrFail($order->user_id);
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = OrderFinancialEvent::query()
                ->where('order_id', $locked->id)
                ->where('event_key', $eventKey)
                ->first();
            if ($existing) {
                return $this->freshWithReceipt($locked);
            }

            if (!in_array($locked->financial_status, [
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVERSED,
                Order::FINANCIAL_PARTIALLY_RECOVERED,
            ], true)) {
                $locked->forceFill([
                    'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                    'reversal_reason' => $reason,
                ])->save();
            }
            $this->recordEvent(
                $locked,
                $eventType,
                $eventKey,
                null,
                $reason,
                $provider,
                $externalEventId,
                $payload
            );

            return $this->freshWithReceipt($locked);
        }, 3);
    }

    /** Resolve a reviewed reversal without rewriting its financial history. */
    public function resolveFinancialReview(
        Order $order,
        string $resolution,
        string $eventKey,
        ?int $actorId = null,
        ?string $note = null
    ): Order {
        if (!in_array($resolution, ['repaid', 'waived'], true) || trim($eventKey) === '') {
            throw new \InvalidArgumentException('Invalid financial review resolution.');
        }

        return DB::transaction(function () use (
            $order,
            $resolution,
            $eventKey,
            $actorId,
            $note
        ): Order {
            User::withTrashed()->lockForUpdate()->findOrFail($order->user_id);
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = OrderFinancialEvent::query()
                ->where('order_id', $locked->id)
                ->where('event_key', $eventKey)
                ->first();
            if ($existing) {
                if ($existing->event_type !== 'resolution_' . $resolution) {
                    throw new \UnexpectedValueException(
                        'Financial resolution event key was reused for another decision.'
                    );
                }

                return $this->freshWithReceipt($locked);
            }
            if (
                !$locked->package_id
                || $locked->financial_status !== Order::FINANCIAL_REVIEW_REQUIRED
            ) {
                throw new \DomainException('Only a package under financial review can be resolved.');
            }

            $result = $this->provenance->resolvePackageReversal(
                $locked,
                $resolution,
                $actorId,
                $note
            );
            if ($resolution === 'repaid') {
                $locked->forceFill([
                    'financial_status' => Order::FINANCIAL_SETTLED,
                    'reversed_at' => null,
                    'reversal_reason' => null,
                    'recovered_coins' => 0,
                    'unrecovered_coins' => 0,
                ])->save();
                $this->syncBill($locked, Bill::PAYMENT_STATUS_PAID);
            } else {
                $locked->forceFill([
                    'financial_status' => Order::FINANCIAL_REVERSED,
                    'reversal_reason' => $note ?: $locked->reversal_reason,
                ])->save();
                $this->syncBill($locked, Bill::PAYMENT_STATUS_CANCELLED);
            }

            $this->recordEvent(
                $locked,
                'resolution_' . $resolution,
                $eventKey,
                $actorId,
                $note,
                null,
                null,
                $result,
                $resolution === 'repaid' ? (int) $result['restored_coins'] : 0,
                $resolution === 'waived' ? (int) $locked->unrecovered_coins : 0
            );

            return $this->freshWithReceipt($locked);
        }, 3);
    }

    public function reconcile(Order $order): Order
    {
        $fresh = $order->fresh();
        if ($fresh->status === Order::STATUS_APPROVED && !$fresh->reversed_at) {
            return $this->approve($fresh, $fresh->approved_by, $fresh->notes);
        }
        if ($fresh->status === Order::STATUS_REJECTED) {
            return $this->rejectPending($fresh, null, $fresh->notes);
        }
        if ($fresh->status === Order::STATUS_CANCELLED) {
            return $this->cancelPending($fresh, null, $fresh->notes);
        }

        return $fresh;
    }

    /** Credit a verified Rokn-side service failure without falsifying the cash receipt. */
    public function compensateCourseOrder(
        Order $order,
        int $amount,
        string $reason,
        string $eventKey,
        ?int $actorId = null
    ): Order {
        if ($amount <= 0 || trim($reason) === '' || trim($eventKey) === '') {
            throw new \InvalidArgumentException('Invalid course compensation.');
        }

        return DB::transaction(function () use ($order, $amount, $reason, $eventKey, $actorId): Order {
            User::withTrashed()->lockForUpdate()->findOrFail($order->user_id);
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existingEvent = OrderFinancialEvent::query()
                ->where('order_id', $locked->id)
                ->where('event_key', $eventKey)
                ->first();
            if ($existingEvent) return $locked->fresh();

            if (
                !$locked->course_id
                || $locked->package_id
                || $locked->payment_method !== Order::PAYMENT_METHOD_WALLET_COINS
                || $locked->status !== Order::STATUS_APPROVED
                || $locked->financial_status !== Order::FINANCIAL_SETTLED
            ) {
                throw new \DomainException('Only a settled wallet course order can be compensated.');
            }

            $debit = $locked->walletTransaction()->lockForUpdate()->first();
            if (
                !$debit
                || $debit->direction !== WalletTransaction::DIRECTION_DEBIT
                || (int) $debit->user_id !== (int) $locked->user_id
                || (int) $debit->amount !== (int) $locked->total_coins
            ) {
                throw new \DomainException('This legacy order has no verifiable wallet debit.');
            }

            $alreadyCompensated = (int) WalletTransaction::query()
                ->where('user_id', $locked->user_id)
                ->where('direction', WalletTransaction::DIRECTION_CREDIT)
                ->where('category', 'course_service_compensation')
                ->where('source_type', Order::class)
                ->where('source_id', $locked->id)
                ->sum('amount');
            if ($amount > max(0, (int) $debit->amount - $alreadyCompensated)) {
                throw new \DomainException('Compensation exceeds the remaining order amount.');
            }

            $credit = $this->wallet->refundDebit(
                (int) $locked->user_id,
                $amount,
                'course_service_compensation',
                'course-compensation:' . hash('sha256', $locked->id . '|' . $eventKey),
                $debit,
                $locked,
                [
                    'order_id' => (int) $locked->id,
                    'reason' => trim($reason),
                    'approved_by' => $actorId,
                ]
            );
            $this->provenance->recordPaidCompensationCredit($debit, $credit);
            $this->recordEvent(
                $locked,
                'course_compensation',
                $eventKey,
                $actorId,
                trim($reason),
                null,
                null,
                ['wallet_transaction_id' => (int) $credit->id, 'amount' => $amount]
            );

            return $locked->fresh();
        }, 3);
    }

    public function expectedBillStatus(Order $order): string
    {
        // A transaction-identity conflict quarantines future finance actions,
        // but it does not erase the already captured receipt. Bills follow the
        // cash receipt; entitlement checks remain stricter through
        // isFinanciallyEffective(). A real reversal always stamps reversed_at.
        if (
            $order->status === Order::STATUS_APPROVED
            && $order->reversed_at === null
            && in_array($order->financial_status, [
                Order::FINANCIAL_SETTLED,
                Order::FINANCIAL_REVIEW_REQUIRED,
            ], true)
        ) {
            return Bill::PAYMENT_STATUS_PAID;
        }

        return $order->status === Order::STATUS_PENDING
            ? Bill::PAYMENT_STATUS_PENDING
            : Bill::PAYMENT_STATUS_CANCELLED;
    }

    public function reconcileBill(Bill $bill): Bill
    {
        return DB::transaction(function () use ($bill): Bill {
            // Every other lifecycle transition locks order before bill. Keep
            // the same order here so dashboard reconciliation cannot deadlock
            // a provider callback that is settling or reversing this order.
            $order = Order::query()->lockForUpdate()->findOrFail($bill->order_id);
            Bill::query()
                ->whereKey($bill->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->syncBill($order, $this->expectedBillStatus($order));
        }, 3);
    }

    private function assertOrderShape(Order $order): void
    {
        if ((bool) $order->course_id === (bool) $order->package_id) {
            throw new \DomainException('An order must reference exactly one course or coin package.');
        }
        if (!$order->user_id) {
            throw new \DomainException('An order must belong to a learner.');
        }
    }

    private function freshWithReceipt(Order $order): Order
    {
        return $order->fresh($order->course_id ? ['bill'] : []);
    }

    private function fulfillPackage(Order $order): void
    {
        $order->loadMissing(['package', 'user']);
        $coins = max(0, (int) $order->package_coins);
        if (!$order->package || !$order->user || $coins <= 0 || (float) $order->final_amount <= 0) {
            throw new \DomainException('Coin package order is incomplete and cannot be approved.');
        }
        $existingCredit = WalletTransaction::query()
            ->where('user_id', $order->user_id)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('category', 'package_purchase')
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->first();
        if (!$existingCredit) {
            $existingCredit = $this->wallet->credit(
                (int) $order->user_id,
                $coins,
                'package_purchase',
                'order-lifecycle:package-credit:' . $order->id,
                $order,
                [
                    'package_id' => $order->package_id,
                    'transaction_id' => $order->transaction_id,
                ],
                WalletTransaction::BUCKET_PAID
            );
        }
        $this->provenance->recordPaidPackageCredit($order, $existingCredit);

        DB::table('package_user')->updateOrInsert(
            ['order_id' => $order->id],
            [
                'user_id' => $order->user_id,
                'package_id' => $order->package_id,
                'price' => $order->final_amount,
                'coins' => $coins,
                'created_at' => $order->approved_at ?: now(),
                'updated_at' => now(),
            ]
        );
    }

    private function fulfillCourse(Order $order): void
    {
        $order->loadMissing(['course', 'user']);
        if (
            !$order->course
            || !$order->user
            || !$order->course->isPublishedForLearning()
        ) {
            throw new \DomainException('Course order is incomplete and cannot be approved.');
        }

        $enrollment = CourseEnrollment::query()
            ->where('user_id', $order->user_id)
            ->where('course_id', $order->course_id)
            ->lockForUpdate()
            ->first() ?: new CourseEnrollment([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id,
                'enrolled_at' => now(),
            ]);
        $enrollment->forceFill([
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => $enrollment->access_granted_at ?: now(),
            'expires_at' => null,
        ])->save();
    }

    private function syncBill(Order $order, string $status): Bill
    {
        /** @var Bill $bill */
        $bill = Bill::withTrashed()->where('order_id', $order->id)->lockForUpdate()->first()
            ?: new Bill(['order_id' => $order->id]);
        if ($bill->trashed()) {
            $bill->restore();
        }
        $bill->forceFill([
            'user_id' => $order->user_id,
            'course_id' => $order->course_id,
            'bill_number' => $bill->bill_number ?: Bill::numberForOrder((int) $order->id),
            'amount' => $order->amount,
            'tax_amount' => 0,
            'total_amount' => $order->final_amount,
            'payment_status' => $status,
            'payment_method' => $order->payment_method,
            'due_date' => $order->created_at ?: now(),
            'paid_at' => $status === Bill::PAYMENT_STATUS_PAID
                ? ($bill->paid_at ?: $order->approved_at ?: now())
                : null,
            'notes' => $order->reversal_reason ?: $order->notes,
        ])->save();

        return $bill;
    }

    private function recordEvent(
        Order $order,
        string $type,
        string $key,
        ?int $actorId,
        ?string $reason,
        ?string $provider = null,
        ?string $externalEventId = null,
        array $payload = [],
        int $recovered = 0,
        int $unrecovered = 0
    ): OrderFinancialEvent {
        return OrderFinancialEvent::query()->firstOrCreate(
            ['order_id' => $order->id, 'event_key' => $key],
            [
                'actor_id' => $actorId,
                'event_type' => $type,
                'provider' => $provider,
                'external_event_id' => $externalEventId,
                'recovered_coins' => $recovered,
                'unrecovered_coins' => $unrecovered,
                'reason' => $reason,
                'payload' => $payload ?: null,
                'occurred_at' => now(),
            ]
        );
    }
}
