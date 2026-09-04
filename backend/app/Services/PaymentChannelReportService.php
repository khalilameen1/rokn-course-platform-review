<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonInterface;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PaymentChannelReportService
{
    /** @return array<string, string> */
    public function labels(): array
    {
        return [
            Order::PAYMENT_METHOD_KASHIER => 'Kashier — أندرويد مباشر/الويب',
            Order::PAYMENT_METHOD_GOOGLE_PLAY => 'Google Play',
            Order::PAYMENT_METHOD_APP_STORE => 'App Store',
        ];
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_keys($this->labels());
    }

    /** @return array{count:int,egp_amount:float} */
    public function pendingCheckoutSummary(?Builder $scope = null): array
    {
        $query = $scope ? clone $scope : Order::query();
        $query->withoutEagerLoads()->reorder()
            ->whereIn('payment_method', $this->methods())
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_PENDING);

        $count = (clone $query)->count();
        $egpAmount = (float) (clone $query)
            ->where(function ($currency): void {
                $currency->whereRaw("UPPER(gateway_currency) = 'EGP'")
                    ->orWhere(function ($legacyKashier): void {
                        $legacyKashier->whereNull('gateway_currency')
                            ->where('payment_method', Order::PAYMENT_METHOD_KASHIER);
                    });
            })
            ->sum('final_amount');

        return ['count' => $count, 'egp_amount' => $egpAmount];
    }

    /**
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   egp: array<string, float|int>,
     *   has_other_currencies: bool
     * }
     */
    public function summary(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?Builder $scope = null
    ): array {
        $query = $scope ? clone $scope : Order::query();
        $query->withoutEagerLoads()->reorder()
            ->whereIn('payment_method', $this->methods())
            ->whereNotNull('package_id')
            ->financiallyEffective();

        if ($from !== null) {
            $query->where('approved_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('approved_at', '<=', $to);
        }

        $aggregates = $query
            ->selectRaw("payment_method, CASE WHEN gateway_currency IS NULL AND payment_method IN ('google_play', 'app_store') THEN 'PENDING' ELSE COALESCE(gateway_currency, 'EGP') END as report_currency")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') = 'test_purchase' THEN 1 ELSE 0 END) as test_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') <> 'test_purchase' THEN 1 ELSE 0 END) as live_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') <> 'test_purchase' THEN COALESCE(package_coins, 0) ELSE 0 END) as live_coins")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') = 'test_purchase' THEN COALESCE(package_coins, 0) ELSE 0 END) as test_coins")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') <> 'test_purchase' THEN COALESCE(gateway_gross_amount, final_amount, 0) ELSE 0 END) as gross_amount")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') NOT IN ('test_purchase', 'catalog_estimate') AND gateway_gross_amount IS NOT NULL THEN gateway_gross_amount ELSE 0 END) as confirmed_gross_amount")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') <> 'test_purchase' AND (gateway_gross_amount IS NULL OR gateway_settlement_status = 'catalog_estimate') THEN COALESCE(final_amount, 0) ELSE 0 END) as catalog_estimated_gross_amount")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') NOT IN ('test_purchase', 'catalog_estimate') AND gateway_gross_amount IS NOT NULL THEN 1 ELSE 0 END) as confirmed_gross_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') <> 'test_purchase' AND (gateway_gross_amount IS NULL OR gateway_settlement_status = 'catalog_estimate') THEN 1 ELSE 0 END) as catalog_estimated_gross_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') NOT IN ('test_purchase', 'catalog_estimate') THEN COALESCE(gateway_fee_amount, 0) ELSE 0 END) as confirmed_fee_amount")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') NOT IN ('test_purchase', 'catalog_estimate') THEN COALESCE(gateway_net_amount, 0) ELSE 0 END) as confirmed_net_amount")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') NOT IN ('test_purchase', 'catalog_estimate') AND gateway_net_amount IS NOT NULL THEN 1 ELSE 0 END) as confirmed_net_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') <> 'test_purchase' AND gateway_net_amount IS NULL THEN 1 ELSE 0 END) as pending_settlement_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(gateway_settlement_status, '') <> 'test_purchase' THEN COALESCE(gateway_net_amount, COALESCE(gateway_gross_amount, final_amount, 0) - COALESCE(gateway_fee_amount, 0)) ELSE 0 END) as estimated_net_amount")
            ->groupBy('payment_method', 'report_currency')
            ->get()
            ->keyBy(fn (Order $row): string => $row->payment_method . ':' . strtoupper((string) $row->report_currency));

        $rows = collect();
        foreach ($this->labels() as $method => $label) {
            $matching = $aggregates->filter(
                fn (Order $row): bool => $row->payment_method === $method
            );
            if ($matching->isEmpty()) {
                $rows->push($this->row($method, $label, 'EGP', null));
                continue;
            }

            foreach ($matching as $aggregate) {
                $rows->push($this->row(
                    $method,
                    $label,
                    strtoupper((string) $aggregate->report_currency),
                    $aggregate
                ));
            }
        }

        $egpRows = $rows->where('currency', 'EGP');

        return [
            'rows' => $rows,
            'egp' => [
                'live_count' => (int) $egpRows->sum('live_count'),
                'test_count' => (int) $egpRows->sum('test_count'),
                'live_coins' => (int) $egpRows->sum('live_coins'),
                'gross_amount' => (float) $egpRows->sum('gross_amount'),
                'confirmed_gross_amount' => (float) $egpRows->sum('confirmed_gross_amount'),
                'catalog_estimated_gross_amount' => (float) $egpRows->sum('catalog_estimated_gross_amount'),
                'confirmed_gross_count' => (int) $egpRows->sum('confirmed_gross_count'),
                'catalog_estimated_gross_count' => (int) $egpRows->sum('catalog_estimated_gross_count'),
                'confirmed_fee_amount' => (float) $egpRows->sum('confirmed_fee_amount'),
                'confirmed_net_amount' => (float) $egpRows->sum('confirmed_net_amount'),
                'estimated_net_amount' => (float) $egpRows->sum('estimated_net_amount'),
                'pending_settlement_count' => (int) $egpRows->sum('pending_settlement_count'),
            ],
            'has_other_currencies' => $rows->contains(
                fn (array $row): bool => $row['currency'] !== 'EGP' && ($row['live_count'] + $row['test_count']) > 0
            ),
        ];
    }

    /**
     * One grouped query supplies the dashboard chart and month comparison.
     * Calling summary() once per month scanned the same settled-order range
     * eight times on every dashboard visit.
     *
     * @return Collection<string, float> keyed by YYYY-MM
     */
    public function monthlyEgpGross(
        CarbonInterface $from,
        CarbonInterface $to
    ): Collection {
        $totals = collect();
        Order::query()
            ->whereIn('payment_method', $this->methods())
            ->whereNotNull('package_id')
            ->financiallyEffective()
            ->where('approved_at', '>=', $from)
            ->where('approved_at', '<', $to)
            ->whereRaw("UPPER(COALESCE(gateway_currency, 'EGP')) = 'EGP'")
            ->whereNotNull('gateway_gross_amount')
            ->where(function ($query): void {
                $query->whereNull('gateway_settlement_status')
                    ->orWhereNotIn('gateway_settlement_status', ['test_purchase', 'catalog_estimate']);
            })
            ->select(['id', 'approved_at', 'gateway_gross_amount'])
            ->eachById(function ($order) use ($totals): void {
                $month = $order->approved_at
                    ->copy()
                    ->utc()
                    ->setTimezone(BusinessClock::timezoneName())
                    ->format('Y-m');
                $totals->put(
                    $month,
                    (float) $totals->get($month, 0)
                        + (float) $order->gateway_gross_amount
                );
            }, 500, 'id');

        return $totals->sortKeys();
    }

    /** @return array<string, mixed> */
    private function row(
        string $method,
        string $label,
        string $currency,
        ?Order $aggregate
    ): array {
        return [
            'method' => $method,
            'label' => $label,
            'currency' => $currency,
            'live_count' => (int) ($aggregate?->live_count ?? 0),
            'test_count' => (int) ($aggregate?->test_count ?? 0),
            'live_coins' => (int) ($aggregate?->live_coins ?? 0),
            'test_coins' => (int) ($aggregate?->test_coins ?? 0),
            'gross_amount' => (float) ($aggregate?->gross_amount ?? 0),
            'confirmed_gross_amount' => (float) ($aggregate?->confirmed_gross_amount ?? 0),
            'catalog_estimated_gross_amount' => (float) ($aggregate?->catalog_estimated_gross_amount ?? 0),
            'confirmed_gross_count' => (int) ($aggregate?->confirmed_gross_count ?? 0),
            'catalog_estimated_gross_count' => (int) ($aggregate?->catalog_estimated_gross_count ?? 0),
            'confirmed_fee_amount' => (float) ($aggregate?->confirmed_fee_amount ?? 0),
            'confirmed_net_amount' => (float) ($aggregate?->confirmed_net_amount ?? 0),
            'confirmed_net_count' => (int) ($aggregate?->confirmed_net_count ?? 0),
            'pending_settlement_count' => (int) ($aggregate?->pending_settlement_count ?? 0),
            'estimated_net_amount' => (float) ($aggregate?->estimated_net_amount ?? 0),
        ];
    }
}
