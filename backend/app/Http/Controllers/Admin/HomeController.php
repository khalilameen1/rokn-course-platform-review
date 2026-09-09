<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Order;
use App\Services\AiUsageReportService;
use App\Services\CoursePublishingService;
use App\Services\CourseFinancialLedgerReportService;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\PaymentChannelReportService;
use App\Services\ProviderInvoiceReportService;
use App\Support\BusinessClock;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;


class HomeController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return \App\Models\DesignSetting::getDefaultSettings();
    }


    public function index(
        Request $request,
        CoursePublishingService $publishingService,
        AdminPermissionMatrix $permissions
    )
    {
        // Content moderators enter through the authoring workspace. Financial
        // and learner-account operations stay on the administrator dashboard.
        if (!$permissions->isAdministrator(auth()->user()?->role)) {
            $revisionCourseIds = fn () => CourseAuthoringRevision::query()
                ->select('revision_course_id');
            $courses = Course::query()
                ->with([
                    'photo',
                    'teachers:id,name,name_ar,name_en,profile_image',
                    'classifications:id,name_ar,name_en',
                ])
                ->withCount(['modules', 'sections'])
                ->whereNotIn('courses.id', $revisionCourseIds())
                ->latest('updated_at')
                ->latest('id')
                ->paginate(12)
                ->withQueryString();
            $activeRevisions = CourseAuthoringRevision::query()
                ->where('status', CourseAuthoringRevision::DRAFT)
                ->whereIn('canonical_course_id', $courses->getCollection()->modelKeys())
                ->get(['canonical_course_id', 'revision_course_id'])
                ->keyBy('canonical_course_id');
            $workingDrafts = Course::query()
                ->with([
                    'photo',
                    'teachers:id,name,name_ar,name_en,profile_image',
                    'classifications:id,name_ar,name_en',
                ])
                ->withCount(['modules', 'sections'])
                ->whereIn('id', $activeRevisions->pluck('revision_course_id'))
                ->get()
                ->keyBy('id');
            $courses->setCollection($courses->getCollection()->map(function (Course $canonical) use (
                $activeRevisions,
                $workingDrafts
            ): Course {
                $revision = $activeRevisions->get((int) $canonical->id);

                return $revision
                    ? ($workingDrafts->get((int) $revision->revision_course_id) ?: $canonical)
                    : $canonical;
            }));
            $publishingAudits = $courses->getCollection()->mapWithKeys(
                fn (Course $course): array => [$course->id => $publishingService->auditCatalogCard($course)]
            );
            $contentSummary = [
                'courses' => Course::query()
                    ->whereNotIn('courses.id', $revisionCourseIds())
                    ->count(),
                'modules' => CourseModule::query()->whereHas('course', fn ($query) => $query
                    ->whereNotIn('courses.id', $revisionCourseIds()))->count(),
                'sections' => CourseSection::query()->whereHas('course', fn ($query) => $query
                    ->whereNotIn('courses.id', $revisionCourseIds()))->count(),
                'published' => Course::query()
                    ->whereNotIn('courses.id', $revisionCourseIds())
                    ->where('is_coming_soon', false)
                    ->count(),
            ];

            return view('admin.home.moderator', compact('courses', 'publishingAudits', 'contentSummary'));
        }

        return $this->administratorDashboard(
            $request,
            resolve(PaymentChannelReportService::class),
            resolve(AdminPaymentOperationsReadService::class),
            resolve(CourseFinancialLedgerReportService::class)
        );
    }

    private function administratorDashboard(
        Request $request,
        PaymentChannelReportService $paymentChannels,
        AdminPaymentOperationsReadService $paymentOperations,
        CourseFinancialLedgerReportService $financialLedger
    ) {

        // Cash-channel totals exclude sandbox/test transactions. Wallet totals
        // remain virtual units and are reported independently below.
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(ReportPeriod::labels()))],
        ]);
        $period = ReportPeriod::fromKey($filters['period'] ?? '30d');
        $previousPeriod = $period->previous();
        $paymentChannelReport = $paymentChannels->summary(
            scope: $period->apply(Order::query(), 'approved_at')
        );
        $previousPayments = $previousPeriod
            ? $paymentChannels->summary(scope: $previousPeriod->apply(Order::query(), 'approved_at'))
            : null;
        $totalRevenue = (float) $paymentChannelReport['egp']['confirmed_gross_amount'];
        $pendingCash = $paymentChannels->pendingCheckoutSummary(
            $paymentOperations->openProviderCheckouts()
        );

        $chartStart = $period->start ?? CarbonImmutable::parse(
            Order::query()->whereNotNull('package_id')->financiallyEffective()->min('approved_at')
                ?: BusinessClock::utcNow(),
            'UTC'
        );
        $chartEnd = $period->end ?? BusinessClock::utcNow();
        $monthlyGross = $paymentChannels->monthlyEgpGross($chartStart, $chartEnd);
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
        $ai = app(AiUsageReportService::class)->summary($period);
        $invoiceReport = app(ProviderInvoiceReportService::class)->summary($period);
        $previousAi = $previousPeriod ? app(AiUsageReportService::class)->summary($previousPeriod) : null;
        $aiChange = ReportPeriod::compare(
            $ai['cost_complete'] ? $ai['cost_usd'] : null,
            ($previousAi['cost_complete'] ?? false) ? $previousAi['cost_usd'] : null
        );
        $courseCoinSummaries = $financialLedger->courseSummaries(
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

        $designSettings = $this->getDesignSettings();
        $platformStats = [
            'courses' => Course::query()->count(),
            'lessons' => \App\Models\Lesson::query()->count(),
            'students' => User::query()->students()->count(),
        ];

        return view('admin.home.index', compact(
            'designSettings',
            'revenueStats',
            'monthlyRevenue',
            'paymentChannelReport',
            'courseStats',
            'platformStats',
            'period',
            'ai',
            'aiChange',
            'invoiceReport'
        ));
    }
}
