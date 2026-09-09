<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Platform-wide unit economics assembled from the same auditable course ledger. */
final readonly class PlatformCommercialReportService
{
    public function __construct(
        private CourseCommercialReportService $courses,
        private AiUsageReportService $ai,
        private ProviderInvoiceReportService $invoices,
    )
    {
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function report(array $filters = []): array
    {
        $period = ReportPeriod::fromKey((string) ($filters['period'] ?? 'all'));
        $current = $this->build($filters, $period);
        $previous = $period->previous();
        $prior = $previous ? $this->build($filters, $previous) : null;
        $current['comparisons'] = collect(['gross_egp', 'net_egp', 'ai_cost_usd', 'service_cost_egp', 'margin_egp'])
            ->mapWithKeys(function (string $key) use ($current, $prior): array {
                $value = $current[$key];
                $previousValue = $prior[$key] ?? null;
                if ($key === 'ai_cost_usd') {
                    if (!$current['ai_cost_complete']) $value = null;
                    if (!($prior['ai_cost_complete'] ?? false)) $previousValue = null;
                }
                if (in_array($key, ['gross_egp', 'net_egp', 'margin_egp'], true)) {
                    if (!$current['coin_allocation_complete']) $value = null;
                    if (!($prior['coin_allocation_complete'] ?? false)) $previousValue = null;
                }
                return [$key => ReportPeriod::compare($value, $previousValue)];
            })->all();
        $current['plan_breakdown'] = $current['plan_breakdown']->map(function (array $plan, string $code) use ($prior): array {
            $previous = $prior === null ? [] : ($prior['plan_breakdown']->get($code)['period_metrics'] ?? []);
            $plan['comparisons'] = collect($plan['period_metrics'])->mapWithKeys(fn ($value, string $key): array => [
                $key => ReportPeriod::compare($value, $previous[$key] ?? null),
            ])->all();
            return $plan;
        });
        $priorCourses = $prior === null ? collect() : $prior['course_breakdown']->keyBy('course_id');
        $current['course_breakdown'] = $current['course_breakdown']->map(function (array $course) use ($priorCourses): array {
            $previous = $priorCourses->get($course['course_id']);
            $course['comparisons'] = ['cash_net_egp' => ReportPeriod::compare(
                $course['coin_allocation_complete'] ? $course['net_egp'] : null,
                ($previous['coin_allocation_complete'] ?? false) ? $previous['net_egp'] : null,
            )];
            return $course;
        });

        return $current;
    }

    private function build(array $filters, ReportPeriod $period): array
    {
        // Retiring a course removes it from the catalogue, not from lifetime
        // revenue and cost history.
        $courseQuery = Course::query()->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotIn('courses.id', CourseAuthoringRevision::query()->select('revision_course_id'))
            ->where(function ($query): void {
                $query->whereHas('enrollments')->orWhereHas('orders')
                    ->orWhereExists(fn ($events) => $events->selectRaw('1')->from('ai_usage_events')->whereColumn('course_id', 'courses.id'))
                    ->orWhereExists(fn ($invoices) => $invoices->selectRaw('1')->from('operating_cost_pools')
                        ->whereColumn('course_id', 'courses.id')->whereNull('deleted_at')->where('is_final', true));
            });
        $courseModels = $courseQuery
            ->when($filters['course_id'] ?? null, fn ($query, $courseId) =>
                $query->whereKey((int) $courseId)
            )
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'deleted_at']);

        $rawRows = collect();
        $warnings = collect();
        $courseReports = collect();
        foreach ($courseModels as $course) {
            $courseReport = $this->courses->forCourse($course, $period, false);
            $courseReports->put((int) $course->id, $courseReport);
            $warnings = $warnings->concat($courseReport['cost_warnings']);
            $rawRows = $rawRows->concat(
                $courseReport['rows']->map(function (array $row) use ($course): array {
                    $row['course_id'] = (int) $course->id;
                    $row['course_name'] = (string) $course->title;
                    $row['course_archived'] = $course->trashed();

                    return $row;
                })
            );
        }

        $filterOptions = [
            'plans' => $rawRows->map(fn (array $row): array => [
                'code' => (string) $row['plan_code'],
                'name' => (string) $row['plan_name'],
            ])->unique('code')->values(),
            'sources' => $rawRows->map(fn (array $row): array => [
                'code' => (string) $row['source'],
                'name' => (string) $row['source_label'],
            ])->unique('code')->values(),
        ];

        $rows = $this->filterRows($rawRows, $filters);
        $notificationUsage = $this->notificationUsage(
            $rows->pluck('enrollment.user_id')->map(fn ($id): int => (int) $id), $period
        );
        $summary = $this->courses->groupSummary($rows);
        $cohort = collect(['plan', 'source', 'q'])->contains(fn (string $key): bool => trim((string) ($filters[$key] ?? '')) !== '');
        $courseId = !empty($filters['course_id']) ? (int) $filters['course_id'] : null;
        $invoiceReport = $cohort ? null : $this->invoices->summary($period, $courseId);
        $ai = $cohort ? null : $this->ai->summary($period, $courseId);
        if ($ai !== null) {
            $summary = array_replace($summary, [
                'ai_requests' => $ai['completed_requests'], 'ai_failed_requests' => $ai['failed_requests'],
                'ai_unanswered_requests' => $ai['unanswered_requests'], 'ai_tokens' => $ai['tokens'],
                'ai_cost_usd' => $ai['cost_usd'], 'ai_cost_complete' => $ai['cost_complete'],
                'ai_estimated_requests' => $ai['estimated_cost_requests'],
            ]);
            $complete = !$invoiceReport['shared_costs'] && $invoiceReport['missing_fx'] === 0
                && $ai['cost_egp'] !== null && collect(['bunny_delivery', 'bunny_storage', 'infrastructure'])
                    ->every(fn (string $key): bool => $invoiceReport['services']->firstWhere('key', $key)['actual_egp'] !== null);
            $summary['service_cost_complete'] = $complete;
            $summary['service_cost_egp'] = $complete ? round($ai['cost_egp'] + $invoiceReport['known_total_egp'], 4) : null;
        } else {
            $summary['service_cost_complete'] = false;
            $summary['service_cost_egp'] = null;
        }
        $summary['margin_egp'] = $summary['net_egp'] !== null && $summary['service_cost_egp'] !== null
            ? round($summary['net_egp'] - $summary['service_cost_egp'], 4) : null;
        $summary['cost_to_net_revenue_percentage'] = $summary['net_egp'] > 0 && $summary['service_cost_egp'] !== null
            ? round($summary['service_cost_egp'] / $summary['net_egp'] * 100, 2) : null;
        $summary['contribution_margin_percentage'] = $summary['net_egp'] > 0 && $summary['margin_egp'] !== null
            ? round($summary['margin_egp'] / $summary['net_egp'] * 100, 2) : null;
        $unsuccessfulRequests = $summary['ai_failed_requests'] + $summary['ai_unanswered_requests'];
        $aiAttempts = $summary['ai_requests'] + $unsuccessfulRequests;
        $summary['ai_failure_rate_percentage'] = $aiAttempts > 0
            ? round($unsuccessfulRequests / $aiAttempts * 100, 2) : null;
        $studentRows = $rows
            ->groupBy(fn (array $row): int => (int) $row['enrollment']->user_id)
            ->map(function (Collection $userRows, int $userId) use ($notificationUsage): array {
                $first = $userRows->first();
                $push = $notificationUsage->get($userId, $this->emptyNotificationUsage());

                return $this->courses->groupSummary($userRows) + [
                    'user' => $first['user'],
                    'active_courses' => $userRows->where('is_active', true)->count(),
                    'courses' => $userRows->pluck('course_name')->filter()->unique()->values(),
                    'plans' => $userRows->pluck('plan_name')->filter()->unique()->values(),
                    'sources' => $userRows->pluck('source_label')->filter()->unique()->values(),
                    'payment_channels' => $userRows
                        ->flatMap(fn (array $row): array => collect($row['cash_channels'] ?? [])
                            ->pluck('label')
                            ->all())
                        ->filter()
                        ->unique()
                        ->values(),
                    'actual_cost_by_service_egp' => $this->sumServiceMaps(
                        $userRows,
                        'actual_cost_by_service_egp'
                    ),
                    'cost_with_estimates_by_service_egp' => $this->sumServiceMaps(
                        $userRows,
                        'cost_with_estimates_by_service_egp'
                    ),
                    'in_app_notifications' => (int) $push['in_app_notifications'],
                    'read_notifications' => (int) $push['read_notifications'],
                    'push_attempts' => (int) $push['push_attempts'],
                    'push_provider_accepted' => (int) $push['push_provider_accepted'],
                    'push_provider_acceptance_rate_percentage' => (int) $push['push_attempts'] > 0
                        ? round(((int) $push['push_provider_accepted'] / (int) $push['push_attempts']) * 100, 2)
                        : null,
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) ($row['service_cost_egp'] ?? -1))
            ->values();
        $uniqueStudents = $studentRows->count();
        $summary['average_net_per_student_egp'] = $uniqueStudents > 0
            && $summary['net_egp'] !== null
                ? round((float) $summary['net_egp'] / $uniqueStudents, 2)
                : null;
        // Recorded platform invoices are not evidence of individual learner costs.
        $summary['average_cost_per_student_egp'] = null;
        // Failed requests can carry provider charges without completed token totals.
        $summary['ai_cost_per_1000_tokens_usd'] = $summary['ai_cost_complete']
            && $summary['ai_failed_requests'] === 0 && (int) $summary['ai_tokens'] > 0
            ? round(((float) $summary['ai_cost_usd'] / (int) $summary['ai_tokens']) * 1000, 6)
            : null;
        $notificationTotals = [
            'in_app_notifications' => (int) $studentRows->sum('in_app_notifications'),
            'read_notifications' => (int) $studentRows->sum('read_notifications'),
            'push_attempts' => (int) $studentRows->sum('push_attempts'),
            'push_provider_accepted' => (int) $studentRows->sum('push_provider_accepted'),
        ];
        $summary += $notificationTotals;
        $summary['push_provider_acceptance_rate_percentage'] = $notificationTotals['push_attempts'] > 0
            ? round(($notificationTotals['push_provider_accepted'] / $notificationTotals['push_attempts']) * 100, 2)
            : null;
        $notificationTotals['push_provider_acceptance_rate_percentage']
            = $summary['push_provider_acceptance_rate_percentage'];
        $services = $this->serviceBreakdown($rows, $notificationTotals);
        if ($invoiceReport !== null) {
            $services = $services->map(function (array $service) use ($invoiceReport, $ai): array {
                $service['actual_egp'] = $service['key'] === 'openrouter' ? $ai['cost_egp']
                    : ($invoiceReport['services']->firstWhere('key', $service['key'])['actual_egp'] ?? null);
                if ($service['key'] === 'openrouter') {
                    $service = array_replace($service, ['requests' => $ai['completed_requests'],
                        'failed_requests' => $ai['failed_requests'], 'units' => $ai['tokens'], 'cost_usd' => $ai['cost_usd']]);
                }
                return $service;
            });
        }
        $serviceBreakdown = $services->map(function (array $service) use (
            $summary
        ): array {
            $service['share_of_actual_cost_percentage'] = $summary['service_cost_egp'] !== null
                && (float) $summary['service_cost_egp'] > 0
                && $service['actual_egp'] !== null
                    ? round(((float) $service['actual_egp'] / (float) $summary['service_cost_egp']) * 100, 2)
                    : null;

            return $service;
        });
        // Historical tier metrics already use accepted order contracts in the
        // course report. Never redistribute them using today's enrollment tier.
        $planBreakdown = $courseReports->flatMap(fn (array $report) => $report['plan_breakdown']->values())
            ->groupBy('plan_code')->map(function (Collection $plans, string $code) use ($cohort, $rows): array {
                $metrics = collect(['cash_gross_egp', 'cash_net_egp', 'ai_requests', 'ai_tokens', 'ai_cost_usd'])
                    ->mapWithKeys(fn (string $key): array => [$key => $cohort || $plans->contains(
                        fn (array $plan): bool => ($plan['period_metrics'][$key] ?? null) === null
                    ) ? null : $plans->sum(fn (array $plan) => $plan['period_metrics'][$key])])->all();
                return array_replace($this->courses->groupSummary($rows->where('plan_code', $code)), [
                    'plan_code' => $code, 'plan_name' => $plans->first()['plan_name'], 'period_metrics' => $metrics,
                    'service_cost_egp' => null, 'service_cost_complete' => false,
                    'average_cost_per_student_egp' => null, 'margin_egp' => null,
                ]);
            });

        return $summary + [
            'period' => $period,
            'provider_invoice_report' => $invoiceReport,
            'cohort_filter' => $cohort,
            'rows' => $rows,
            'student_rows' => $studentRows,
            'unique_students' => $uniqueStudents,
            'enrollments' => $rows->count(),
            'active_enrollments' => $rows->where('is_active', true)->count(),
            'course_breakdown' => $courseModels->filter(fn (Course $course): bool => !$cohort || $rows->contains('course_id', (int) $course->id))->map(function (
                Course $course
            ) use ($courseReports, $cohort, $rows): array {
                $courseRows = $rows->where('course_id', (int) $course->id);
                $result = $this->courses->groupSummary($courseRows);
                $courseReport = $courseReports->get((int) $course->id);
                if (!$cohort) {
                    $result['service_cost_egp'] = $courseReport['service_cost_actual_egp'];
                    $result['cost_to_net_revenue_percentage'] = $result['net_egp'] > 0 && $result['service_cost_egp'] !== null
                        ? round($result['service_cost_egp'] / $result['net_egp'] * 100, 2) : null;
                }
                return $result + [
                    'course_id' => (int) $course->id,
                    'course_name' => (string) $course->title,
                    'course_archived' => $course->trashed(),
                ];
            })->values(),
            'plan_breakdown' => $planBreakdown,
            'source_breakdown' => $rows->groupBy('source_label')->map(
                fn (Collection $sourceRows): array => $this->courses->groupSummary($sourceRows)
            ),
            'service_breakdown' => $serviceBreakdown,
            'cost_warnings' => $warnings->filter()->unique()->values(),
            'filter_options' => $filterOptions,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @param array<string, mixed> $filters */
    private function filterRows(Collection $rows, array $filters): Collection
    {
        $plan = trim((string) ($filters['plan'] ?? ''));
        $source = trim((string) ($filters['source'] ?? ''));
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        return $rows
            ->when($plan !== '', fn (Collection $items) => $items->filter(
                fn (array $row): bool => (string) $row['plan_code'] === $plan
            ))
            ->when($source !== '', fn (Collection $items) => $items->where('source', $source))
            ->when($search !== '', fn (Collection $items) => $items->filter(function (
                array $row
            ) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($row['user']?->name ?? ''),
                    (string) ($row['user']?->email ?? ''),
                    (string) $row['course_name'],
                    (string) $row['plan_name'],
                ]));

                return str_contains($haystack, $search);
            }))
            ->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows @return Collection<string, float|null> */
    private function sumServiceMaps(Collection $rows, string $field): Collection
    {
        return collect(CourseCostReportService::serviceLabels())->map(function (
            string $_label,
            string $serviceKey
        ) use ($rows, $field): ?float {
            if ($rows->isEmpty() || $rows->contains(fn (array $row): bool =>
                ($row[$field][$serviceKey] ?? null) === null
            )) {
                return null;
            }

            return round((float) $rows->sum(fn (array $row): float =>
                (float) ($row[$field][$serviceKey] ?? 0)
            ), 4);
        });
    }

    /** @param Collection<int, array<string, mixed>> $rows @return Collection<int, array<string, mixed>> */
    private function serviceBreakdown(Collection $rows, array $notificationTotals): Collection
    {
        $actual = $this->sumServiceMaps($rows, 'actual_cost_by_service_egp');
        $estimated = $this->sumServiceMaps($rows, 'cost_with_estimates_by_service_egp');

        return collect(CourseCostReportService::serviceLabels())->map(function (
            string $label,
            string $serviceKey
        ) use ($actual, $estimated, $rows, $notificationTotals): array {
            $result = [
                'key' => $serviceKey,
                'label' => $label,
                'actual_egp' => $actual->get($serviceKey),
                'with_estimates_egp' => $estimated->get($serviceKey),
            ];
            if ($serviceKey === CourseCostReportService::OPENROUTER_SERVICE) {
                $result += [
                    'requests' => (int) $rows->sum('ai_requests'),
                    'failed_requests' => (int) $rows->sum('ai_failed_requests'),
                    'units' => (int) $rows->sum('ai_tokens'),
                    'unit_label' => 'توكن',
                    'cost_usd' => round((float) $rows->sum('ai_cost_usd'), 6),
                ];
            } elseif ($serviceKey === 'bunny_delivery') {
                $result += [
                    'units' => round((float) $rows->sum('playback_gb_estimated'), 4),
                    'unit_label' => 'GB مشاهدة مقدرة',
                    'minutes' => round((float) $rows->sum('playback_minutes'), 2),
                ];
            } elseif ($serviceKey === 'notifications') {
                $result += $notificationTotals;
            }

            return $result;
        })->values();
    }

    /** @param Collection<int,int> $userIds @return Collection<int,array<string,int>> */
    private function notificationUsage(Collection $userIds, ReportPeriod $period): Collection
    {
        $userIds = $userIds->filter()->unique()->values();
        if ($userIds->isEmpty()) {
            return collect();
        }

        $base = DB::table('student_notifications')
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, COUNT(*) as total')->groupBy('user_id');
        $result = $userIds->mapWithKeys(fn (int $id): array => [$id => $this->emptyNotificationUsage()]);
        foreach (['in_app_notifications' => 'created_at', 'read_notifications' => 'created_at',
            'push_attempts' => 'push_attempted_at', 'push_provider_accepted' => 'push_sent_at'] as $metric => $column) {
            $query = (clone $base)->whereNotNull($column);
            if ($metric === 'read_notifications') {
                $query->where('is_read', true);
            }
            foreach ($period->apply($query, $column)->get() as $row) {
                $result->put((int) $row->user_id, array_replace($result->get((int) $row->user_id), [$metric => (int) $row->total]));
            }
        }
        return $result;
    }

    /** @return array<string,int> */
    private function emptyNotificationUsage(): array
    {
        return [
            'in_app_notifications' => 0,
            'read_notifications' => 0,
            'push_attempts' => 0,
            'push_provider_accepted' => 0,
        ];
    }
}
