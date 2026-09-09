<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Order;
use App\Services\PaymentChannelReportService;
use App\Services\ProductAnalyticsService;
use App\Services\ProviderInvoiceReportService;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProductAnalyticsController extends Controller
{
    public function index(
        Request $request,
        ProductAnalyticsService $analytics,
        PaymentChannelReportService $payments
    ) {
        $filters = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'period' => ['nullable', Rule::in(array_keys(ReportPeriod::labels()))],
        ]);
        $courseId = isset($filters['course_id']) ? (int) $filters['course_id'] : null;
        if ($courseId !== null) {
            // Reporting retains archived canonical identities without granting
            // the authoring service access to edit archived course copies.
            $courseId = (int) (CourseAuthoringRevision::query()
                ->where('revision_course_id', $courseId)->value('canonical_course_id') ?: $courseId);
            Course::withTrashed()->findOrFail($courseId);
        }
        $period = ReportPeriod::fromKey($filters['period'] ?? '30d');
        // Package top-ups belong to the platform, not to the course on which
        // a learner happened to open checkout. Never relabel them as course cash.
        $paymentChannelReport = $courseId === null
            ? $payments->summary(scope: $period->apply(Order::query(), 'approved_at'))
            : null;
        $previousPeriod = $period->previous();
        $previousPayments = $paymentChannelReport !== null && $previousPeriod !== null
            ? $payments->summary(scope: $previousPeriod->apply(Order::query(), 'approved_at'))
            : null;

        return view('admin.product_analytics', [
            'analytics' => $analytics->overview($courseId, $period),
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
            'invoiceReport' => $courseId === null ? app(ProviderInvoiceReportService::class)->summary($period) : null,
            'courses' => Course::withTrashed()
                ->whereNotIn('id', CourseAuthoringRevision::query()->select('revision_course_id'))
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en']),
            'filters' => ['course_id' => $courseId, 'period' => $period->key],
        ]);
    }
}
