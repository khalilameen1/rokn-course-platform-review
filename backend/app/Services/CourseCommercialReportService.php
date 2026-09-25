<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Support\ReportPeriod;
use Illuminate\Support\Collection;

/** Builds the administrator's auditable learner and cash-attribution report. */
final class CourseCommercialReportService
{
    public function __construct(
        private readonly CourseCostReportService $costs,
        private readonly CourseFinancialLedgerReportService $ledger,
        private readonly CourseCashAttributionService $cash,
        private readonly CommercialLearnerSummaryService $summaries,
        private readonly CoursePlanHistoryReportService $plans
    ) {
    }

    /** @return array<string, mixed> */
    public function forCourse(Course $course, ?ReportPeriod $period = null, bool $withComparisons = true): array
    {
        $period ??= ReportPeriod::fromKey('all');
        $enrollments = CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->with(['user', 'order.courseCode', 'accessPlanOrder.courseCode', 'accessPlan'])
            ->orderByDesc('is_active')
            ->orderByDesc('access_granted_at')
            ->get();

        // Load learner identities once. A learner who joined before the window
        // can still incur a purchase or provider cost inside it.
        $report = $this->periodReport($course, $enrollments, $period);
        $previousPeriod = $withComparisons ? $period->previous() : null;
        $previous = $previousPeriod === null
            ? null
            : $this->periodReport($course, $enrollments, $previousPeriod);
        $report['comparisons'] = $this->comparisons($report, $previous);
        $previousPlans = $previous['plan_breakdown'] ?? collect();
        foreach ($previousPlans as $code => $priorPlan) {
            if (!$report['plan_breakdown']->has($code)) {
                $emptyMetrics = $this->plans->emptyMetrics();
                foreach ($emptyMetrics as $metric => $_value) {
                    if ($report['plan_breakdown']->contains(fn (array $plan): bool =>
                        array_key_exists($metric, $plan['period_metrics']) && $plan['period_metrics'][$metric] === null
                    )) $emptyMetrics[$metric] = null;
                }
                $report['plan_breakdown']->put($code, $this->summaries->forRows(collect()) + [
                    'plan_code' => $code, 'plan_name' => $priorPlan['plan_name'],
                    'period_metrics' => $emptyMetrics,
                    'historical_attribution_complete' => !in_array(null, $emptyMetrics, true),
                ]);
            }
        }
        $report['plan_breakdown'] = $report['plan_breakdown']->map(function (array $plan, string $code) use ($previousPlans, $previousPeriod): array {
            $prior = $previousPlans->get($code);
            if ($prior === null && $previousPeriod !== null) {
                $metrics = $this->plans->emptyMetrics();
                foreach ($metrics as $metric => $_value) {
                    if ($previousPlans->contains(fn (array $previousPlan): bool =>
                        array_key_exists($metric, $previousPlan['period_metrics']) && $previousPlan['period_metrics'][$metric] === null
                    )) $metrics[$metric] = null;
                }
                $prior = ['period_metrics' => $metrics];
            }
            $plan['comparisons'] = $this->planComparisons($plan, $prior, $previousPeriod !== null);

            return $plan;
        });

        return $report;
    }

    /** @param Collection<int, CourseEnrollment> $enrollments */
    private function periodReport(Course $course, Collection $enrollments, ReportPeriod $period): array
    {

        $orders = Order::query()
            ->where('course_id', $course->id)
            ->financiallyEffective()
            ->with(['user', 'courseCode', 'accessPlan'])
            ->orderBy('approved_at')
            ->orderBy('id');
        $orders = $period->apply($orders, 'approved_at')->get();

        $allocationsByOrder = $this->cash->allocationsFor($orders);
        $coinAllocationsByOrder = $this->ledger->allocationsForOrders($orders);
        $ordersByUser = $orders->groupBy(fn (Order $order): int => (int) $order->user_id);
        $costReport = $this->costs->forCourse(
            $course,
            $enrollments->pluck('user_id')->map(fn ($id): int => (int) $id),
            $period
        );
        $rows = $enrollments->map(function (CourseEnrollment $enrollment) use (
            $ordersByUser,
            $allocationsByOrder,
            $coinAllocationsByOrder,
            $costReport
        ): array {
            /** @var Collection<int, Order> $learnerOrders */
            $learnerOrders = $ordersByUser->get((int) $enrollment->user_id, collect());
            $cash = $this->cash->forOrders(
                $learnerOrders,
                $allocationsByOrder,
                $coinAllocationsByOrder
            );
            $currentOrder = $enrollment->accessPlanOrder ?: $enrollment->order;
            $snapshot = is_array($enrollment->access_plan_snapshot)
                ? $enrollment->access_plan_snapshot
                : (is_array($currentOrder?->access_plan_snapshot)
                    ? $currentOrder->access_plan_snapshot
                    : []);
            $grantOrder = $learnerOrders->first(
                fn (Order $order): bool => $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
                    && (bool) $order->courseCode?->isInstitutionalGrant()
            );
            $codeOrder = $learnerOrders->first(
                fn (Order $order): bool => $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
            );
            $hasPaidOrder = $learnerOrders->contains(
                fn (Order $order): bool => (int) data_get(
                    $coinAllocationsByOrder->get((int) $order->id),
                    'paid_coins',
                    0
                ) > 0
            );
            $source = $grantOrder && $hasPaidOrder
                ? 'grant_plus_purchase'
                : ($grantOrder ? 'grant' : ($codeOrder && $hasPaidOrder
                    ? 'code_plus_purchase'
                    : ($codeOrder ? 'course_code' : 'purchase')));
            $sourceLabel = match ($source) {
                'grant_plus_purchase' => 'منحة + شراء/ترقية',
                'code_plus_purchase' => 'كود إتاحة + شراء/ترقية',
                'grant' => 'منحة',
                'course_code' => 'كود إتاحة',
                default => 'شراء',
            };

            $cost = $costReport['users']->get(
                (int) $enrollment->user_id,
                [
                    'ai_requests' => 0, 'ai_failed_requests' => 0,
                    'ai_unanswered_requests' => 0, 'ai_tokens' => 0,
                    'ai_estimated_requests' => 0, 'ai_cost_complete' => true,
                    'ai_measurement_available' => true,
                    'ai_cost_usd' => 0.0, 'ai_cost_egp' => 0.0,
                    'playback_minutes' => 0.0, 'playback_gb_estimated' => 0.0,
                    'allocated_operating_cost_egp' => 0.0,
                    'estimated_operating_cost_egp' => 0.0,
                    'service_cost_actual_egp' => 0.0,
                    'service_cost_with_estimates_egp' => 0.0,
                    'service_cost_complete' => true,
                    'service_cost_estimate_complete' => true,
                ]
            );

            $row = [
                'enrollment' => $enrollment,
                'user' => $enrollment->user,
                'is_active' => $enrollment->isActive()
                    && $enrollment->user !== null
                    && !$enrollment->user->trashed()
                    && strtolower((string) $enrollment->user->role) === 'client',
                'source' => $source,
                'source_label' => $sourceLabel,
                'plan_code' => (string) ($snapshot['code'] ?? $enrollment->accessPlan?->code ?? ''),
                'plan_name' => (string) ($snapshot['name_ar'] ?? $enrollment->accessPlan?->name_ar ?? 'إتاحة قديمة'),
                'contract_price_coins' => isset($snapshot['price_coins'])
                    ? (int) $snapshot['price_coins']
                    : null,
                'discount_coins' => (int) $learnerOrders->sum('discount_amount'),
                'coupon_codes' => $learnerOrders->pluck('coupon_code')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'access_codes' => $learnerOrders
                    ->map(fn (Order $order): ?string => $order->courseCode?->code)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'total_coins' => (int) $learnerOrders->sum(
                    fn (Order $order): int => (int) data_get(
                        $coinAllocationsByOrder->get((int) $order->id),
                        'total_coins',
                        0
                    )
                ),
                'paid_coins' => (int) $learnerOrders->sum(
                    fn (Order $order): int => (int) data_get(
                        $coinAllocationsByOrder->get((int) $order->id),
                        'paid_coins',
                        0
                    )
                ),
                'reward_coins' => (int) $learnerOrders->sum(
                    fn (Order $order): int => (int) data_get(
                        $coinAllocationsByOrder->get((int) $order->id),
                        'reward_coins',
                        0
                    )
                ),
                'coin_allocation_complete' => $learnerOrders->every(
                    fn (Order $order): bool => (bool) data_get(
                        $coinAllocationsByOrder->get((int) $order->id),
                        'complete',
                        false
                    )
                ),
                'orders_count' => $learnerOrders->count(),
                'purchased_at' => $currentOrder?->approved_at ?: $enrollment->access_granted_at,
            ] + $cash + $cost;
            $row['contribution_margin_egp'] = $row['cash_net_complete']
                && $row['service_cost_complete']
                && $row['service_cost_actual_egp'] !== null
                    ? round(
                        (float) $row['cash_net_known_egp']
                        - (float) $row['service_cost_actual_egp'],
                        2
                    )
                    : null;
            $row['estimated_contribution_margin_egp'] = $row['cash_net_complete']
                && $row['service_cost_with_estimates_egp'] !== null
                    ? round(
                        (float) $row['cash_net_known_egp']
                        - (float) $row['service_cost_with_estimates_egp'],
                        2
                    )
                    : null;
            $row += $this->summaries->unitEconomics(
                $row['cash_net_complete'] ? (float) $row['cash_net_known_egp'] : null,
                $row['service_cost_actual_egp'],
                $row['contribution_margin_egp']
            );

            return $row;
        })->values();

        $gross = round((float) $rows->sum('cash_gross_egp'), 2);
        $estimatedGross = round((float) $rows->sum('cash_estimated_gross_egp'), 2);
        $knownNet = round((float) $rows->sum('cash_net_known_egp'), 2);
        $pendingGross = round((float) $rows->sum('cash_pending_settlement_egp'), 2);
        $cashNetComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_net_complete']);
        $cashGrossComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_gross_complete']);
        $foreignCurrencyExposure = $rows
            ->flatMap(fn (array $row): array => collect($row['cash_foreign_currency_amounts'])
                ->map(fn (float $amount, string $currency): array => [
                    'currency' => $currency,
                    'amount' => $amount,
                ])->values()->all())
            ->groupBy('currency')
            ->map(fn (Collection $items): float => round((float) $items->sum('amount'), 2))
            ->all();
        // A directly invoiced course cost is not an invented allocation to its
        // individual students, so the course collector owns this total.
        $serviceCostComplete = (bool) ($costReport['service_cost_complete'] ?? $costReport['complete']);
        $serviceCost = $serviceCostComplete ? $costReport['service_cost_actual_egp'] : null;
        $unitEconomics = $this->summaries->unitEconomics(
            $cashNetComplete ? $knownNet : null,
            $serviceCost,
            $cashNetComplete && $serviceCostComplete
                ? round($knownNet - (float) $serviceCost, 2)
                : null
        );
        $cashChannels = $rows
            ->flatMap(fn (array $row): array => array_values($row['cash_channels'] ?? []))
            ->groupBy('method')
            ->map(function (Collection $items): array {
                $foreign = $items->flatMap(function (array $item): array {
                    return collect($item['foreign_currency_amounts'] ?? [])
                        ->map(fn (float $amount, string $currency): array => [
                            'currency' => $currency,
                            'amount' => $amount,
                        ])->values()->all();
                })->groupBy('currency')->map(
                    fn (Collection $amounts): float => round((float) $amounts->sum('amount'), 2)
                )->all();

                return [
                    'method' => (string) $items->first()['method'],
                    'label' => (string) $items->first()['label'],
                    'paid_coins' => (int) $items->sum('paid_coins'),
                    'gross_egp' => round((float) $items->sum('gross_egp'), 2),
                    'estimated_gross_egp' => round((float) $items->sum('estimated_gross_egp'), 2),
                    'gross_complete' => $items->every(fn (array $item): bool => (bool) $item['gross_complete']),
                    'net_known_egp' => round((float) $items->sum('net_known_egp'), 2),
                    'pending_settlement_egp' => round((float) $items->sum('pending_settlement_egp'), 2),
                    'net_complete' => $items->every(fn (array $item): bool => (bool) $item['net_complete']),
                    'foreign_currency_amounts' => $foreign,
                ];
            })->values();

        return [
            'rows' => $rows,
            'active_students' => $rows->where('is_active', true)->count(),
            'historical_students' => $rows->count(),
            'new_students' => $enrollments->filter(fn (CourseEnrollment $enrollment): bool =>
                $period->contains($enrollment->enrolled_at ?: $enrollment->created_at)
            )->count(),
            'grant_students' => $rows->filter(fn (array $row): bool => str_starts_with($row['source'], 'grant'))->count(),
            'code_students' => $rows->filter(fn (array $row): bool => str_contains($row['source'], 'code'))->count(),
            'paid_students' => $rows->where('paid_coins', '>', 0)->count(),
            'coin_allocation_complete' => $rows->every(
                fn (array $row): bool => (bool) $row['coin_allocation_complete']
            ),
            'total_coins' => (int) $rows->sum('total_coins'),
            'discount_coins' => (int) $orders->sum('discount_amount'),
            'paid_coins' => (int) $rows->sum('paid_coins'),
            'reward_coins' => (int) $rows->sum('reward_coins'),
            'cash_gross_egp' => $gross,
            'cash_estimated_gross_egp' => $estimatedGross,
            'cash_gross_complete' => $cashGrossComplete,
            'cash_net_known_egp' => $knownNet,
            'cash_pending_settlement_egp' => $pendingGross,
            'cash_net_complete' => $cashNetComplete,
            'cash_foreign_currency_exposure' => $foreignCurrencyExposure,
            'cash_channel_breakdown' => $cashChannels,
            'cash_net_egp' => $cashNetComplete ? $knownNet : null,
            'ai_cost_usd' => $costReport['ai_cost_usd'],
            'ai_requests' => (int) ($costReport['ai_requests'] ?? $rows->sum('ai_requests')),
            'ai_failed_requests' => (int) ($costReport['ai_failed_requests'] ?? $rows->sum('ai_failed_requests')),
            'ai_unanswered_requests' => (int) ($costReport['ai_unanswered_requests'] ?? $rows->sum('ai_unanswered_requests')),
            'ai_tokens' => (int) ($costReport['ai_tokens'] ?? $rows->sum('ai_tokens')),
            'ai_pending_cost_requests' => (int) ($costReport['ai_pending_cost_requests'] ?? $rows->sum('ai_estimated_requests')),
            'ai_estimated_requests' => (int) ($costReport['ai_pending_cost_requests'] ?? $rows->sum('ai_estimated_requests')),
            'ai_cost_complete' => (bool) ($costReport['ai_cost_complete'] ?? $rows->every(fn (array $row): bool => (bool) $row['ai_cost_complete'])),
            'playback_minutes' => $costReport['playback_minutes'],
            'playback_gb_estimated' => $costReport['playback_gb_estimated'],
            'service_cost_complete' => $serviceCostComplete,
            'service_cost_actual_egp' => $serviceCost,
            'service_cost_with_estimates_egp' => $costReport['service_cost_with_estimates_egp'],
            'estimated_contribution_margin_egp' => $cashNetComplete
                && $costReport['service_cost_with_estimates_egp'] !== null
                    ? round($knownNet - (float) $costReport['service_cost_with_estimates_egp'], 2)
                    : null,
            'contribution_margin_egp' => $cashNetComplete && $serviceCostComplete
                ? round($knownNet - (float) $serviceCost, 2)
                : null,
            'cost_warnings' => $costReport['unallocated_pools'],
            'service_breakdown' => $costReport['service_breakdown'],
            'plan_breakdown' => $this->plans->forPeriod($course, $period, $orders, $allocationsByOrder, $coinAllocationsByOrder, $rows->groupBy('plan_code')->map(
                fn (Collection $planRows, string $planCode): array => $this->summaries->forRows($planRows) + [
                    'plan_code' => $planCode,
                    'plan_name' => (string) ($planRows->first()['plan_name'] ?? 'إتاحة قديمة'),
                ]
            )),
        ] + $unitEconomics;
    }

    private function comparisons(array $current, ?array $previous): array
    {
        $metrics = ['new_students', 'paid_coins', 'reward_coins', 'cash_gross_egp',
            'cash_net_egp', 'ai_requests', 'ai_tokens', 'ai_cost_usd',
            'service_cost_actual_egp', 'playback_minutes'];
        $result = [];
        foreach ($metrics as $metric) {
            $value = $current[$metric] ?? null;
            $prior = $previous[$metric] ?? null;
            if ($metric === 'ai_cost_usd') {
                if (!($current['ai_cost_complete'] ?? false)) $value = null;
                if (!($previous['ai_cost_complete'] ?? false)) $prior = null;
            }
            if ($metric === 'cash_gross_egp') {
                if (!$current['cash_gross_complete'] || !$current['coin_allocation_complete']) $value = null;
                if (!($previous['cash_gross_complete'] ?? false) || !($previous['coin_allocation_complete'] ?? false)) $prior = null;
            }
            if (in_array($metric, ['paid_coins', 'reward_coins', 'cash_net_egp'], true)) {
                if (!$current['coin_allocation_complete']) $value = null;
                if (!($previous['coin_allocation_complete'] ?? false)) $prior = null;
            }
            $result[$metric] = ReportPeriod::compare($value, $prior);
        }

        return $result;
    }

    private function planComparisons(array $current, ?array $previous, bool $hasPrevious): array
    {
        $prior = $previous['period_metrics'] ?? $this->plans->emptyMetrics();
        $comparisons = [];
        foreach ($current['period_metrics'] as $metric => $value) {
            $comparisons[$metric] = ReportPeriod::compare($value, $hasPrevious ? ($prior[$metric] ?? null) : null);
        }

        return $comparisons;
    }


}
