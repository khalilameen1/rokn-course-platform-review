<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\WalletDebitAllocation;
use App\Models\AiUsageEvent;
use App\Support\ReportPeriod;
use Illuminate\Support\Collection;

/** Builds the administrator's auditable learner and cash-attribution report. */
final class CourseCommercialReportService
{
    public function __construct(
        private readonly CourseCostReportService $costs,
        private readonly CourseFinancialLedgerReportService $ledger
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
                $emptyMetrics = $this->emptyPlanMetrics();
                foreach ($emptyMetrics as $metric => $_value) {
                    if ($report['plan_breakdown']->contains(fn (array $plan): bool =>
                        array_key_exists($metric, $plan['period_metrics']) && $plan['period_metrics'][$metric] === null
                    )) $emptyMetrics[$metric] = null;
                }
                $report['plan_breakdown']->put($code, $this->groupSummary(collect()) + [
                    'plan_code' => $code, 'plan_name' => $priorPlan['plan_name'],
                    'period_metrics' => $emptyMetrics,
                    'historical_attribution_complete' => !in_array(null, $emptyMetrics, true),
                ]);
            }
        }
        $report['plan_breakdown'] = $report['plan_breakdown']->map(function (array $plan, string $code) use ($previousPlans, $previousPeriod): array {
            $prior = $previousPlans->get($code);
            if ($prior === null && $previousPeriod !== null) {
                $metrics = $this->emptyPlanMetrics();
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

        $allocationsByOrder = $this->allocationsFor($orders);
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
            $cash = $this->cashForOrders(
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
            $row += $this->unitEconomics(
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
        $unitEconomics = $this->unitEconomics(
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
            'plan_breakdown' => $this->historicalPlanMetrics($course, $period, $orders, $allocationsByOrder, $coinAllocationsByOrder, $rows->groupBy('plan_code')->map(
                fn (Collection $planRows, string $planCode): array => $this->groupSummary($planRows) + [
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

    private function emptyPlanMetrics(): array
    {
        return ['students' => 0, 'paid_coins' => 0, 'reward_coins' => 0,
            'cash_gross_egp' => 0.0, 'cash_net_egp' => 0.0,
            'ai_requests' => 0, 'ai_tokens' => 0, 'ai_cost_usd' => 0.0];
    }

    private function planComparisons(array $current, ?array $previous, bool $hasPrevious): array
    {
        $prior = $previous['period_metrics'] ?? $this->emptyPlanMetrics();
        $comparisons = [];
        foreach ($current['period_metrics'] as $metric => $value) {
            $comparisons[$metric] = ReportPeriod::compare($value, $hasPrevious ? ($prior[$metric] ?? null) : null);
        }

        return $comparisons;
    }

    /** Purchase attribution uses the immutable contract, never a learner's current tier. */
    private function historicalPlanMetrics(Course $course, ReportPeriod $period, Collection $orders, Collection $allocations, Collection $coinAllocations, Collection $plans): Collection
    {
        $groups = $orders->groupBy(fn (Order $order): string =>
            trim((string) data_get($order->access_plan_snapshot, 'code')) ?: 'unattributed'
        );
        foreach ($groups as $code => $planOrders) {
            if (!$plans->has($code)) {
                $plans->put($code, $this->groupSummary(collect()) + [
                    'plan_code' => $code,
                    'plan_name' => $code === 'unattributed' ? 'فئة تاريخية غير موثقة'
                        : (string) data_get($planOrders->last()->access_plan_snapshot, 'name_ar', $code),
                ]);
            }
        }
        $ai = $this->historicalAiByPlan($course, $period);
        if (!$ai['complete'] && !$plans->has('unattributed')) {
            $plans->put('unattributed', $this->groupSummary(collect()) + [
                'plan_code' => 'unattributed', 'plan_name' => 'فئة تاريخية غير موثقة',
            ]);
        }
        foreach (array_keys($ai['plans']) as $code) {
            if (!$plans->has($code)) {
                $plans->put($code, $this->groupSummary(collect()) + [
                    'plan_code' => $code, 'plan_name' => $code,
                ]);
            }
        }
        $unattributedPurchases = $groups->has('unattributed');

        return $plans->map(function (array $plan, string $code) use ($groups, $allocations, $coinAllocations, $ai, $unattributedPurchases): array {
            $planOrders = $groups->get($code, collect());
            $cash = $this->cashForOrders($planOrders, $allocations, $coinAllocations);
            $metrics = $this->emptyPlanMetrics();
            $metrics['students'] = $planOrders->pluck('user_id')->unique()->count();
            foreach (['paid_coins', 'reward_coins'] as $metric) {
                $metrics[$metric] = (int) $planOrders->sum(fn (Order $order): int =>
                    (int) data_get($coinAllocations->get((int) $order->id), $metric, 0)
                );
            }
            $metrics['cash_gross_egp'] = $cash['cash_gross_complete'] ? $cash['cash_gross_egp'] : null;
            $metrics['cash_net_egp'] = $cash['cash_net_complete'] ? $cash['cash_net_known_egp'] : null;
            if (!$planOrders->every(fn (Order $order): bool => (bool) data_get($coinAllocations->get((int) $order->id), 'complete', false))) {
                foreach (['paid_coins', 'reward_coins', 'cash_gross_egp', 'cash_net_egp'] as $metric) $metrics[$metric] = null;
            }
            foreach (['ai_requests', 'ai_tokens', 'ai_cost_usd'] as $metric) {
                $metrics[$metric] = $ai['complete']
                    ? (isset($ai['plans'][$code]) ? $ai['plans'][$code][$metric] : 0)
                    : null;
            }
            if ($unattributedPurchases) {
                // Missing legacy contracts could belong to any tier: expose the
                // known rows, but do not turn absence of evidence into growth.
                foreach (['students', 'paid_coins', 'reward_coins', 'cash_gross_egp', 'cash_net_egp'] as $metric) {
                    $metrics[$metric] = null;
                }
            }
            $plan['period_metrics'] = $metrics;
            $plan['historical_attribution_complete'] = !$unattributedPurchases && $ai['complete'];

            return $plan;
        });
    }

    /** Resolve usage against an already accepted contract at the event time. */
    private function historicalAiByPlan(Course $course, ReportPeriod $period): array
    {
        $contracts = Order::withTrashed()->where('course_id', $course->id)
            ->whereNotNull('approved_at')->whereNotNull('access_plan_snapshot')
            ->orderByDesc('approved_at')->orderByDesc('id')
            ->get(['user_id', 'access_plan_id', 'access_plan_snapshot', 'approved_at'])
            ->groupBy('user_id');
        $query = AiUsageEvent::query()->where('course_id', $course->id);
        $period->apply($query, 'created_at');
        $plans = [];
        $complete = true;
        foreach ($query->cursor() as $event) {
            $source = data_get($event->metadata, 'cost_usage_source');
            $providerCost = $source === 'provider' && $event->cost_usd !== null
                && (float) $event->cost_usd >= 0;
            $cached = $source === 'cache_zero_cost' && $event->cost_usd !== null
                && (float) $event->cost_usd === 0.0;
            if ($event->status !== 'completed' && !$providerCost) {
                continue;
            }
            $contract = $contracts->get($event->user_id, collect())->first(fn (Order $order): bool =>
                $event->access_plan_id !== null
                && (int) $order->access_plan_id === (int) $event->access_plan_id
                && $order->approved_at <= $event->created_at
            );
            $code = trim((string) data_get($contract?->access_plan_snapshot, 'code'));
            if ($code === '') {
                $complete = false;
                continue;
            }
            $plans[$code] ??= ['ai_requests' => 0, 'ai_tokens' => 0, 'ai_cost_usd' => 0.0];
            if ($event->status === 'completed') {
                if (!in_array(data_get($event->metadata, 'entitlement_delivered', true), [false, 0, 'false', '0'], true)) {
                    $plans[$code]['ai_requests']++;
                }
                $plans[$code]['ai_tokens'] += (int) $event->total_tokens;
            }
            if ($providerCost) {
                if ($plans[$code]['ai_cost_usd'] !== null) {
                    $plans[$code]['ai_cost_usd'] = round($plans[$code]['ai_cost_usd'] + (float) $event->cost_usd, 6);
                }
            } elseif (!$cached) {
                $plans[$code]['ai_cost_usd'] = null;
            }
        }

        return compact('plans', 'complete');
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, mixed> */
    public function groupSummary(Collection $rows): array
    {
        $enrollments = $rows->count();
        $students = $rows->map(function (array $row): int {
            return (int) ($row['enrollment']?->user_id ?? $row['user']?->id ?? 0);
        })->filter()->unique()->count();
        $netComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_net_complete']);
        $costComplete = $rows->every(fn (array $row): bool => (bool) $row['service_cost_complete']);
        $estimatedComplete = $rows->every(
            fn (array $row): bool => $row['service_cost_with_estimates_egp'] !== null
        );
        $net = $netComplete ? round((float) $rows->sum('cash_net_known_egp'), 2) : null;
        $cost = $costComplete ? round((float) $rows->sum('service_cost_actual_egp'), 2) : null;
        $margin = $net !== null && $cost !== null ? round($net - $cost, 2) : null;
        $aiRequests = (int) $rows->sum('ai_requests');
        $aiFailedRequests = (int) $rows->sum('ai_failed_requests');
        $aiUnansweredRequests = (int) $rows->sum('ai_unanswered_requests');
        $aiEstimatedRequests = (int) $rows->sum('ai_estimated_requests');
        $aiMeasurementAvailable = $rows->every(
            fn (array $row): bool => (bool) ($row['ai_measurement_available'] ?? true)
        );
        $aiAttempts = $aiRequests + $aiFailedRequests + $aiUnansweredRequests;
        $actualCostByService = collect(CourseCostReportService::serviceLabels())
            ->mapWithKeys(function (string $_label, string $serviceKey) use ($rows): array {
                $complete = $rows->every(fn (array $row): bool =>
                    ($row['actual_cost_by_service_egp'][$serviceKey] ?? null) !== null
                );

                return [$serviceKey => $complete
                    ? round((float) $rows->sum(fn (array $row): float =>
                        (float) $row['actual_cost_by_service_egp'][$serviceKey]
                    ), 4)
                    : null];
            })->all();
        $estimatedCostByService = collect(CourseCostReportService::serviceLabels())
            ->mapWithKeys(function (string $_label, string $serviceKey) use ($rows): array {
                $complete = $rows->every(fn (array $row): bool =>
                    ($row['cost_with_estimates_by_service_egp'][$serviceKey] ?? null) !== null
                );

                return [$serviceKey => $complete
                    ? round((float) $rows->sum(fn (array $row): float =>
                        (float) $row['cost_with_estimates_by_service_egp'][$serviceKey]
                    ), 4)
                    : null];
            })->all();

        return [
            'students' => $students,
            'active_students' => $rows->where('is_active', true)
                ->map(fn (array $row): int => (int) ($row['enrollment']?->user_id ?? 0))
                ->filter()
                ->unique()
                ->count(),
            'enrollments' => $enrollments,
            'coin_allocation_complete' => $rows->every(
                fn (array $row): bool => (bool) ($row['coin_allocation_complete'] ?? false)
            ),
            'coins' => (int) $rows->sum('total_coins'),
            'discount_coins' => (int) $rows->sum('discount_coins'),
            'gross_egp' => round((float) $rows->sum('cash_gross_egp'), 2),
            'net_egp' => $net,
            'ai_requests' => $aiRequests,
            'ai_failed_requests' => $aiFailedRequests,
            'ai_unanswered_requests' => $aiUnansweredRequests,
            'ai_estimated_requests' => $aiEstimatedRequests,
            'ai_cost_complete' => $aiMeasurementAvailable && $aiEstimatedRequests === 0,
            'ai_failure_rate_percentage' => $aiAttempts > 0
                ? round((($aiFailedRequests + $aiUnansweredRequests) / $aiAttempts) * 100, 2)
                : null,
            'ai_tokens' => (int) $rows->sum('ai_tokens'),
            'ai_measurement_available' => $aiMeasurementAvailable,
            'ai_cost_usd' => $aiMeasurementAvailable
                ? round((float) $rows->sum('ai_cost_usd'), 6)
                : null,
            'playback_minutes' => round((float) $rows->sum('playback_minutes'), 2),
            'playback_gb_estimated' => round((float) $rows->sum('playback_gb_estimated'), 4),
            'service_cost_egp' => $cost,
            'service_breakdown_actual_egp' => $actualCostByService,
            'margin_egp' => $margin,
            'estimated_cost_egp' => $estimatedComplete
                ? round((float) $rows->sum('service_cost_with_estimates_egp'), 2)
                : null,
            'service_breakdown_with_estimates_egp' => $estimatedCostByService,
            'estimated_margin_egp' => $netComplete && $estimatedComplete
                ? round((float) $rows->sum('estimated_contribution_margin_egp'), 2)
                : null,
            'average_net_per_student_egp' => $students > 0 && $net !== null
                ? round($net / $students, 2)
                : null,
            'average_cost_per_student_egp' => $students > 0 && $cost !== null
                ? round($cost / $students, 2)
                : null,
            'average_net_per_enrollment_egp' => $enrollments > 0 && $net !== null
                ? round($net / $enrollments, 2)
                : null,
            'average_cost_per_enrollment_egp' => $enrollments > 0 && $cost !== null
                ? round($cost / $enrollments, 2)
                : null,
        ] + $this->unitEconomics($net, $cost, $margin);
    }

    /** @return array<string, float|null> */
    private function unitEconomics(?float $net, ?float $cost, ?float $margin): array
    {
        return [
            'cost_to_net_revenue_percentage' => $net !== null && $net > 0 && $cost !== null
                ? round(($cost / $net) * 100, 2)
                : null,
            'contribution_margin_percentage' => $net !== null && $net > 0 && $margin !== null
                ? round(($margin / $net) * 100, 2)
                : null,
        ];
    }

    /** @param Collection<int, Order> $orders */
    private function allocationsFor(Collection $orders): Collection
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
    private function cashForOrders(
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
                    $channels['service_compensation'] ??= [
                        'method' => 'service_compensation',
                        'label' => 'تعويض خدمة بلا تحصيل جديد',
                        'paid_coins' => 0,
                        'gross_egp' => 0.0,
                        'estimated_gross_egp' => 0.0,
                        'gross_complete' => true,
                        'net_known_egp' => 0.0,
                        'pending_settlement_egp' => 0.0,
                        'net_complete' => true,
                        'foreign_currency_amounts' => [],
                    ];
                    $channels['service_compensation']['paid_coins'] += $coins;
                    continue;
                }
                if (!$source) {
                    continue;
                }

                $orderAllocatedCoins += $coins;
                $allocatedCoins += $coins;
                if ($source->financial_status !== Order::FINANCIAL_SETTLED) {
                    $channels['unreconciled'] ??= $this->unreconciledCashChannel();
                    $channels['unreconciled']['paid_coins'] += $coins;
                    $reconciliationMissing = true;
                    continue;
                }

                if ($source->gateway_settlement_status === 'test_purchase') {
                    $testChannel = (string) $source->payment_method . '_test';
                    $channels[$testChannel] ??= [
                        'method' => $testChannel,
                        'label' => match ($source->payment_method) {
                            Order::PAYMENT_METHOD_KASHIER => 'Kashier — اختبار بلا دخل',
                            Order::PAYMENT_METHOD_GOOGLE_PLAY => 'Google Play — اختبار بلا دخل',
                            Order::PAYMENT_METHOD_APP_STORE => 'App Store — اختبار بلا دخل',
                            default => 'عملية اختبار بلا دخل',
                        },
                        'paid_coins' => 0,
                        'gross_egp' => 0.0,
                        'estimated_gross_egp' => 0.0,
                        'gross_complete' => true,
                        'net_known_egp' => 0.0,
                        'pending_settlement_egp' => 0.0,
                        'net_complete' => true,
                        'foreign_currency_amounts' => [],
                    ];
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
                $channels[$method] ??= [
                    'method' => $method,
                    'label' => match ($method) {
                        Order::PAYMENT_METHOD_KASHIER => 'Kashier',
                        Order::PAYMENT_METHOD_GOOGLE_PLAY => 'Google Play',
                        Order::PAYMENT_METHOD_APP_STORE => 'App Store',
                        default => $method,
                    },
                    'paid_coins' => 0,
                    'gross_egp' => 0.0,
                    'estimated_gross_egp' => 0.0,
                    'gross_complete' => true,
                    'net_known_egp' => 0.0,
                    'pending_settlement_egp' => 0.0,
                    'net_complete' => true,
                    'foreign_currency_amounts' => [],
                ];
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
                $channels['unreconciled'] ??= $this->unreconciledCashChannel();
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
    private function unreconciledCashChannel(): array
    {
        return [
            'method' => 'unreconciled',
            'label' => 'مصدر شحن غير مُسوّى',
            'paid_coins' => 0,
            'gross_egp' => 0.0,
            'estimated_gross_egp' => 0.0,
            'gross_complete' => false,
            'net_known_egp' => 0.0,
            'pending_settlement_egp' => 0.0,
            'net_complete' => false,
            'foreign_currency_amounts' => [],
        ];
    }
}
