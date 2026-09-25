<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\StudentNotificationIntent;

use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class KashierOrderSettlementService
{
    public function __construct(
        private OrderLifecycleService $orderLifecycle,
        private KashierGatewayEvidenceService $evidence,
        private readonly StudentNotificationService $notifications,
    ) {
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function flagApprovedTransactionConflict(
        Order $order,
        ?string $transactionId,
        array $gatewayResponse
    ): bool {
        if ($order->status !== Order::STATUS_APPROVED || !$this->evidence->transactionIdConflicts($order, $transactionId)) {
            return false;
        }

        return DB::transaction(function () use ($order, $transactionId, $gatewayResponse): bool {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (
                $locked->status !== Order::STATUS_APPROVED
                || !$this->evidence->transactionIdConflicts($locked, $transactionId)
            ) {
                return false;
            }

            $this->flagCaptureForReview(
                $locked,
                'capture_transaction_conflict',
                $transactionId,
                $gatewayResponse
            );

            Log::critical('Kashier replay carried a different transaction identifier', [
                'order_ref' => $locked->order_ref,
                'order_id' => $locked->id,
                'stored_transaction_id' => $locked->transaction_id,
                'incoming_transaction_id' => $transactionId,
            ]);

            return true;
        }, 3);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function recordFinancialReversal(
        Order $order,
        string $type,
        string $paymentStatus,
        ?string $transactionId,
        array $params
    ): void {
        if (empty($params['_normalized_reversal_event'])) {
            $events = isset($params['reversal_events']) && is_array($params['reversal_events'])
                ? $params['reversal_events']
                : $this->evidence->reversalEvents($params);
            if ($events !== []) {
                foreach ($events as $event) {
                    if (!is_array($event)) continue;
                    $eventStatus = strtoupper(trim((string) ($event['payment_status'] ?? '')));
                    $eventType = $this->evidence->reversalType($eventStatus);
                    if ($eventType === null) continue;
                    $eventIdentity = trim((string) ($event['provider_event_id'] ?? ''));
                    if ($eventIdentity === '') {
                        $eventIdentity = 'evidence:' . trim((string) (
                            $event['evidence_fingerprint'] ?? hash('sha256', json_encode($event))
                        ));
                    }
                    $this->recordFinancialReversal(
                        $order,
                        $eventType,
                        $eventStatus,
                        $this->evidence->normalizeTransactionId(
                            $event['original_transaction_id'] ?? null
                        ) ?? $transactionId,
                        [
                            '_normalized_reversal_event' => true,
                            'eventId' => $eventIdentity,
                            'paymentStatus' => $eventStatus,
                            'amount' => $event['amount'] ?? null,
                            'currency' => $event['currency'] ?? null,
                            'occurred_at' => $event['occurred_at'] ?? null,
                            'original_transaction_id' => $event['original_transaction_id'] ?? null,
                        ]
                    );
                }

                return;
            }
        }

        $normalizedStatus = strtoupper(trim($paymentStatus));
        $eventStatus = in_array($normalizedStatus, [
            'PARTIAL_REFUND',
            'PARTIALLY_REFUNDED',
        ], true) ? 'partial_refund' : $type;
        $reason = 'Kashier reported payment status ' . $normalizedStatus . '.';
        $providerEventId = trim((string) (
            $params['eventId']
            ?? $params['event_id']
            ?? ''
        ));
        $eventIdentity = $providerEventId !== ''
            ? $providerEventId
            : ($transactionId ?? (string) $order->order_ref);
        // Kashier payloads do not always carry a unique event id. A payment
        // transaction is stable across later refund/chargeback states, so it
        // cannot be used raw as the provider-event uniqueness key. Derive one
        // per normalized state while keeping exact retries idempotent.
        $externalEventId = $providerEventId !== ''
            ? $providerEventId
            : ($transactionId !== null
                ? 'transaction-status:' . hash(
                    'sha256',
                    $eventStatus . '|' . $transactionId
                )
                : null);
        $eventKey = 'kashier:' . $eventStatus . ':'
            . hash('sha256', $eventIdentity);

        if (in_array($normalizedStatus, ['PARTIAL_REFUND', 'PARTIALLY_REFUNDED'], true)) {
            $this->orderLifecycle->flagExternalFinancialReview(
                $order,
                'partial_refund_reported',
                $reason,
                $eventKey,
                'kashier',
                $externalEventId,
                $this->evidence->sanitize($params)
            );

            return;
        }

        DB::transaction(function () use (
            $order,
            $type,
            $reason,
            $normalizedStatus,
            $transactionId,
            $params,
            $externalEventId,
            $eventKey
        ): void {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->user_id !== $expectedUserId) {
                throw new \RuntimeException('Kashier order ownership changed during reversal.');
            }

            // Keep the identity check and the reversal under the same locks.
            // Otherwise a capture can settle between both operations and a
            // stale reversal can reclaim a different transaction's value.
            if ($this->flagApprovedTransactionConflict($locked, $transactionId, $params)) {
                return;
            }

            $this->orderLifecycle->registerReversal(
                $locked,
                $type,
                $reason,
                $eventKey,
                null,
                'kashier',
                $externalEventId,
                $this->evidence->sanitize($params)
            );
        }, 3);
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function fulfillOrder(Order $order, ?string $transactionId, array $gatewayResponse): Order
    {
        $result = DB::transaction(
            fn (): array => $this->captureLockedOrder($order, $transactionId, $gatewayResponse),
            3
        );

        return $this->finishCapture($result, $transactionId);
    }

    /**
     * Local settlement only. Keep the user lock before the order lock and all
     * identity/evidence checks in this same transaction as wallet fulfillment.
     *
     * @param array<string, mixed> $gatewayResponse
     * @return array{order: Order, user: User, late_capture: bool, notify: bool}
     */
    private function captureLockedOrder(Order $order, ?string $transactionId, array $gatewayResponse): array
    {
        $expectedUserId = (int) $order->user_id;
        $lockedUser = User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
        $order = Order::with(['user', 'package'])->lockForUpdate()->findOrFail($order->id);
        if ((int) $order->user_id !== $expectedUserId) {
            throw new \RuntimeException('Kashier order ownership changed during fulfillment.');
        }

        if ($order->status === Order::STATUS_APPROVED) {
            if ($transactionId === null && !$order->transaction_id) {
                $order = $this->flagCaptureForReview(
                    $order,
                    'capture_transaction_missing',
                    null,
                    $gatewayResponse
                );
            } elseif ($transactionId !== null && (
                $this->evidence->transactionIdConflicts($order, $transactionId)
                || $this->transactionAssignedToAnotherOrder($order, $transactionId)
            )) {
                $order = $this->flagCaptureForReview(
                    $order,
                    'capture_transaction_conflict',
                    $transactionId,
                    $gatewayResponse
                );
                Log::critical('Concurrent Kashier fulfillment carried a different transaction identifier', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'stored_transaction_id' => $order->transaction_id,
                    'incoming_transaction_id' => $transactionId,
                ]);
            } else {
                if ($order->transaction_id === null && $transactionId !== null) {
                    $order->update(['transaction_id' => $transactionId]);
                }
                $this->evidence->assertMatches($order, $gatewayResponse);
                $settlementFacts = $this->evidence->settlementFacts($order, $gatewayResponse);
                if ($settlementFacts !== []) {
                    $order->update($settlementFacts);
                }
                if ($order->isFinanciallyEffective()) {
                    // Approval owns package crediting and the purchase
                    // receipt. Replaying a provider capture also repairs an
                    // interrupted local fulfilment without minting twice.
                    $order = $this->orderLifecycle->approve(
                        $order,
                        null,
                        $order->notes,
                        true
                    );
                }
            }

            return $this->captureResult($order, $lockedUser);
        }

        if (
            $order->payment_method !== Order::PAYMENT_METHOD_KASHIER
            || !$order->package_id
            || !$order->package
            || $order->packageCoinAmount() <= 0
            || (float) $order->final_amount <= 0
        ) {
            throw new \RuntimeException('Invalid Kashier package order.');
        }

        if ($order->reversed_at || in_array($order->financial_status, [
            Order::FINANCIAL_REFUNDED,
            Order::FINANCIAL_CHARGEBACK,
            Order::FINANCIAL_REVERSED,
            Order::FINANCIAL_PARTIALLY_RECOVERED,
        ], true)) {
            Log::warning('Kashier capture ignored because a reversal arrived first', [
                'order_ref' => $order->order_ref,
                'order_id' => $order->id,
                'financial_status' => $order->financial_status,
                'transaction_id' => $transactionId,
            ]);
            return $this->captureResult($order, $lockedUser);
        }

        if (!in_array($order->status, [
            Order::STATUS_PENDING,
            Order::STATUS_CANCELLED,
            Order::STATUS_REJECTED,
        ], true)) {
            throw new \RuntimeException('Kashier capture targets an unsupported order state.');
        }

        // A capture without a provider transaction identifier can never
        // be fulfilled. Quarantine it before parsing optional amount and
        // currency fields: sparse provider/webhook responses are valid
        // evidence for reconciliation, but never evidence for crediting.
        if ($transactionId === null) {
            $order = $this->flagCaptureForReview(
                $order,
                'capture_transaction_missing',
                null,
                $gatewayResponse
            );

            Log::critical('Kashier capture is missing a valid transaction identifier', [
                'order_ref' => $order->order_ref,
                'order_id' => $order->id,
            ]);

            return $this->captureResult($order, $lockedUser);
        }

        $this->evidence->assertMatches($order, $gatewayResponse);

        if (
            $order->financial_status === Order::FINANCIAL_REVIEW_REQUIRED
            && $this->hasReversalReviewEvidence($order)
        ) {
            $order->update(array_merge([
                'payment_gateway_response' => $this->evidence->sanitize($gatewayResponse),
            ], $this->evidence->settlementFacts($order, $gatewayResponse)));

            Log::critical('Kashier capture withheld because reversal evidence arrived first', [
                'order_ref' => $order->order_ref,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
            ]);

            return $this->captureResult($order, $lockedUser);
        }

        if (
            $transactionId
            && $this->transactionAssignedToAnotherOrder($order, $transactionId)
        ) {
            $order = $this->flagCaptureForReview(
                $order,
                'capture_transaction_conflict',
                $transactionId,
                $gatewayResponse
            );
            Log::critical('Kashier transaction was already assigned to another order', [
                'order_ref' => $order->order_ref,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
            ]);

            return $this->captureResult($order, $lockedUser);
        }

        $lateCapture = $order->status !== Order::STATUS_PENDING
            || $order->isCheckoutExpired();

        if ($lockedUser->trashed()) {
            $order->update(array_merge([
                'status' => Order::STATUS_APPROVED,
                'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                'transaction_id' => $transactionId,
                'approved_at' => now(),
                'payment_gateway_response' => $this->evidence->sanitize($gatewayResponse),
            ], $this->evidence->settlementFacts($order, $gatewayResponse)));
            $this->recordCaptureAfterAccountDeletion($order, $transactionId, $lockedUser);

            Log::critical('Kashier captured payment after the learner account was deleted', [
                'order_ref' => $order->order_ref,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
            ]);

            return $this->captureResult($order, $lockedUser, lateCapture: $lateCapture);
        }

        $order->update(array_merge([
            'transaction_id' => $transactionId,
            'payment_gateway_response' => $this->evidence->sanitize($gatewayResponse),
        ], $this->evidence->settlementFacts($order, $gatewayResponse)));

        // OrderLifecycleService owns the state transition and every local
        // side effect. Provider identity and settlement evidence are
        // attached first, while the order is still pending.
        $order = $this->orderLifecycle->approve(
            $order,
            null,
            $order->notes,
            true
        );

        if ($lateCapture) {
            // Redirects, webhooks and reconciliation can arrive out of
            // order. A provider-authenticated capture is still real money:
            // credit it exactly once instead of leaving a charged learner
            // waiting for manual review merely because a failure/timeout
            // notification won the race.
            Log::warning('Late Kashier capture recovered after checkout closure', [
                'order_ref' => $order->order_ref,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
            ]);
        }

        /** @var User $user */
        $user = User::withTrashed()->findOrFail($order->user_id);

        return $this->captureResult($order, $user, lateCapture: $lateCapture, notify: true);
    }

    /** @return array{order: Order, user: User, late_capture: bool, notify: bool} */
    private function captureResult(Order $order, User $user, bool $lateCapture = false, bool $notify = false): array
    {
        return [
            'order' => $order->fresh(['user', 'package']),
            'user' => $user,
            'late_capture' => $lateCapture,
            'notify' => $notify,
        ];
    }

    /** @param array{order: Order, user: User, late_capture: bool, notify: bool} $result */
    private function finishCapture(array $result, ?string $transactionId): Order
    {
        $order = $result['order'];
        $user = $result['user'];
        $lateCapture = $result['late_capture'];

        if ($lateCapture && $result['notify']) {
            try {
                $overlappingOrder = Order::query()
                    ->whereKeyNot($order->id)
                    ->where('user_id', $order->user_id)
                    ->where('package_id', $order->package_id)
                    ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                    ->financiallyEffective()
                    ->where('created_at', '>=', $order->created_at)
                    ->oldest('id')
                    ->first(['id', 'order_ref']);
                if ($overlappingOrder) {
                    $fingerprint = hash('sha256', implode('|', [
                        'kashier',
                        (string) $order->id,
                        'late_capture_overlap',
                        (string) $overlappingOrder->id,
                    ]));
                    PaymentReconciliationFinding::query()->firstOrCreate(
                        ['fingerprint' => $fingerprint],
                        [
                            'provider' => 'kashier',
                            'order_id' => $order->id,
                            'order_ref' => (string) $order->order_ref,
                            'kind' => 'late_capture_overlaps_newer_payment',
                            'local_status' => (string) $order->status,
                            'local_financial_status' => (string) $order->financial_status,
                            'provider_status' => 'CAPTURED',
                            'provider_transaction_id' => $transactionId,
                            'state' => PaymentReconciliationFinding::STATE_OPEN,
                            'attempts' => 1,
                            'first_seen_at' => now(),
                            'last_seen_at' => now(),
                            'evidence' => [
                                'overlapping_order_id' => (int) $overlappingOrder->id,
                                'overlapping_order_ref' => (string) $overlappingOrder->order_ref,
                            ],
                        ]
                    );
                }
            } catch (\Throwable $findingException) {
                report($findingException);
            }
        }

        if ($result['notify']) {
            try {
                if ($user->trashed()) {
                    return $order->fresh(['user', 'package']);
                }
                $this->notifications->notifyUser(
                    $user,
                    new StudentNotificationIntent(
                        notificationType: StudentNotificationService::TYPE_PACKAGE_PURCHASED,
                        titleAr: 'تم شحن رصيدك',
                        titleEn: 'Package Purchased',
                        messageAr: 'أضفنا ' . $order->packageCoinAmount()
                            . " عملة إلى محفظتك\nالرصيد جاهز للاستخدام",
                        messageEn: 'Package purchased successfully. ' . $order->packageCoinAmount() . ' coins added to your wallet.',
                        link: null,
                        notifiableType: Package::class,
                        notifiableId: $order->package_id,
                        deliveryKey: 'package-purchased:order:' . $order->id,
                        templateVariables: ['coins' => $order->packageCoinAmount()]
                    )
                );
            } catch (\Throwable $notificationException) {
                report($notificationException);
            }
        }

        return $order->fresh(['user', 'package']);
    }

    /**
     * Record a signed failure without overwriting a terminal successful state.
     *
     * @param array<string, mixed>|null $gatewayResponse
     */
    public function cancelPendingOrder(Order $order, ?array $gatewayResponse = null): Order
    {
        return DB::transaction(function () use ($order, $gatewayResponse): Order {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $locked = Order::with(['user', 'package'])->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->user_id !== $expectedUserId) {
                throw new \RuntimeException('Kashier order ownership changed while recording failure.');
            }

            if (
                $locked->status === Order::STATUS_PENDING
                && $locked->financial_status !== Order::FINANCIAL_REVIEW_REQUIRED
            ) {
                if ($gatewayResponse !== null) {
                    $locked->update([
                        'payment_gateway_response' => $this->evidence->sanitize($gatewayResponse),
                    ]);
                }
                $locked = $this->orderLifecycle->cancelPending($locked);
            }

            return $locked->fresh(['user', 'package']);
        }, 3);
    }

    private function transactionAssignedToAnotherOrder(Order $order, string $transactionId): bool
    {
        return Order::query()
            ->where('transaction_id', $transactionId)
            ->whereKeyNot($order->id)
            ->exists();
    }

    private function hasReversalReviewEvidence(Order $order): bool
    {
        return $order->financialEvents()
            ->whereIn('event_type', [
                'partial_refund',
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVERSED,
            ])
            ->exists();
    }

    /** @param array<string, mixed> $gatewayResponse */
    private function flagCaptureForReview(
        Order $order,
        string $reason,
        ?string $transactionId,
        array $gatewayResponse
    ): Order {
        $safeEvidence = $this->evidence->sanitize($gatewayResponse);
        $order->update(['payment_gateway_response' => $safeEvidence]);

        return $this->orderLifecycle->flagExternalFinancialReview(
            $order,
            $reason,
            'Kashier capture requires financial review.',
            'kashier:capture-review:' . hash('sha256', implode('|', [
                (string) $order->id,
                $reason,
                (string) $transactionId,
                json_encode($safeEvidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ])),
            'kashier',
            null,
            $safeEvidence
        );
    }

    private function recordCaptureAfterAccountDeletion(
        Order $order,
        string $transactionId,
        User $user
    ): void {
        $fingerprint = hash('sha256', implode('|', [
            'kashier',
            (string) $order->id,
            'capture_after_account_deletion',
            $transactionId,
        ]));
        PaymentReconciliationFinding::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'provider' => 'kashier',
                'order_id' => $order->id,
                'order_ref' => (string) $order->order_ref,
                'kind' => 'capture_after_account_deletion',
                'local_status' => (string) $order->status,
                'local_financial_status' => (string) $order->financial_status,
                'provider_status' => 'CAPTURED',
                'provider_transaction_id' => $transactionId,
                'state' => PaymentReconciliationFinding::STATE_OPEN,
                'attempts' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'evidence' => [
                    'account_deleted_at' => $user->deleted_at?->toIso8601String(),
                    'wallet_credit_withheld' => true,
                ],
            ]
        );
    }
}
