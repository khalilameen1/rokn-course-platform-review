<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Order;
use App\Support\ReportPeriod;

/** One read-only analytics composition for both dashboard transports. */
final readonly class AdminProductAnalyticsReadService
{
    public function __construct(
        private ProductAnalyticsService $analytics,
        private PaymentChannelReportService $payments,
        private ProviderInvoiceReportService $invoices,
    ) {}

    /** @return array<string, mixed> */
    public function report(?int $courseId = null, string $periodKey = '30d'): array
    {
        if ($courseId !== null) {
            // Reporting retains archived canonical identities without granting
            // the authoring service access to edit archived course copies.
            $courseId = (int) (CourseAuthoringRevision::query()
                ->where('revision_course_id', $courseId)->value('canonical_course_id') ?: $courseId);
            Course::withTrashed()->findOrFail($courseId);
        }
        $period = ReportPeriod::fromKey($periodKey);
        // Package top-ups belong to the platform, not to the course on which
        // a learner happened to open checkout. Never relabel them as course cash.
        $paymentChannelReport = $courseId === null
            ? $this->payments->summary(scope: $period->apply(Order::query(), 'approved_at'))
            : null;
        $previousPeriod = $period->previous();
        $previousPayments = $paymentChannelReport !== null && $previousPeriod !== null
            ? $this->payments->summary(scope: $previousPeriod->apply(Order::query(), 'approved_at'))
            : null;

        return [
            'analytics' => $this->analytics->overview($courseId, $period),
            'paymentChannelReport' => $paymentChannelReport,
            'paymentChanges' => [
                'gross' => ReportPeriod::compare(
                    ($paymentChannelReport['egp']['catalog_estimated_gross_count'] ?? 1) === 0
                        ? $paymentChannelReport['egp']['confirmed_gross_amount'] : null,
                    ($previousPayments['egp']['catalog_estimated_gross_count'] ?? 1) === 0
                        ? $previousPayments['egp']['confirmed_gross_amount'] : null
                ),
                'net' => ReportPeriod::compare(
                    ($paymentChannelReport['egp']['pending_settlement_count'] ?? 1) === 0
                        ? $paymentChannelReport['egp']['confirmed_net_amount'] : null,
                    ($previousPayments['egp']['pending_settlement_count'] ?? 1) === 0
                        ? $previousPayments['egp']['confirmed_net_amount'] : null
                ),
            ],
            'period' => $period,
            'invoiceReport' => $courseId === null ? $this->invoices->summary($period) : null,
            'courses' => Course::withTrashed()
                ->whereNotIn('id', CourseAuthoringRevision::query()->select('revision_course_id'))
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en']),
            'filters' => ['course_id' => $courseId, 'period' => $period->key],
        ];
    }
}
