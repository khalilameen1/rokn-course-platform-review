<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\WalletDebitAllocation;
use Illuminate\Support\Collection;

/** Attributes accepted course debits to evidenced source cash, never catalogue prices. */
final class CourseCashAttributionService
{
    /** @param Collection<int, Order> $orders */
    public function allocationsFor(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        return WalletDebitAllocation::query()
            ->whereIn('course_order_id', $orders->modelKeys())
            ->with('creditLot.sourceOrder')
            ->get()
            ->groupBy('course_order_id');
    }

    /**
     * @param Collection<int, Order> $orders
     * @param Collection<int, Collection<int, WalletDebitAllocation>> $allocationsByOrder
     * @param Collection<int, array{total_coins:int,paid_coins:int,reward_coins:int,complete:bool}> $coinAllocationsByOrder
     * @return array<string, mixed>
     */
    public function forOrders(
        Collection $orders,
        Collection $allocationsByOrder,
        Collection $coinAllocationsByOrder
    ): array {
        $gross = 0.0;
        $estimatedGross = 0.0;
        $netKnown = 0.0;
        $pendingGross = 0.0;
        $allocatedCoins = 0;
        $reconciliationMissing = false;
        $foreignCurrencyAmounts = [];
        $channels = [];

        foreach ($orders as $order) {
            $coinAllocation = $coinAllocationsByOrder->get((int) $order->id, [
                'paid_coins' => 0,
                'complete' => false,
            ]);
            if (!(bool) $coinAllocation['complete']) {
                $reconciliationMissing = true;
            }
            $orderAllocatedCoins = 0;
            foreach ($allocationsByOrder->get($order->id, collect()) as $allocation) {
                $lot = $allocation->creditLot;
                $source = $lot?->sourceOrder;
                $coins = max(0, (int) $allocation->amount);
                $lotCoins = max(0, (int) $lot?->original_amount);
                if ($coins === 0 || $lotCoins === 0) {
                    continue;
                }

                if (
                    !$source
                    && (string) data_get($lot?->metadata, 'provenance_type')
                        === 'course_service_compensation'
                ) {
                    $orderAllocatedCoins += $coins;
                    $allocatedCoins += $coins;
                    $channels['service_compensation'] ??= $this->emptyChannel(
                        'service_compensation',
                        'تعويض خدمة بلا تحصيل جديد'
                    );
                    $channels['service_compensation']['paid_coins'] += $coins;
                    continue;
                }
                if (!$source) {
                    continue;
                }

                $orderAllocatedCoins += $coins;
                $allocatedCoins += $coins;
                if ($source->financial_status !== Order::FINANCIAL_SETTLED) {
                    $channels['unreconciled'] ??= $this->emptyChannel(
                        'unreconciled', 'مصدر شحن غير مُسوّى', false
                    );
                    $channels['unreconciled']['paid_coins'] += $coins;
                    $reconciliationMissing = true;
                    continue;
                }

                if ($source->gateway_settlement_status === 'test_purchase') {
                    $testChannel = (string) $source->payment_method . '_test';
                    $channels[$testChannel] ??= $this->emptyChannel(
                        $testChannel,
                        match ($source->payment_method) {
                            Order::PAYMENT_METHOD_KASHIER => 'Kashier — اختبار بلا دخل',
                            Order::PAYMENT_METHOD_GOOGLE_PLAY => 'Google Play — اختبار بلا دخل',
                            Order::PAYMENT_METHOD_APP_STORE => 'App Store — اختبار بلا دخل',
                            default => 'عملية اختبار بلا دخل',
                        }
                    );
                    $channels[$testChannel]['paid_coins'] += $coins;
                    continue;
                }

                $ratio = min(1, $coins / $lotCoins);
                $sourceGross = (float) ($source->gateway_gross_amount ?? $source->final_amount ?? 0);
                $sourceGrossKnown = $source->gateway_gross_amount !== null
                    && $source->gateway_settlement_status !== 'catalog_estimate';
                $attributedGross = $sourceGross * $ratio;
                $method = (string) $source->payment_method;
                $sourceCurrency = strtoupper((string) (
                    $source->gateway_currency
                    ?: (in_array($method, [
                        Order::PAYMENT_METHOD_GOOGLE_PLAY,
                        Order::PAYMENT_METHOD_APP_STORE,
                    ], true) ? 'PENDING' : 'EGP')
                ));
                $channels[$method] ??= $this->emptyChannel(
                    $method,
                    match ($method) {
                        Order::PAYMENT_METHOD_KASHIER => 'Kashier',
                        Order::PAYMENT_METHOD_GOOGLE_PLAY => 'Google Play',
                        Order::PAYMENT_METHOD_APP_STORE => 'App Store',
                        default => $method,
                    }
                );
                $channels[$method]['paid_coins'] += $coins;
                if ($sourceCurrency === 'PENDING') {
                    // Store catalogue prices are not cash evidence. Until the
                    // provider supplies the settlement currency, do not turn a
                    // local package price into apparent EGP course revenue.
                    $channels[$method]['gross_complete'] = false;
                    $channels[$method]['net_complete'] = false;
                    $reconciliationMissing = true;
                    continue;
                }
                if ($sourceCurrency !== 'EGP') {
                    $foreignCurrencyAmounts[$sourceCurrency] =
                        ($foreignCurrencyAmounts[$sourceCurrency] ?? 0.0) + $attributedGross;
                    $channels[$method]['foreign_currency_amounts'][$sourceCurrency] =
                        ($channels[$method]['foreign_currency_amounts'][$sourceCurrency] ?? 0.0)
                        + $attributedGross;
                    $channels[$method]['net_complete'] = false;
                    $channels[$method]['gross_complete'] = false;
                    $reconciliationMissing = true;
                    continue;
                }
                if ($sourceGrossKnown) {
                    $gross += $attributedGross;
                    $channels[$method]['gross_egp'] += $attributedGross;
                } else {
                    $estimatedGross += $attributedGross;
                    $channels[$method]['estimated_gross_egp'] += $attributedGross;
                    $channels[$method]['gross_complete'] = false;
                }

                if ($source->gateway_net_amount !== null) {
                    $attributedNet = (float) $source->gateway_net_amount * $ratio;
                    $netKnown += $attributedNet;
                    $channels[$method]['net_known_egp'] += $attributedNet;
                } elseif ($source->gateway_fee_amount !== null) {
                    $attributedNet = max(0, $sourceGross - (float) $source->gateway_fee_amount) * $ratio;
                    $netKnown += $attributedNet;
                    $channels[$method]['net_known_egp'] += $attributedNet;
                } else {
                    $pendingGross += $attributedGross;
                    $channels[$method]['pending_settlement_egp'] += $attributedGross;
                    $channels[$method]['net_complete'] = false;
                }
            }

            $missingPaidCoins = max(
                0,
                (int) $coinAllocation['paid_coins'] - $orderAllocatedCoins
            );
            if ($missingPaidCoins > 0) {
                $channels['unreconciled'] ??= $this->emptyChannel(
                    'unreconciled', 'مصدر شحن غير مُسوّى', false
                );
                $channels['unreconciled']['paid_coins'] += $missingPaidCoins;
                $reconciliationMissing = true;
            }
        }

        return [
            'cash_gross_egp' => round($gross, 2),
            'cash_estimated_gross_egp' => round($estimatedGross, 2),
            'cash_gross_complete' => collect($channels)->every(
                fn (array $channel): bool => (bool) $channel['gross_complete']
            ),
            'cash_net_known_egp' => round($netKnown, 2),
            'cash_pending_settlement_egp' => round($pendingGross, 2),
            'cash_net_complete' => $pendingGross < 0.005 && !$reconciliationMissing,
            'allocated_paid_coins' => $allocatedCoins,
            'cash_foreign_currency_amounts' => collect($foreignCurrencyAmounts)
                ->map(fn (float $amount): float => round($amount, 2))
                ->all(),
            'cash_channels' => collect($channels)->map(function (array $channel): array {
                $channel['gross_egp'] = round((float) $channel['gross_egp'], 2);
                $channel['estimated_gross_egp'] = round(
                    (float) $channel['estimated_gross_egp'],
                    2
                );
                $channel['net_known_egp'] = round((float) $channel['net_known_egp'], 2);
                $channel['pending_settlement_egp'] = round(
                    (float) $channel['pending_settlement_egp'],
                    2
                );
                $channel['foreign_currency_amounts'] = collect(
                    $channel['foreign_currency_amounts']
                )->map(fn (float $amount): float => round($amount, 2))->all();

                return $channel;
            })->all(),
        ];
    }

    /** @return array<string, int|float|bool|string|array> */
    private function emptyChannel(string $method, string $label, bool $complete = true): array
    {
        return [
            'method' => $method,
            'label' => $label,
            'paid_coins' => 0,
            'gross_egp' => 0.0,
            'estimated_gross_egp' => 0.0,
            'gross_complete' => $complete,
            'net_known_egp' => 0.0,
            'pending_settlement_egp' => 0.0,
            'net_complete' => $complete,
            'foreign_currency_amounts' => [],
        ];
    }}
