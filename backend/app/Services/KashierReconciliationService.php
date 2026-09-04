<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentReconciliationCheckpoint;
use App\Models\PaymentReconciliationFinding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class KashierReconciliationService
{
    private const PROVIDER = 'kashier';

    public function __construct(
        private KashierPaymentService $payments,
        private OrderLifecycleService $orders
    ) {
    }

    /**
     * @return array{checked:int,consistent:int,fulfilled:int,reversed:int,findings:int,unavailable:int,wrapped:bool,cursor:int}
     */
    public function reconcile(int $limit, bool $restart = false): array
    {
        $limit = max(1, min(1000, $limit));
        $checkpoint = PaymentReconciliationCheckpoint::query()->firstOrCreate(
            ['provider' => self::PROVIDER],
            ['cursor_order_id' => 0]
        );
        if ($restart) {
            $checkpoint->update(['cursor_order_id' => 0]);
            $checkpoint->refresh();
        }

        $checkpoint->update([
            'last_started_at' => now(),
            'last_error_at' => null,
            'last_error_code' => null,
        ]);

        $wrapped = false;
        $orders = $this->batchAfter((int) $checkpoint->cursor_order_id, $limit);
        if ($orders->isEmpty() && (int) $checkpoint->cursor_order_id > 0) {
            $wrapped = true;
            $checkpoint->increment('cycles');
            $orders = $this->batchAfter(0, $limit);
        }

        $stats = [
            'checked' => 0,
            'consistent' => 0,
            'fulfilled' => 0,
            'reversed' => 0,
            'findings' => 0,
            'unavailable' => 0,
            'wrapped' => $wrapped,
            'cursor' => (int) $checkpoint->cursor_order_id,
        ];

        foreach ($orders as $order) {
            $stats['checked']++;
            try {
                $outcome = $this->reconcileOrder($order);
                $stats[$outcome]++;
                if (
                    $outcome === 'unavailable'
                    || (
                        $outcome === 'reversed'
                        && $order->fresh()->financial_status === Order::FINANCIAL_REVIEW_REQUIRED
                    )
                ) {
                    $stats['findings']++;
                }
            } catch (Throwable $exception) {
                $stats['findings']++;
                $this->recordFinding(
                    $order,
                    'reconciliation_exception',
                    null,
                    null,
                    ['exception' => $exception::class]
                );
                Log::error('Kashier reconciliation could not inspect an order', [
                    'order_id' => $order->id,
                    'order_ref' => $order->order_ref,
                    'exception' => $exception::class,
                ]);
            }
            $stats['cursor'] = (int) $order->id;
        }

        $errorCode = $stats['checked'] > 0 && $stats['unavailable'] === $stats['checked']
            ? 'provider_unavailable'
            : null;
        $checkpoint->update([
            'cursor_order_id' => $stats['cursor'],
            'last_batch_size' => $stats['checked'],
            'last_completed_at' => now(),
            'last_error_at' => $errorCode ? now() : null,
            'last_error_code' => $errorCode,
            'metadata' => $stats,
        ]);

        return $stats;
    }

    /** @return Collection<int, Order> */
    private function batchAfter(int $cursor, int $limit): Collection
    {
        return Order::query()
            ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
            ->whereNotNull('order_ref')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return 'consistent'|'fulfilled'|'reversed'|'findings'|'unavailable' */
    private function reconcileOrder(Order $order): string
    {
        $response = $this->payments->verifyOrderViaApi((string) $order->order_ref);
        if ($response === null) {
            $this->recordFinding($order, 'provider_unavailable', null, null, []);

            return 'unavailable';
        }

        $providerStatus = $this->payments->providerOrderStatus($response);
        $transactionId = $this->payments->extractTransactionId($response);
        $evidence = $this->safeEvidence($response, $providerStatus, $transactionId);
        if ($providerStatus === null) {
            $this->recordFinding(
                $order,
                'provider_status_missing',
                null,
                $transactionId,
                $evidence
            );

            return 'findings';
        }

        if ($this->payments->isOrderCaptured($response)) {
            try {
                $this->payments->assertGatewayPaymentMatchesOrder($order, $response);
            } catch (Throwable $exception) {
                $this->markForReview($order, 'captured_evidence_mismatch');
                $this->recordFinding(
                    $order,
                    'captured_evidence_mismatch',
                    $providerStatus,
                    $transactionId,
                    $evidence + ['exception' => $exception::class]
                );

                return 'findings';
            }

            if ($this->payments->transactionIdConflicts($order, $transactionId)) {
                $this->payments->flagApprovedTransactionConflict($order, $transactionId, $response);
                $this->recordFinding(
                    $order,
                    'captured_transaction_conflict',
                    $providerStatus,
                    $transactionId,
                    $evidence
                );

                return 'findings';
            }

            $reconciled = $this->payments->fulfillOrder(
                $order,
                $transactionId,
                [
                    'verified_via' => 'scheduled_kashier_reconciliation',
                    'kashier_api_response' => $response,
                ]
            );
            if (
                $reconciled->status !== Order::STATUS_APPROVED
                || $reconciled->financial_status !== Order::FINANCIAL_SETTLED
            ) {
                $this->recordFinding(
                    $reconciled,
                    'captured_local_fulfillment_blocked',
                    $providerStatus,
                    $transactionId,
                    $evidence
                );

                return 'findings';
            }

            $this->resolveOpenFindings($reconciled);

            return $order->status === Order::STATUS_APPROVED ? 'consistent' : 'fulfilled';
        }

        if ($providerStatus === 'NOT_FOUND' && $order->status === Order::STATUS_PENDING) {
            if ($order->financial_status === Order::FINANCIAL_REVIEW_REQUIRED) {
                $this->recordFinding(
                    $order,
                    'provider_missing_local_review_required',
                    $providerStatus,
                    $transactionId,
                    $evidence
                );

                return 'findings';
            }

            // The provider-side order can be absent until the learner opens
            // the HPP. Only local expiry proves that this particular checkout
            // may be closed; otherwise a periodic scan would cancel a fresh
            // link and allow two payable attempts for the same tap.
            if ($order->isCheckoutExpired()) {
                $this->payments->cancelPendingOrder($order, $evidence);
            }
            $this->resolveOpenFindings($order->fresh());

            return 'consistent';
        }

        if ($providerStatus === 'NOT_FOUND') {
            if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REJECTED], true)) {
                $this->resolveOpenFindings($order);

                return 'consistent';
            }

            // Provider retention is not guaranteed to match the application's
            // financial history. Absence is a finding, not evidence that can
            // undo or quarantine an otherwise settled local order.
            $this->recordFinding(
                $order,
                'provider_missing_local_order',
                $providerStatus,
                $transactionId,
                $evidence
            );

            return 'findings';
        }

        $reversalType = $this->payments->financialReversalType($providerStatus);
        if ($reversalType !== null) {
            $this->payments->recordFinancialReversal(
                $order,
                $reversalType,
                $providerStatus,
                $transactionId,
                $evidence
            );
            $reconciled = $order->fresh();
            if ($reconciled->financial_status === Order::FINANCIAL_REVIEW_REQUIRED) {
                $this->recordFinding(
                    $reconciled,
                    'provider_reversal_requires_review',
                    $providerStatus,
                    $transactionId,
                    $evidence
                );
                Log::warning('Kashier reversal requires financial review', [
                    'order_id' => $reconciled->id,
                    'order_ref' => $reconciled->order_ref,
                    'provider_status' => $providerStatus,
                ]);
            } else {
                $this->resolveOpenFindings($reconciled);
            }

            return 'reversed';
        }

        if ($providerStatus !== 'NOT_FOUND' && $this->payments->isProviderFailureStatus($providerStatus)) {
            if ($order->status === Order::STATUS_PENDING) {
                $reconciled = $this->payments->cancelPendingOrder($order, $evidence);
                if ($reconciled->financial_status === Order::FINANCIAL_REVIEW_REQUIRED) {
                    $this->recordFinding(
                        $reconciled,
                        'provider_failed_local_review_required',
                        $providerStatus,
                        $transactionId,
                        $evidence
                    );

                    return 'findings';
                }
                $this->resolveOpenFindings($reconciled);

                return 'consistent';
            }
            if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REJECTED], true)) {
                $this->resolveOpenFindings($order);

                return 'consistent';
            }

            $this->markForReview($order, 'provider_failed_local_settled');
            $this->recordFinding(
                $order,
                'provider_failed_local_settled',
                $providerStatus,
                $transactionId,
                $evidence
            );

            return 'findings';
        }

        if ($this->payments->isProviderPendingStatus($providerStatus)
            && $order->status === Order::STATUS_PENDING) {
            if ($order->financial_status === Order::FINANCIAL_REVIEW_REQUIRED) {
                $this->recordFinding(
                    $order,
                    'provider_pending_local_review_required',
                    $providerStatus,
                    $transactionId,
                    $evidence
                );

                return 'findings';
            }
            if ($order->isCheckoutExpired()) {
                $this->recordFinding(
                    $order,
                    'provider_pending_after_local_expiry',
                    $providerStatus,
                    $transactionId,
                    $evidence
                );

                return 'findings';
            }
            $this->resolveOpenFindings($order);

            return 'consistent';
        }

        $this->markForReview($order, 'provider_local_status_mismatch');
        $this->recordFinding(
            $order,
            'provider_local_status_mismatch',
            $providerStatus,
            $transactionId,
            $evidence
        );

        return 'findings';
    }

    /** @return array<string, mixed> */
    private function safeEvidence(array $response, ?string $status, ?string $transactionId): array
    {
        return [
            'status' => $status,
            'transaction_id' => $transactionId,
            'merchant_order_id' => $this->firstScalar($response, [
                'response.merchantOrderId',
                'response.order.merchantOrderId',
                'response.transactions.0.merchantOrderId',
                'data.merchantOrderId',
                'merchantOrderId',
            ]),
            'amount' => $this->firstScalar($response, [
                'response.amount',
                'response.amount.value',
                'response.order.amount',
                'response.transactions.0.amount',
                'data.amount',
                'amount',
            ]),
            'currency' => $this->firstScalar($response, [
                'response.currency',
                'response.amount.currency',
                'response.order.currency',
                'response.transactions.0.currency',
                'data.currency',
                'currency',
            ]),
            'reversal_events' => $this->payments->extractFinancialReversalEvents($response),
        ];
    }

    private function firstScalar(array $payload, array $paths): int|float|string|null
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_int($value) || is_float($value) || is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $evidence */
    private function recordFinding(
        Order $order,
        string $kind,
        ?string $providerStatus,
        ?string $transactionId,
        array $evidence
    ): PaymentReconciliationFinding {
        $fingerprint = hash('sha256', implode('|', [
            self::PROVIDER,
            (string) $order->id,
            $kind,
            (string) $providerStatus,
            (string) $transactionId,
        ]));
        return DB::transaction(function () use (
            $fingerprint,
            $order,
            $kind,
            $providerStatus,
            $transactionId,
            $evidence
        ): PaymentReconciliationFinding {
            // Reconciliation and a dashboard decision can touch the same row
            // at once. Read the review state under the row lock so a provider
            // observation that lands after “resolved” reliably reopens it,
            // rather than saving fresh evidence into a falsely closed item.
            $finding = PaymentReconciliationFinding::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first() ?: new PaymentReconciliationFinding([
                    'fingerprint' => $fingerprint,
                ]);
            $isNew = ! $finding->exists;
            $finding->fill([
                'provider' => self::PROVIDER,
                'order_id' => $order->id,
                'order_ref' => (string) $order->order_ref,
                'kind' => $kind,
                'local_status' => (string) $order->status,
                'local_financial_status' => (string) $order->financial_status,
                'provider_status' => $providerStatus,
                'provider_transaction_id' => $transactionId,
                'attempts' => $isNew ? 1 : ((int) $finding->attempts + 1),
                'first_seen_at' => $isNew ? now() : $finding->first_seen_at,
                'last_seen_at' => now(),
                'evidence' => $evidence,
            ]);
            if ($isNew || $finding->state === PaymentReconciliationFinding::STATE_RESOLVED) {
                $finding->fill([
                    'state' => PaymentReconciliationFinding::STATE_OPEN,
                    'resolved_at' => null,
                    'resolved_by' => null,
                    'resolution_note' => null,
                ]);
            }
            $finding->save();

            return $finding;
        }, 3);
    }

    private function resolveOpenFindings(Order $order): void
    {
        PaymentReconciliationFinding::query()
            ->where('order_id', $order->id)
            ->where('state', PaymentReconciliationFinding::STATE_OPEN)
            ->update([
                'state' => PaymentReconciliationFinding::STATE_RESOLVED,
                'resolved_at' => now(),
                'resolution_note' => 'Cleared by a later provider reconciliation.',
                'updated_at' => now(),
            ]);
    }

    private function markForReview(Order $order, string $reason): void
    {
        $this->orders->flagExternalFinancialReview(
            $order,
            $reason,
            'Kashier reconciliation requires financial review.',
            'kashier:reconcile-review:' . hash('sha256', implode('|', [
                (string) $order->id,
                $reason,
            ])),
            'kashier'
        );
    }
}
