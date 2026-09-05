<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Services\CoursePublishingService;
use App\Services\CourseFinancialLedgerReportService;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\PaymentChannelReportService;
use App\Support\BusinessClock;


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
        CoursePublishingService $publishingService,
        PaymentChannelReportService $paymentChannels,
        AdminPaymentOperationsReadService $paymentOperations,
        CourseFinancialLedgerReportService $financialLedger,
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

        // Cash-channel totals exclude sandbox/test transactions. Wallet totals
        // remain virtual units and are reported independently below.
        $paymentChannelReport = $paymentChannels->summary();
        $totalRevenue = (float) $paymentChannelReport['egp']['confirmed_gross_amount'];
        $pendingCash = $paymentChannels->pendingCheckoutSummary(
            $paymentOperations->openProviderCheckouts()
        );

        $businessNow = BusinessClock::now();
        $chartStart = $businessNow->subMonths(5)->startOfMonth()->utc();
        $chartEnd = $businessNow->addMonth()->startOfMonth()->utc();
        $monthlyGross = $paymentChannels->monthlyEgpGross($chartStart, $chartEnd);
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = $businessNow->subMonths($i);
            $monthName = $date->locale('ar')->format('M Y');
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
            'pending_payments' => $pendingCash['egp_amount'],
            'pending_bills_count' => $pendingCash['count'],
            'confirmed_net_revenue' => $paymentChannelReport['egp']['confirmed_net_amount'],
            'provider_settlement_pending_count' => $paymentChannelReport['egp']['pending_settlement_count'],
        ];

        $currentMonth = $businessNow;
        $previousMonth = $businessNow->subMonth();
        $currentMonthRevenue = (float) $monthlyGross->get($currentMonth->format('Y-m'), 0);
        $previousMonthRevenue = (float) $monthlyGross->get($previousMonth->format('Y-m'), 0);

        $revenueStats['current_month_revenue'] = $currentMonthRevenue;
        $revenueStats['previous_month_revenue'] = $previousMonthRevenue;

        $revenueStats['revenue_growth'] = $previousMonthRevenue > 0
            ? (float) ((($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100)
            : 0;

        $monthStart = $currentMonth->startOfMonth()->utc();
        $nextMonthStart = $currentMonth->addMonth()->startOfMonth()->utc();
        $courseCoinSummaries = $financialLedger->courseSummaries(
            null,
            $monthStart,
            $nextMonthStart
        );
        $courseNames = Course::withTrashed()
            ->whereIn('id', $courseCoinSummaries->keys())
            ->get(['id', 'name_ar', 'name_en'])
            ->keyBy('id');
        $courseStats = $courseCoinSummaries
            ->filter(fn (array $summary, int $courseId): bool => $courseNames->has($courseId))
            ->map(function (array $summary, int $courseId) use ($courseNames): array {
                $course = $courseNames->get($courseId);

                return [
                    'name' => (string) ($course?->name_ar ?: $course?->name_en),
                    'total_buy_count' => (int) $summary['total_buy_count'],
                    'paid_coins' => (int) $summary['paid_coins'],
                    'reward_coins' => (int) $summary['reward_coins'],
                    'current_month_buy_count' => (int) $summary['current_period_buy_count'],
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
            'platformStats'
        ));
    }
}
