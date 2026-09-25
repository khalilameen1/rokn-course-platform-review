<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\Order;
use App\Support\ReportPeriod;
use Illuminate\Support\Collection;

/** Attributes period activity to its accepted contract, not the learner's current tier. */
final readonly class CoursePlanHistoryReportService
{
    public function __construct(
        private CourseCashAttributionService $cash,
        private CommercialLearnerSummaryService $summaries,
    ) {
    }

    public function emptyMetrics(): array
    {
        return [
            'students' => 0,
            'paid_coins' => 0,
            'reward_coins' => 0,
            'cash_gross_egp' => 0.0,
            'cash_net_egp' => 0.0,
            'ai_requests' => 0,
            'ai_tokens' => 0,
            'ai_cost_usd' => 0.0,
        ];
    }

    /**
     * Purchase attribution uses the immutable contract, never the current tier.
     * The caller supplies period orders and their already-read ledger evidence.
     * Usage contracts may predate the period, so those are queried separately.
     *
     * @param Collection<int, Order> $orders
     * @param Collection<string, array<string, mixed>> $plans
     * @return Collection<string, array<string, mixed>>
     */
    public function forPeriod(
        Course $course,
        ReportPeriod $period,
        Collection $orders,
        Collection $allocations,
        Collection $coinAllocations,
        Collection $plans,
    ): Collection {
        $groups = $orders->groupBy(fn (Order $order): string =>
            trim((string) data_get($order->access_plan_snapshot, 'code')) ?: 'unattributed'
        );
        foreach ($groups as $code => $planOrders) {
            if (!$plans->has($code)) {
                $plans->put($code, $this->summaries->forRows(collect()) + [
                    'plan_code' => $code,
                    'plan_name' => $code === 'unattributed' ? 'فئة تاريخية غير موثقة'
                        : (string) data_get($planOrders->last()->access_plan_snapshot, 'name_ar', $code),
                ]);
            }
        }
        $ai = $this->historicalAiByPlan($course, $period);
        if (!$ai['complete'] && !$plans->has('unattributed')) {
            $plans->put('unattributed', $this->summaries->forRows(collect()) + [
                'plan_code' => 'unattributed', 'plan_name' => 'فئة تاريخية غير موثقة',
            ]);
        }
        foreach (array_keys($ai['plans']) as $code) {
            if (!$plans->has($code)) {
                $plans->put($code, $this->summaries->forRows(collect()) + [
                    'plan_code' => $code, 'plan_name' => $code,
                ]);
            }
        }
        $unattributedPurchases = $groups->has('unattributed');

        return $plans->map(function (array $plan, string $code) use ($groups, $allocations, $coinAllocations, $ai, $unattributedPurchases): array {
            $planOrders = $groups->get($code, collect());
            $cash = $this->cash->forOrders($planOrders, $allocations, $coinAllocations);
            $metrics = $this->emptyMetrics();
            $metrics['students'] = $planOrders->pluck('user_id')->unique()->count();
            foreach (['paid_coins', 'reward_coins'] as $metric) {
                $metrics[$metric] = (int) $planOrders->sum(fn (Order $order): int =>
                    (int) data_get($coinAllocations->get((int) $order->id), $metric, 0)
                );
            }
            $metrics['cash_gross_egp'] = $cash['cash_gross_complete'] ? $cash['cash_gross_egp'] : null;
            $metrics['cash_net_egp'] = $cash['cash_net_complete'] ? $cash['cash_net_known_egp'] : null;
            if (!$planOrders->every(fn (Order $order): bool => (bool) data_get($coinAllocations->get((int) $order->id), 'complete', false))) {
                foreach (['paid_coins', 'reward_coins', 'cash_gross_egp', 'cash_net_egp'] as $metric) {
                    $metrics[$metric] = null;
                }
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
}
