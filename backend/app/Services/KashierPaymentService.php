<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class KashierPaymentService
{
    public function __construct(
        private readonly OrderLifecycleService $orderLifecycle,
        private readonly PackageChannelPricingService $pricing,
        private readonly KashierProviderOrderService $providerOrders,
        private readonly KashierGatewayEvidenceService $evidence,
    ) {
    }

    /**
     * @return array{order: Order, reused: bool, closed: ?string}
     */
    public function beginCheckout(
        User $user,
        Package $package,
        string $clientRequestKey,
        ?float $expectedAmount = null,
        ?int $expectedCoins = null
    ): array
    {
        return DB::transaction(function () use (
            $user,
            $package,
            $clientRequestKey,
            $expectedAmount,
            $expectedCoins
        ): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // The package is catalogue input, not the learner's financial
            // aggregate. Locking it serialized every buyer of the same popular
            // package. Published store coin terms are immutable and direct
            // price/coin facts are copied into the order below, so a consistent
            // transaction read is sufficient here.
            $package = Package::query()->findOrFail($package->id);

            $existing = null;
            if ($clientRequestKey !== '') {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('checkout_request_key', $clientRequestKey)
                    ->first();

                if (
                    $existing
                    && (
                        (int) $existing->package_id !== (int) $package->id
                        || $existing->payment_method !== Order::PAYMENT_METHOD_KASHIER
                    )
                ) {
                    throw new \UnexpectedValueException(
                        'Checkout idempotency key was reused for another package.'
                    );
                }
            } else {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('package_id', $package->id)
                    ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                    ->where('status', Order::STATUS_PENDING)
                    ->where('created_at', '>=', now()->subMinutes(10))
                    ->where(function ($query): void {
                        $query->whereNull('checkout_expires_at')
                            ->orWhere('checkout_expires_at', '>', now());
                    })
                    ->latest('id')
                    ->first();
            }

            if ($existing) {
                if (
                    ($expectedAmount !== null
                        && (int) round((float) $existing->final_amount * 100)
                            !== (int) round($expectedAmount * 100))
                    || ($expectedCoins !== null
                        && (int) $existing->package_coins !== $expectedCoins)
                ) {
                    throw new \UnexpectedValueException(
                        'Checkout idempotency key was replayed with different package terms.'
                    );
                }
                if ($existing->isCheckoutExpired()) {
                    return [
                        'order' => $existing,
                        'reused' => true,
                        'closed' => 'expired',
                    ];
                }

                if ($existing->status !== Order::STATUS_PENDING) {
                    return [
                        'order' => $existing,
                        'reused' => true,
                        'closed' => 'closed',
                    ];
                }

                return ['order' => $existing, 'reused' => true, 'closed' => null];
            }

            if (
                !$package->is_active
                || !$package->direct_enabled
                || (float) $package->price <= 0
                || (int) $package->coins <= 0
            ) {
                throw new \UnexpectedValueException(
                    'This package is not available for checkout.'
                );
            }

            $otherPendingCheckout = Order::query()
                ->where('user_id', $user->id)
                ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                ->where('status', Order::STATUS_PENDING)
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($otherPendingCheckout) {
                throw new \UnexpectedValueException(
                    'A previous payment is still pending confirmation.'
                );
            }

            $baseAmount = (float) $package->price;
            $finalAmount = $this->pricing->directPrice($package);
            if (
                ($expectedAmount !== null
                    && (int) round($finalAmount * 100) !== (int) round($expectedAmount * 100))
                || ($expectedCoins !== null && (int) $package->coins !== $expectedCoins)
            ) {
                throw new \UnexpectedValueException(
                    'Package terms changed before checkout.'
                );
            }
            $order = Order::create([
                'user_id' => $user->id,
                'package_id' => $package->id,
                'package_coins' => (int) $package->coins,
                'payment_method' => Order::PAYMENT_METHOD_KASHIER,
                'order_ref' => 'PKG-' . strtoupper(str_replace('-', '', (string) Str::uuid())),
                'checkout_request_key' => $clientRequestKey !== ''
                    ? $clientRequestKey
                    : 'server-' . (string) Str::uuid(),
                'checkout_expires_at' => now()->addMinutes(Order::KASHIER_CHECKOUT_TTL_MINUTES),
                'amount' => $baseAmount,
                'discount_amount' => round($baseAmount - $finalAmount, 2),
                'final_amount' => $finalAmount,
                'status' => Order::STATUS_PENDING,
                'financial_status' => Order::FINANCIAL_PENDING,
                'is_premium_user' => $user->isPremiumUser(),
            ]);

            return ['order' => $order, 'reused' => false, 'closed' => null];
        }, 3);
    }

    public function isValidOrderReference(mixed $orderRef): bool
    {
        return $this->providerOrders->isValidReference($orderRef);
    }

    public function normalizeTransactionId(mixed $value): ?string
    {
        return $this->evidence->normalizeTransactionId($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyOrderViaApi(string $orderRef): ?array
    {
        return $this->providerOrders->fetch($orderRef);
    }

    /**
     * @param array<string, mixed>|null $apiResponse
     */
    public function isOrderCaptured(?array $apiResponse): bool
    {
        return $this->evidence->isCaptureStatus($this->evidence->status($apiResponse));
    }

    public function isCaptureNotificationStatus(?string $status): bool
    {
        return $this->evidence->isCaptureStatus($status);
    }

    /**
     * @param array<string, mixed>|null $apiResponse
     */
    public function providerOrderStatus(?array $apiResponse): ?string
    {
        return $this->evidence->status($apiResponse);
    }

    /**
     * Return compact, stable evidence for every successful provider-side
     * reversal. Generic REFUND operations are classified for review rather
     * than assumed to be full refunds; only an explicit FULL marker may cause
     * the whole paid lot to be reclaimed automatically.
     *
     * @param array<string, mixed>|null $apiResponse
     * @return array<int, array{payment_status:string,provider_event_id:?string,original_transaction_id:?string,amount:int|float|string|null,currency:?string,occurred_at:?string,evidence_fingerprint:string}>
     */
    public function extractFinancialReversalEvents(?array $apiResponse): array
    {
        return $this->evidence->reversalEvents($apiResponse);
    }

    public function isProviderPendingStatus(?string $status): bool
    {
        return $this->evidence->isPendingStatus($status);
    }

    /**
     * An authorization/processing state may still turn into a charge without
     * another learner action. A merely opened HPP order may be abandoned and
     * replaced; a late authenticated capture is still recovered by
     * fulfillOrder() exactly once.
     */
    public function providerStatusMayCaptureWithoutLearner(?string $status): bool
    {
        return $this->evidence->mayCaptureWithoutLearner($status);
    }

    public function isProviderFailureStatus(?string $status): bool
    {
        return $this->evidence->isFailureStatus($status);
    }

    /**
     * @param array<string, mixed>|null $apiResponse
     */
    public function extractTransactionId(?array $apiResponse): ?string
    {
        return $this->evidence->transactionId($apiResponse);
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    public function captureEvidenceWithTransactionId(
        string $orderRef,
        ?string $transactionId,
        array $gatewayResponse
    ): array {
        if ($transactionId !== null) {
            return [$transactionId, $gatewayResponse];
        }

        $apiResponse = $this->verifyOrderViaApi($orderRef);
        if (!$this->isOrderCaptured($apiResponse)) {
            return [null, $gatewayResponse];
        }

        return [
            $this->extractTransactionId($apiResponse),
            array_merge($gatewayResponse, [
                'verified_via' => 'kashier_api_missing_transaction_id',
                'kashier_api_response' => $apiResponse,
            ]),
        ];
    }

    public function transactionIdConflicts(Order $order, ?string $transactionId): bool
    {
        return $transactionId !== null
            && is_string($order->transaction_id)
            && $order->transaction_id !== ''
            && !hash_equals($order->transaction_id, $transactionId);
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function flagApprovedTransactionConflict(
        Order $order,
        ?string $transactionId,
        array $gatewayResponse
    ): bool {
        if ($order->status !== Order::STATUS_APPROVED || !$this->transactionIdConflicts($order, $transactionId)) {
            return false;
        }

        return DB::transaction(function () use ($order, $transactionId, $gatewayResponse): bool {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (
                $locked->status !== Order::STATUS_APPROVED
                || !$this->transactionIdConflicts($locked, $transactionId)
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

    public function financialReversalType(string $paymentStatus): ?string
    {
        return $this->evidence->reversalType($paymentStatus);
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
                : $this->extractFinancialReversalEvents($params);
            if ($events !== []) {
                foreach ($events as $event) {
                    if (!is_array($event)) continue;
                    $eventStatus = strtoupper(trim((string) ($event['payment_status'] ?? '')));
                    $eventType = $this->financialReversalType($eventStatus);
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
                        $this->normalizeTransactionId(
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
                $this->sanitizeGatewayResponse($params)
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
                $this->sanitizeGatewayResponse($params)
            );
        }, 3);
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function fulfillOrder(Order $order, ?string $transactionId, array $gatewayResponse): Order
    {
        /** @var array{order: Order, user: User, late_capture: bool, notify: bool} $result */
        $result = DB::transaction(function () use ($order, $transactionId, $gatewayResponse): array {
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
                    $this->transactionIdConflicts($order, $transactionId)
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
                    $this->assertGatewayPaymentMatchesOrder($order, $gatewayResponse);
                    $settlementFacts = $this->gatewaySettlementFacts($order, $gatewayResponse);
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

                return [
                    'order' => $order->fresh(['user', 'package']),
                    'user' => $lockedUser,
                    'late_capture' => false,
                    'notify' => false,
                ];
            }

            if (
                $order->payment_method !== Order::PAYMENT_METHOD_KASHIER
                || !$order->package_id
                || !$order->package
                || $this->coinAmount($order) <= 0
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
                return [
                    'order' => $order->fresh(['user', 'package']),
                    'user' => $lockedUser,
                    'late_capture' => false,
                    'notify' => false,
                ];
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

                return [
                    'order' => $order->fresh(['user', 'package']),
                    'user' => $lockedUser,
                    'late_capture' => false,
                    'notify' => false,
                ];
            }

            $this->assertGatewayPaymentMatchesOrder($order, $gatewayResponse);

            if (
                $order->financial_status === Order::FINANCIAL_REVIEW_REQUIRED
                && $this->hasReversalReviewEvidence($order)
            ) {
                $order->update(array_merge([
                    'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
                ], $this->gatewaySettlementFacts($order, $gatewayResponse)));

                Log::critical('Kashier capture withheld because reversal evidence arrived first', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                ]);

                return [
                    'order' => $order->fresh(['user', 'package']),
                    'user' => $lockedUser,
                    'late_capture' => false,
                    'notify' => false,
                ];
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

                return [
                    'order' => $order->fresh(['user', 'package']),
                    'user' => $lockedUser,
                    'late_capture' => false,
                    'notify' => false,
                ];
            }

            $lateCapture = $order->status !== Order::STATUS_PENDING
                || $order->isCheckoutExpired();

            if ($lockedUser->trashed()) {
                $order->update(array_merge([
                    'status' => Order::STATUS_APPROVED,
                    'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                    'transaction_id' => $transactionId,
                    'approved_at' => now(),
                    'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
                ], $this->gatewaySettlementFacts($order, $gatewayResponse)));
                $this->recordCaptureAfterAccountDeletion($order, $transactionId, $lockedUser);

                Log::critical('Kashier captured payment after the learner account was deleted', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                ]);

                return [
                    'order' => $order->fresh(['user', 'package']),
                    'user' => $lockedUser,
                    'late_capture' => $lateCapture,
                    'notify' => false,
                ];
            }

            $order->update(array_merge([
                'transaction_id' => $transactionId,
                'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
            ], $this->gatewaySettlementFacts($order, $gatewayResponse)));

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

            return [
                'order' => $order->fresh(['user', 'package']),
                'user' => $user,
                'late_capture' => $lateCapture,
                'notify' => true,
            ];
        }, 3);

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
                StudentNotificationService::notifyUser(
                    $user,
                    StudentNotificationService::TYPE_PACKAGE_PURCHASED,
                    'تم شحن رصيدك',
                    'Package Purchased',
                    'أضفنا ' . $this->coinAmount($order)
                        . " عملة إلى محفظتك\nالرصيد جاهز للاستخدام",
                    'Package purchased successfully. ' . $this->coinAmount($order) . ' coins added to your wallet.',
                    null,
                    Package::class,
                    $order->package_id,
                    'package-purchased:order:' . $order->id,
                    ['coins' => $this->coinAmount($order)]
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
                        'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
                    ]);
                }
                $locked = $this->orderLifecycle->cancelPending($locked);
            }

            return $locked->fresh(['user', 'package']);
        }, 3);
    }

    public function coinAmount(Order $order): int
    {
        return max(0, (int) $order->package_coins);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeGatewayResponse(array $payload): array
    {
        return $this->evidence->sanitize($payload);
    }

    /**
     * Preserve provider settlement facts separately from the sanitized raw
     * payload so reports never present captured gross revenue as net payout.
     * Existing non-settlement values are intentionally write-once.
     *
     * @param array<string, mixed> $gatewayResponse
     * @return array<string, mixed>
     */
    private function gatewaySettlementFacts(Order $order, array $gatewayResponse): array
    {
        return $this->evidence->settlementFacts($order, $gatewayResponse);
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function assertGatewayPaymentMatchesOrder(Order $order, array $gatewayResponse): void
    {
        $this->evidence->assertMatches($order, $gatewayResponse);
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
        $safeEvidence = $this->sanitizeGatewayResponse($gatewayResponse);
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
