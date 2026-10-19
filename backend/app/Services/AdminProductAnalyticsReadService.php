<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Order;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Collection;

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
        $courseId = $this->canonicalCourseId($courseId);
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
            'courses' => $this->courses(),
            'filters' => ['course_id' => $courseId, 'period' => $period->key],
        ];
    }

    /** @return Collection<int, Course> */
    public function courses(): Collection
    {
        return Course::withTrashed()->whereNotIn('id', CourseAuthoringRevision::query()->select('revision_course_id'))
            ->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']);
    }

    public function canonicalCourseId(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }
        // Reporting retains archived identities without granting authoring access.
        $canonical = (int) (CourseAuthoringRevision::query()->where('revision_course_id', $id)->value('canonical_course_id') ?: $id);

        return (int) Course::withTrashed()->findOrFail($canonical, ['id'])->id;
    }
}
