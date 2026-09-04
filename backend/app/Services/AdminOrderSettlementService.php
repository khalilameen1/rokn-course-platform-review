<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;

final class AdminOrderSettlementService
{
    /** @param array<string, mixed> $input */
    public function record(Order $order, array $input, int $actorId): void
    {
        $this->assertRecordable($order);

        $gross = round((float) $input['gross_amount'], 2);
        $fee = round((float) $input['fee_amount'], 2);
        $net = round((float) $input['net_amount'], 2);
        $currency = strtoupper((string) $input['currency']);
        $settledAt = BusinessClock::localInputToUtc((string) $input['settled_at']);
        if ($settledAt === null || $settledAt->isAfter(BusinessClock::utcNow()->addMinute())) {
            throw new \DomainException('Settlement time cannot be in the future.');
        }
        if (abs(($gross - $fee) - $net) > 0.02) {
            throw new \DomainException('Settlement net does not equal gross minus fees.');
        }
        if (
            $order->gateway_gross_amount !== null
            && abs((float) $order->gateway_gross_amount - $gross) > 0.02
        ) {
            throw new \DomainException('Settlement gross does not match the captured gross.');
        }
        if (filled($order->gateway_currency) && strtoupper((string) $order->gateway_currency) !== $currency) {
            throw new \DomainException('Settlement currency does not match the captured currency.');
        }

        DB::transaction(function () use ($order, $input, $actorId, $gross, $fee, $net, $currency, $settledAt): void {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertRecordable($locked);

            $response = is_array($locked->payment_gateway_response)
                ? $locked->payment_gateway_response
                : [];
            $response['settlement'] = [
                'source' => 'dashboard_statement',
                'provider_reference' => trim((string) $input['provider_reference']),
                'recorded_by' => $actorId,
                'recorded_at' => now()->toIso8601String(),
            ];
            $locked->forceFill([
                'gateway_gross_amount' => $locked->gateway_gross_amount ?? number_format($gross, 2, '.', ''),
                'gateway_fee_amount' => number_format($fee, 2, '.', ''),
                'gateway_net_amount' => number_format($net, 2, '.', ''),
                'gateway_currency' => $currency,
                'gateway_settlement_status' => 'settled',
                'gateway_settled_at' => $settledAt,
                'payment_gateway_response' => $response,
            ])->save();
        }, 3);
    }

    private function assertRecordable(Order $order): void
    {
        if (
            !in_array($order->payment_method, [
                Order::PAYMENT_METHOD_KASHIER,
                Order::PAYMENT_METHOD_GOOGLE_PLAY,
                Order::PAYMENT_METHOD_APP_STORE,
            ], true)
            || $order->package_id === null
            || $order->status !== Order::STATUS_APPROVED
        ) {
            throw new \DomainException('Only an approved paid package can be settled.');
        }
        if ($order->gateway_settlement_status === 'test_purchase') {
            throw new \DomainException('Test purchases cannot be settled as live revenue.');
        }
        if ($order->gateway_net_amount !== null) {
            throw new \DomainException('Settlement was already recorded.');
        }
    }
}
