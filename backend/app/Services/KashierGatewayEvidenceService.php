<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;

final readonly class KashierGatewayEvidenceService
{
    public function normalizeTransactionId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}\z/D', $value) === 1
            ? $value
            : null;
    }

    public function isCaptureStatus(?string $status): bool
    {
        return in_array(strtoupper(trim((string) $status)), ['SUCCESS', 'CAPTURED', 'PAID'], true);
    }

    /** @param array<string,mixed>|null $response */
    public function status(?array $response): ?string
    {
        if (!$response) {
            return null;
        }

        $reversals = $this->reversalEvents($response);
        if ($reversals !== []) {
            $priority = ['CHARGEBACK' => 4, 'REVERSED' => 3, 'REFUNDED' => 2, 'PARTIAL_REFUND' => 1];
            usort($reversals, static fn (array $left, array $right): int =>
                ($priority[$right['payment_status']] ?? 0) <=> ($priority[$left['payment_status']] ?? 0)
            );

            return $reversals[0]['payment_status'];
        }

        $status = $response['response']['paymentStatus']
            ?? $response['response']['payment_status']
            ?? $response['response']['order']['paymentStatus']
            ?? $response['response']['order']['payment_status']
            ?? $response['response']['data'][0]['paymentStatus']
            ?? $response['response']['data'][0]['payment_status']
            ?? $response['data']['paymentStatus']
            ?? $response['data']['payment_status']
            ?? $response['paymentStatus']
            ?? $response['payment_status']
            ?? $response['response']['status']
            ?? $response['data']['status']
            ?? $response['status']
            ?? null;
        $status = strtoupper(trim((string) $status));

        if ($status === '') {
            $transactions = $response['response']['transactions']
                ?? $response['data']['transactions']
                ?? $response['transactions']
                ?? [];
            if (is_array($transactions)) {
                foreach (array_reverse($transactions) as $transaction) {
                    if (!is_array($transaction)) {
                        continue;
                    }
                    $transactionStatus = strtoupper(trim((string) (
                        $transaction['status'] ?? $transaction['paymentStatus'] ?? ''
                    )));
                    $operation = strtoupper(trim((string) (
                        $transaction['operation'] ?? $transaction['type'] ?? ''
                    )));
                    if ($this->isCaptureStatus($transactionStatus)) {
                        if (str_contains($operation, 'REFUND')) {
                            return 'REFUNDED';
                        }
                        if (str_contains($operation, 'CHARGEBACK') || str_contains($operation, 'DISPUTE')) {
                            return 'CHARGEBACK';
                        }
                        if (str_contains($operation, 'REVERS') || str_contains($operation, 'VOID')) {
                            return 'REVERSED';
                        }
                        if (in_array($operation, ['PAY', 'CAPTURE', 'SALE', 'PURCHASE'], true)) {
                            return 'CAPTURED';
                        }
                    }
                    if (preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $transactionStatus) === 1) {
                        return $transactionStatus;
                    }
                }
            }
        }

        return $status !== '' && preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $status) === 1
            ? $status
            : null;
    }

    /**
     * @param array<string,mixed>|null $response
     * @return array<int,array{payment_status:string,provider_event_id:?string,original_transaction_id:?string,amount:int|float|string|null,currency:?string,occurred_at:?string,evidence_fingerprint:string}>
     */
    public function reversalEvents(?array $response): array
    {
        if (!$response) {
            return [];
        }
        $transactions = $response['response']['transactions']
            ?? $response['data']['transactions']
            ?? $response['transactions']
            ?? null;
        if (!is_array($transactions)) {
            return [];
        }

        $events = [];
        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $transactionStatus = strtoupper(trim((string) (
                $transaction['status'] ?? $transaction['paymentStatus'] ?? ''
            )));
            $operation = strtoupper(trim((string) (
                $transaction['operation'] ?? $transaction['type'] ?? ''
            )));
            if (!in_array($transactionStatus, [
                'SUCCESS', 'CAPTURED', 'PAID', 'REFUND', 'REFUNDED',
                'FULLY_REFUNDED', 'PARTIAL_REFUND', 'PARTIALLY_REFUNDED',
                'CHARGEBACK', 'DISPUTED', 'REVERSED', 'REVERSAL',
            ], true)) {
                continue;
            }

            $paymentStatus = null;
            if (str_contains($operation, 'CHARGEBACK') || str_contains($operation, 'DISPUTE')) {
                $paymentStatus = 'CHARGEBACK';
            } elseif (str_contains($operation, 'REVERS') || str_contains($operation, 'VOID')) {
                $paymentStatus = 'REVERSED';
            } elseif (str_contains($operation, 'REFUND')) {
                $paymentStatus = str_contains($operation, 'FULL') ? 'REFUNDED' : 'PARTIAL_REFUND';
            } elseif (in_array($transactionStatus, ['CHARGEBACK', 'DISPUTED'], true)) {
                $paymentStatus = 'CHARGEBACK';
            } elseif (in_array($transactionStatus, ['REVERSED', 'REVERSAL'], true)) {
                $paymentStatus = 'REVERSED';
            } elseif ($transactionStatus === 'FULLY_REFUNDED') {
                $paymentStatus = 'REFUNDED';
            } elseif (in_array($transactionStatus, [
                'REFUND', 'REFUNDED', 'PARTIAL_REFUND', 'PARTIALLY_REFUNDED',
            ], true)) {
                $paymentStatus = in_array($transactionStatus, ['PARTIAL_REFUND', 'PARTIALLY_REFUNDED'], true)
                    ? 'PARTIAL_REFUND'
                    : 'REFUNDED';
            }
            if ($paymentStatus === null) {
                continue;
            }

            $providerEventId = $this->normalizeTransactionId(
                $transaction['eventId'] ?? $transaction['event_id']
                ?? $transaction['refundId'] ?? $transaction['refund_id']
                ?? $transaction['transactionId'] ?? $transaction['transaction_id'] ?? null
            );
            $originalTransactionId = $this->normalizeTransactionId(
                $transaction['originalTransactionId'] ?? $transaction['original_transaction_id']
                ?? $transaction['parentTransactionId'] ?? $transaction['parent_transaction_id'] ?? null
            );
            $amount = $transaction['amount'] ?? $transaction['refundAmount'] ?? null;
            $amount = is_int($amount) || is_float($amount) || is_string($amount) ? $amount : null;
            $currency = strtoupper(trim((string) ($transaction['currency'] ?? $transaction['currencyCode'] ?? '')));
            $currency = preg_match('/\A[A-Z]{3}\z/D', $currency) === 1 ? $currency : null;
            $occurredAt = trim((string) (
                $transaction['createdAt'] ?? $transaction['created_at'] ?? $transaction['date'] ?? ''
            ));
            $occurredAt = $occurredAt !== '' ? $occurredAt : null;
            $fingerprint = hash('sha256', json_encode([
                $paymentStatus, $providerEventId, $originalTransactionId, $amount, $currency, $occurredAt,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $events[$providerEventId ?? $fingerprint] = [
                'payment_status' => $paymentStatus,
                'provider_event_id' => $providerEventId,
                'original_transaction_id' => $originalTransactionId,
                'amount' => $amount,
                'currency' => $currency,
                'occurred_at' => $occurredAt,
                'evidence_fingerprint' => $fingerprint,
            ];
        }

        ksort($events);

        return array_values($events);
    }

    public function isPendingStatus(?string $status): bool
    {
        return in_array(strtoupper(trim((string) $status)), [
            'PENDING', 'INITIATED', 'AUTHORIZED', 'PROCESSING',
        ], true);
    }

    public function mayCaptureWithoutLearner(?string $status): bool
    {
        $status = strtoupper(trim((string) $status));
        if ($status === '') {
            return true;
        }

        return !in_array($status, [
            'NOT_FOUND', 'UNPAID', 'PENDING', 'INITIATED', 'FAILED', 'FAILURE', 'DECLINED',
            'CANCELLED', 'CANCELED', 'VOIDED', 'EXPIRED',
        ], true);
    }

    public function isFailureStatus(?string $status): bool
    {
        return in_array(strtoupper(trim((string) $status)), [
            'NOT_FOUND', 'UNPAID', 'FAILED', 'FAILURE', 'DECLINED', 'CANCELLED', 'CANCELED',
            'VOIDED', 'EXPIRED',
        ], true);
    }

    /** @param array<string,mixed>|null $response */
    public function transactionId(?array $response): ?string
    {
        if (!$response) {
            return null;
        }

        $direct = $this->normalizeTransactionId(
            $response['response']['transactionId']
            ?? $response['transactionId']
            ?? ($response['data']['transactionId'] ?? null)
        );
        if ($direct !== null) {
            return $direct;
        }

        $transactions = $response['response']['transactions']
            ?? $response['data']['transactions']
            ?? $response['transactions']
            ?? [];
        if (!is_array($transactions)) {
            return null;
        }

        $successful = array_values(array_filter(
            $transactions,
            static fn (mixed $transaction): bool => is_array($transaction)
                && in_array(strtoupper(trim((string) ($transaction['status'] ?? ''))), [
                    'SUCCESS', 'CAPTURED',
                ], true)
        ));
        foreach (array_reverse($successful) as $transaction) {
            $operation = strtolower(trim((string) ($transaction['operation'] ?? '')));
            if (!in_array($operation, ['pay', 'capture', 'sale', 'purchase'], true)) {
                continue;
            }
            $id = $this->normalizeTransactionId(
                $transaction['transactionId'] ?? $transaction['transaction_id'] ?? null
            );
            if ($id !== null) {
                return $id;
            }
        }
        foreach (array_reverse($successful) as $transaction) {
            $id = $this->normalizeTransactionId(
                $transaction['transactionId'] ?? $transaction['transaction_id'] ?? null
            );
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    public function reversalType(string $paymentStatus): ?string
    {
        return match (strtoupper(trim($paymentStatus))) {
            'REFUND', 'REFUNDED', 'FULLY_REFUNDED',
            'PARTIAL_REFUND', 'PARTIALLY_REFUNDED' => Order::FINANCIAL_REFUNDED,
            'CHARGEBACK', 'DISPUTED' => Order::FINANCIAL_CHARGEBACK,
            'REVERSED', 'REVERSAL' => Order::FINANCIAL_REVERSED,
            default => null,
        };
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function sanitize(array $payload): array
    {
        $secretKeys = [
            'signature', 'hash', 'signaturekeys', 'token', 'cardtoken', 'ccvtoken',
            'carddatatoken', 'accesstoken', 'refreshtoken', 'authorization', 'api_key',
            'apikey', 'apipassword', 'password', 'secret', 'secretkey', 'securitycode',
        ];
        $privateContainers = [
            'card', 'cardinfo', 'carddata', 'customer', 'customerdata', 'paymentsource',
            'sourceoffunds', 'requestcredentials', 'credentials', 'auth',
        ];

        foreach ($payload as $key => $value) {
            $keyName = strtolower((string) $key);
            if (in_array($keyName, $secretKeys, true)) {
                unset($payload[$key]);
                continue;
            }
            if (in_array($keyName, $privateContainers, true)) {
                $payload[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    public function settlementFacts(Order $order, array $response): array
    {
        $payload = isset($response['kashier_api_response']) && is_array($response['kashier_api_response'])
            ? $response['kashier_api_response']
            : $response;
        $fee = $this->normalizeAmount($this->firstValue($payload, [
            'fee', 'fees', 'feeAmount', 'fee_amount', 'processingFee', 'response.fee',
            'response.fees', 'response.feeAmount', 'response.settlement.fee', 'data.fee', 'data.feeAmount',
        ]));
        $net = $this->normalizeAmount($this->firstValue($payload, [
            'netAmount', 'net_amount', 'settlementAmount', 'settlement_amount', 'response.netAmount',
            'response.net_amount', 'response.settlement.netAmount', 'response.settlement.amount',
            'data.netAmount', 'data.settlementAmount',
        ]));
        $gross = (float) $order->final_amount;
        if ($net === null && $fee !== null) {
            $net = max(0, $gross - $fee);
        }
        $currency = strtoupper((string) ($this->firstValue($payload, [
            'currency', 'response.currency', 'response.settlement.currency', 'data.currency',
        ]) ?? 'EGP'));
        $providerStatus = strtolower(trim((string) ($this->firstValue($payload, [
            'settlementStatus', 'settlement_status', 'response.settlement.status',
            'data.settlementStatus', 'response.paymentStatus', 'response.payment_status',
            'paymentStatus', 'payment_status', 'response.status', 'status',
        ]) ?? 'captured')));

        $facts = [];
        foreach ([
            'gateway_gross_amount' => number_format($gross, 2, '.', ''),
            'gateway_currency' => substr($currency, 0, 3),
            'gateway_settlement_status' => $net !== null ? 'settled' : substr($providerStatus, 0, 32),
            'gateway_fee_amount' => $fee !== null ? number_format($fee, 2, '.', '') : null,
            'gateway_net_amount' => $net !== null ? number_format($net, 2, '.', '') : null,
            'gateway_settled_at' => $net !== null ? now() : null,
        ] as $field => $value) {
            if ($order->{$field} === null && $value !== null) {
                $facts[$field] = $value;
            }
        }

        return $facts;
    }

    /** @param array<string,mixed> $response */
    public function assertMatches(Order $order, array $response): void
    {
        $payload = isset($response['kashier_api_response']) && is_array($response['kashier_api_response'])
            ? $response['kashier_api_response']
            : $response;
        $orderRef = $this->firstValue($payload, [
            'merchantOrderId', 'merchant_order_id', 'order_ref', 'response.merchantOrderId',
            'response.merchant_order_id', 'response.order_ref', 'response.order.merchantOrderId',
            'response.order.merchant_order_id', 'response.order.order_ref',
            'response.transactions.0.merchantOrderId', 'data.merchantOrderId',
            'data.merchant_order_id', 'data.order_ref',
        ]);
        if ($orderRef !== null && !hash_equals((string) $order->order_ref, trim((string) $orderRef))) {
            throw new \RuntimeException('Kashier merchant order reference mismatch.');
        }

        $amount = $this->firstValue($payload, [
            'amount', 'amount.value', 'response.amount', 'response.amount.value',
            'response.paymentAmount', 'response.totalAmount', 'response.order.amount',
            'response.order.amount.value', 'response.transactions.0.amount', 'data.amount', 'data.amount.value',
        ]);
        $normalizedAmount = $this->normalizeAmount($amount);
        if ($amount === null || $normalizedAmount === null
            || abs($normalizedAmount - (float) $order->final_amount) > 0.009) {
            throw new \RuntimeException('Kashier payment amount mismatch.');
        }

        $currency = $this->firstValue($payload, [
            'currency', 'amount.currency', 'response.currency', 'response.amount.currency',
            'response.order.currency', 'response.transactions.0.currency', 'data.currency', 'data.amount.currency',
        ]);
        if ($currency === null || strtoupper(trim((string) $currency)) !== 'EGP') {
            throw new \RuntimeException('Kashier payment currency mismatch.');
        }
    }

    private function firstValue(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if ($value !== null && $value !== '' && !is_array($value) && !is_object($value)) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = str_replace([',', ' '], '', trim($value));

        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }
}
