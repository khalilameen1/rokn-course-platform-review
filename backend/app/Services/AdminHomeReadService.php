<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\DesignSetting;
use App\Models\Order;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;

/** Administrator home projection. Cash, virtual coins and costs remain separate reports. */
final readonly class AdminHomeReadService
{
    public function __construct(
        private PaymentChannelReportService $paymentChannels,
        private AdminPaymentOperationsReadService $paymentOperations,
        private CourseFinancialLedgerReportService $financialLedger,
        private AiUsageReportService $aiUsage,
        private ProviderInvoiceReportService $invoices,
        private AdminContentInventoryReadService $inventory
    ) {
    }

    /** @return array<string,mixed> */
    public function read(ReportPeriod $period): array
    {
        $previousPeriod = $period->previous();
        $paymentChannelReport = $this->paymentChannels->summary(
            scope: $period->apply(Order::query(), 'approved_at')
        );
        $previousPayments = $previousPeriod
            ? $this->paymentChannels->summary(scope: $previousPeriod->apply(Order::query(), 'approved_at'))
            : null;
        $totalRevenue = (float) $paymentChannelReport['egp']['confirmed_gross_amount'];
        $pendingCash = $this->paymentChannels->pendingCheckoutSummary(
            $this->paymentOperations->openProviderCheckouts()
        );

        $chartStart = $period->start ?? CarbonImmutable::parse(
            Order::query()->whereNotNull('package_id')->financiallyEffective()->min('approved_at')
                ?: BusinessClock::utcNow(),
            'UTC'
        );
        $chartEnd = $period->end ?? BusinessClock::utcNow();
        $monthlyGross = $this->paymentChannels->monthlyEgpGross($chartStart, $chartEnd);
        $monthlyRevenue = [];
        for ($date = $chartStart->setTimezone(BusinessClock::timezoneName())->startOfMonth(); $date->lt($chartEnd); $date = $date->addMonth()) {
            $monthName = $date->locale('ar')->translatedFormat('M Y');
            $monthCashRevenue = (float) $monthlyGross->get($date->format('Y-m'), 0);

            $monthlyRevenue[] = [
                'month' => $monthName,
                'course_revenue' => $monthCashRevenue,
            ];
        }

        // Revenue Statistics Summary
        $revenueStats = [
            'total_revenue' => $totalRevenue,
            'catalog_estimated_revenue' => (float) $paymentChannelReport['egp']['catalog_estimated_gross_amount'],
            'gross_complete' => $paymentChannelReport['egp']['catalog_estimated_gross_count'] === 0,
            'confirmed_gross_count' => $paymentChannelReport['egp']['confirmed_gross_count'],
            'pending_payments' => $pendingCash['egp_amount'],
            'pending_bills_count' => $pendingCash['count'],
            'confirmed_net_revenue' => $paymentChannelReport['egp']['confirmed_net_amount'],
            'provider_settlement_pending_count' => $paymentChannelReport['egp']['pending_settlement_count'],
            'confirmed_net_count' => (int) $paymentChannelReport['rows']->where('currency', 'EGP')->sum('confirmed_net_count'),
            'previous_period_revenue' => $previousPayments['egp']['confirmed_gross_amount'] ?? null,
            'previous_gross_unknown' => ($previousPayments['egp']['catalog_estimated_gross_count'] ?? 0) > 0
                && ($previousPayments['egp']['confirmed_gross_count'] ?? 0) === 0,
            'revenue_change' => ReportPeriod::compare(
                $paymentChannelReport['egp']['catalog_estimated_gross_count'] === 0 ? $totalRevenue : null,
                ($previousPayments['egp']['catalog_estimated_gross_count'] ?? 1) === 0
                    ? $previousPayments['egp']['confirmed_gross_amount'] : null
            ),
            'net_change' => ReportPeriod::compare(
                $paymentChannelReport['egp']['pending_settlement_count'] === 0
                    ? $paymentChannelReport['egp']['confirmed_net_amount'] : null,
                ($previousPayments['egp']['pending_settlement_count'] ?? 1) === 0
                    ? $previousPayments['egp']['confirmed_net_amount'] : null
            ),
        ];
        $ai = $this->aiUsage->summary($period);
        $invoiceReport = $this->invoices->summary($period);
        $previousAi = $previousPeriod ? $this->aiUsage->summary($previousPeriod) : null;
        $aiChange = ReportPeriod::compare(
            $ai['cost_complete'] ? $ai['cost_usd'] : null,
            ($previousAi['cost_complete'] ?? false) ? $previousAi['cost_usd'] : null
        );
        $courseCoinSummaries = $this->financialLedger->courseSummaries(
            null,
            $period->start,
            $period->end
        );
        $courseNames = Course::withTrashed()
            ->whereIn('id', $courseCoinSummaries->keys())
            ->get(['id', 'name_ar', 'name_en'])
            ->keyBy('id');
        $courseStats = $courseCoinSummaries
            ->filter(fn (array $summary, int $courseId): bool => $courseNames->has($courseId))
            ->map(function (array $summary, int $courseId) use ($courseNames, $period): array {
                $course = $courseNames->get($courseId);

                return [
                    'name' => (string) ($course?->name_ar ?: $course?->name_en),
                    'total_buy_count' => (int) $summary['total_buy_count'],
                    'paid_coins' => (int) $summary['paid_coins'],
                    'reward_coins' => (int) $summary['reward_coins'],
                    'current_period_buy_count' => (int) ($period->key === 'all' ? $summary['total_buy_count'] : $summary['current_period_buy_count']),
                    'incomplete_orders' => (int) $summary['incomplete_orders'],
                ];
            })
            ->sortByDesc('paid_coins')
            ->values();

        $designSettings = DesignSetting::getDefaultSettings();
        $content = $this->inventory->summary();
        $platformStats = [
            'courses' => $content['courses'],
            'lessons' => $content['lessons'],
            'students' => User::query()->students()->count(),
        ];

        return compact(
            'designSettings', 'revenueStats', 'monthlyRevenue', 'paymentChannelReport',
            'courseStats', 'platformStats', 'period', 'ai', 'aiChange', 'invoiceReport'
        );
    }
}
