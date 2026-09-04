<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Order;
use App\Support\CsvCell;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AdminCourseReportService
{
    public function __construct(
        private CourseCommercialReportService $commercialReports,
        private CourseFinancialLedgerReportService $financialLedger,
        private CourseStagedAuthoringService $stagedAuthoring
    ) {
    }

    /** @return array{course:Course, headings:list<string>, rows:list<list<mixed>>} */
    public function csv(Course $course): array
    {
        $course = $this->stagedAuthoring->canonicalFor($course);
        $report = $this->commercialReports->forCourse($course);
        $services = CourseCostReportService::serviceLabels();

        $headings = [
            'الطالب', 'البريد', 'الحالة', 'مصدر الإتاحة', 'الفئة', 'سعر العقد بالعملات',
            'إجمالي العملات', 'خصم العملات', 'أكواد الخصم', 'أكواد الإتاحة والمنح',
            'عملات مشتراة', 'عملات مكافآت', 'إجمالي نقدي مؤكد منسوب',
            'حالة ربط دفتر العملات', 'إجمالي تقديري بسعر الكتالوج', 'حالة الإجمالي',
            'قنوات الشحن', 'صافي بوابات الدفع', 'حالة التسوية', 'طلبات AI',
            'طلبات AI فاشلة', 'طلبات AI بتكلفة تقديرية', 'حالة تكلفة AI',
            'توكنات AI', 'تكلفة AI بالدولار', 'دقائق المشاهدة', 'GB مشاهدة مقدرة',
            'تكلفة الخدمات الفعلية بالجنيه', 'التكلفة شاملة التقديرات',
            'هامش المساهمة الفعلي', 'هامش المساهمة التقديري', 'نسبة التكلفة من الصافي',
            'نسبة هامش المساهمة',
            ...array_map(fn (string $label): string => "تكلفة {$label}", $services),
        ];

        $rows = collect($report['rows'])->map(function (array $row) use ($services): array {
            return CsvCell::row([
                $row['user']?->name ?? 'مستخدم محذوف',
                $row['user']?->email,
                $row['is_active'] ? 'نشط' : 'غير نشط',
                $row['source_label'],
                $row['plan_name'],
                $row['contract_price_coins'],
                $row['total_coins'],
                $row['discount_coins'],
                implode(' | ', $row['coupon_codes']),
                implode(' | ', $row['access_codes']),
                $row['paid_coins'],
                $row['reward_coins'],
                $row['cash_gross_egp'],
                $row['coin_allocation_complete'] ? 'مكتمل' : 'غير مكتمل',
                $row['cash_estimated_gross_egp'],
                $row['cash_gross_complete'] ? 'مؤكد' : 'جزئي أو تقديري',
                collect($row['cash_channels'])->map(
                    fn (array $channel): string => $channel['label'].' ('.number_format($channel['paid_coins']).' عملة)'
                )->implode(' | '),
                $row['cash_net_known_egp'],
                $row['cash_net_complete'] ? 'مكتملة' : 'غير مكتملة',
                $row['ai_requests'],
                $row['ai_failed_requests'],
                $row['ai_estimated_requests'],
                $row['ai_cost_complete'] ? 'مؤكدة من المزود' : 'تتضمن تقديرات',
                $row['ai_tokens'],
                $row['ai_cost_usd'],
                $row['playback_minutes'],
                $row['playback_gb_estimated'],
                $row['service_cost_actual_egp'],
                $row['service_cost_with_estimates_egp'],
                $row['contribution_margin_egp'],
                $row['estimated_contribution_margin_egp'],
                $row['cost_to_net_revenue_percentage'],
                $row['contribution_margin_percentage'],
                ...array_map(
                    fn (string $key) => $row['actual_cost_by_service_egp'][$key] ?? null,
                    array_keys($services)
                ),
            ]);
        })->values()->all();

        return compact('course', 'headings', 'rows');
    }

    public function accessPlanStats(Course $course): Collection
    {
        $course = $this->stagedAuthoring->canonicalFor($course);
        $salesOrders = Order::query()
            ->where('course_id', $course->id)
            ->whereNotNull('access_plan_id')
            ->whereIn('payment_method', [
                Order::PAYMENT_METHOD_WALLET,
                Order::PAYMENT_METHOD_WALLET_COINS,
            ])
            ->financiallyEffective()
            ->with('accessPlan')
            ->get();
        $allocations = $this->financialLedger->allocationsForOrders($salesOrders);
        $sales = $salesOrders->groupBy(function (Order $order): string {
            $snapshot = is_array($order->access_plan_snapshot) ? $order->access_plan_snapshot : [];

            return (string) ($snapshot['code'] ?? $order->accessPlan?->code ?? '');
        })->map(function (Collection $orders) use ($allocations): array {
            $sum = fn (string $key): int => (int) $orders->sum(
                fn (Order $order): int => (int) data_get($allocations->get((int) $order->id), $key, 0)
            );

            return [
                'sales_count' => $orders->count(),
                'total_coins' => $sum('total_coins'),
                'paid_coins' => $sum('paid_coins'),
                'reward_coins' => $sum('reward_coins'),
                'incomplete_orders' => $orders->filter(
                    fn (Order $order): bool => !(bool) data_get(
                        $allocations->get((int) $order->id),
                        'complete',
                        false
                    )
                )->count(),
            ];
        });

        $usage = $this->usageByPlan($course);
        $features = ['course_chat', 'project_feedback', 'project_followup'];

        return $course->accessPlans->mapWithKeys(fn ($plan) => [
            $plan->code => [
                'sales_count' => (int) data_get($sales->get($plan->code), 'sales_count', 0),
                'total_coins' => (int) data_get($sales->get($plan->code), 'total_coins', 0),
                'paid_coins' => (int) data_get($sales->get($plan->code), 'paid_coins', 0),
                'reward_coins' => (int) data_get($sales->get($plan->code), 'reward_coins', 0),
                'incomplete_orders' => (int) data_get($sales->get($plan->code), 'incomplete_orders', 0),
                'chat_requests' => (int) ($usage->get($plan->code.':course_chat')?->ai_requests ?? 0),
                'chat_unanswered_requests' => (int) ($usage->get($plan->code.':course_chat')?->unanswered_requests ?? 0),
                'chat_tokens' => (int) ($usage->get($plan->code.':course_chat')?->total_tokens ?? 0),
                'chat_cost_usd' => (float) ($usage->get($plan->code.':course_chat')?->cost_usd ?? 0),
                'project_requests' => (int) ($usage->get($plan->code.':project_feedback')?->ai_requests ?? 0),
                'project_unanswered_requests' => (int) ($usage->get($plan->code.':project_feedback')?->unanswered_requests ?? 0),
                'project_tokens' => (int) ($usage->get($plan->code.':project_feedback')?->total_tokens ?? 0),
                'project_cost_usd' => (float) ($usage->get($plan->code.':project_feedback')?->cost_usd ?? 0),
                'followup_requests' => (int) ($usage->get($plan->code.':project_followup')?->ai_requests ?? 0),
                'followup_unanswered_requests' => (int) ($usage->get($plan->code.':project_followup')?->unanswered_requests ?? 0),
                'followup_tokens' => (int) ($usage->get($plan->code.':project_followup')?->total_tokens ?? 0),
                'followup_cost_usd' => (float) ($usage->get($plan->code.':project_followup')?->cost_usd ?? 0),
                'estimated_cost_requests' => (int) collect($features)->sum(
                    fn (string $feature): int => (int) ($usage->get($plan->code.':'.$feature)?->estimated_requests ?? 0)
                ),
                'total_unanswered_requests' => (int) collect($features)->sum(
                    fn (string $feature): int => (int) ($usage->get($plan->code.':'.$feature)?->unanswered_requests ?? 0)
                ),
            ],
        ]);
    }

    private function usageByPlan(Course $course): Collection
    {
        $driver = DB::connection()->getDriverName();
        $costSource = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.cost_usage_source'))",
            'pgsql' => "metadata->>'cost_usage_source'",
            'sqlite' => "json_extract(metadata, '$.cost_usage_source')",
            default => "''",
        };
        $usageSource = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.usage_source'))",
            'pgsql' => "metadata->>'usage_source'",
            'sqlite' => "json_extract(metadata, '$.usage_source')",
            default => "''",
        };
        $deliverySource = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.entitlement_delivered'))",
            'pgsql' => "metadata->>'entitlement_delivered'",
            'sqlite' => "CAST(json_extract(metadata, '$.entitlement_delivered') AS TEXT)",
            default => "'true'",
        };
        $estimatedCost = "COALESCE({$costSource}, '') NOT IN ('provider', 'cache_zero_cost')"
            ." AND COALESCE({$usageSource}, '') NOT IN ('cached_answer', 'cache_zero_cost')";

        return DB::table('ai_usage_events')
            ->leftJoin('course_access_plans as usage_plan', 'usage_plan.id', '=', 'ai_usage_events.access_plan_id')
            ->where('ai_usage_events.course_id', $course->id)
            ->where('ai_usage_events.status', 'completed')
            ->whereNotNull('ai_usage_events.access_plan_id')
            ->selectRaw("usage_plan.code as plan_code, ai_usage_events.feature, SUM(CASE WHEN COALESCE({$deliverySource}, 'true') NOT IN ('false', '0') THEN 1 ELSE 0 END) as ai_requests, SUM(CASE WHEN COALESCE({$deliverySource}, 'true') IN ('false', '0') THEN 1 ELSE 0 END) as unanswered_requests, SUM(CASE WHEN {$estimatedCost} THEN 1 ELSE 0 END) as estimated_requests, COALESCE(SUM(ai_usage_events.total_tokens),0) as total_tokens, COALESCE(SUM(ai_usage_events.cost_usd),0) as cost_usd")
            ->groupBy('usage_plan.code', 'ai_usage_events.feature')
            ->get()
            ->keyBy(fn ($row) => $row->plan_code.':'.$row->feature);
    }
}
