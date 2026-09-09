<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\OperatingCostPool;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Confirmed AI charges and course-specific invoices are different evidence. */
final class CourseCostReportService
{
    public const OPENROUTER_SERVICE = 'openrouter';

    public function __construct(
        private readonly AiUsageReportService $usage,
        private readonly ProviderInvoiceReportService $invoices,
    ) {
    }

    public static function aiFeatureLabels(): array
    {
        return ['course_chat' => 'شات الكورس', AiUsageEvent::FEATURE_PROJECT_REVIEW => 'مراجعة المشروع',
            'project_feedback' => 'تقرير المشروع', 'project_followup' => 'متابعة المشروع'];
    }

    public static function serviceLabels(): array
    {
        return [self::OPENROUTER_SERVICE => 'OpenRouter'] + OperatingCostPool::SERVICES;
    }

    public function forCourse(Course $course, Collection $userIds, ?ReportPeriod $period = null): array
    {
        $period ??= ReportPeriod::fromKey();
        $userIds = $userIds->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $summary = $this->usage->summary($period, (int) $course->id);
        $byUser = $this->usage->byUser($period, (int) $course->id, $userIds);
        $features = $this->usage->byFeature($period, (int) $course->id, $userIds)->groupBy('user_id');
        $playback = $this->playbackMinutes((int) $course->id, $period);
        $unknownServices = array_fill_keys(array_keys(self::serviceLabels()), null);
        $rows = $userIds->mapWithKeys(function (int $id) use ($byUser, $features, $playback, $unknownServices): array {
            $usage = $byUser->get($id);
            $aiCostEgp = $usage === null ? 0.0 : $usage['cost_egp'];
            $costByService = $unknownServices;
            $costByService[self::OPENROUTER_SERVICE] = $aiCostEgp;

            return [$id => [
                'ai_requests' => $usage['completed_requests'] ?? 0,
                'ai_failed_requests' => $usage['failed_requests'] ?? 0,
                'ai_unanswered_requests' => $usage['unanswered_requests'] ?? 0,
                'ai_tokens' => $usage['tokens'] ?? 0,
                'ai_estimated_requests' => $usage['estimated_cost_requests'] ?? 0,
                'ai_cost_complete' => $usage['cost_complete'] ?? true,
                'ai_measurement_available' => true,
                'ai_cost_usd' => $usage['cost_usd'] ?? 0.0,
                'ai_cost_egp' => $aiCostEgp,
                'ai_by_feature' => $features->get($id, collect())->mapWithKeys(fn (array $feature): array => [
                    $feature['feature'] => ['delivered_requests' => $feature['completed_requests'],
                        'unanswered_requests' => $feature['unanswered_requests'], 'cost_usd' => $feature['cost_usd'],
                        'cost_complete' => $feature['cost_complete'],
                        'estimated_cost_requests' => $feature['estimated_cost_requests']],
                ])->all(),
                'playback_minutes' => round((float) $playback->get($id, 0), 2),
                'playback_gb_estimated' => 0.0,
                // Shared invoices cannot substantiate an individual student's bill.
                'service_cost_complete' => false, 'service_cost_actual_egp' => null,
                'actual_cost_by_service_egp' => $costByService,
                // Keep legacy consumers explicit about unknown costs, never estimates.
                'allocated_operating_cost_egp' => null, 'estimated_operating_cost_egp' => null,
                'service_cost_estimate_complete' => false, 'service_cost_with_estimates_egp' => null,
                'cost_with_estimates_by_service_egp' => $unknownServices,
            ]];
        });

        $invoices = $this->invoices->summary($period, (int) $course->id);
        $services = $invoices['services']->map(fn (array $invoice): array => [
            'key' => $invoice['key'], 'label' => $invoice['label'],
            'actual_egp' => $invoice['actual_egp'], 'actual_complete' => $invoice['actual_egp'] !== null,
            'with_estimates_egp' => null, 'estimate_complete' => false,
        ]);
        $services->prepend(['key' => self::OPENROUTER_SERVICE, 'label' => 'OpenRouter',
            'actual_egp' => $summary['cost_egp'], 'actual_complete' => $summary['cost_egp'] !== null,
            'with_estimates_egp' => null, 'estimate_complete' => false]);
        $complete = !$invoices['shared_costs'] && $invoices['missing_fx'] === 0
            && $summary['cost_egp'] !== null
            && $services->whereIn('key', ['bunny_delivery', 'bunny_storage', 'infrastructure'])
                ->every(fn (array $service): bool => $service['actual_complete']);

        return [
            'users' => $rows,
            'ai_requests' => $summary['completed_requests'],
            'ai_failed_requests' => $summary['failed_requests'],
            'ai_unanswered_requests' => $summary['unanswered_requests'],
            'ai_tokens' => $summary['tokens'],
            'ai_cost_complete' => $summary['cost_complete'],
            'ai_pending_cost_requests' => $summary['estimated_cost_requests'],
            'ai_measurement_available' => true, 'ai_cost_usd' => $summary['cost_usd'],
            'service_cost_complete' => $complete, 'complete' => $complete,
            'service_cost_actual_egp' => $complete
                ? round($summary['cost_egp'] + $invoices['known_total_egp'], 4) : null,
            'service_cost_with_estimates_egp' => null, 'estimate_complete' => false,
            'openrouter_usd_to_egp_rate' => null,
            'playback_minutes' => round((float) $playback->sum(), 2), 'playback_gb_estimated' => 0.0,
            'service_breakdown' => $services,
            'unallocated_pools' => array_values(array_filter([
                'تكلفة OpenRouter من المزود بالدولار والتحويل للجنيه يتطلب سعرًا مسجلًا وقت الاستهلاك',
                'فواتير الخدمات حسب نهاية فترة الفاتورة ولا توزع تقديريًا على الأيام أو الطلاب',
                $invoices['shared_costs'] ? 'توجد فواتير مشتركة للمنصة لا يمكن نسبتها بدقة لهذا الكورس' : null,
                !$complete ? 'إجمالي تكلفة التشغيل والهامش غير مكتملين حتى تكتمل فواتير الخدمات المنسوبة للكورس' : null,
            ])),
        ];
    }

    private function playbackMinutes(int $courseId, ReportPeriod $period): Collection
    {
        $query = $period->apply(DB::table('playback_sessions as ps')
            ->join('course_sections as cs', 'cs.id', '=', 'ps.course_section_id')
            ->where('cs.course_id', $courseId), 'ps.started_at')
            ->select(['ps.user_id', 'ps.started_playing_at', 'ps.started_at', 'ps.ended_at',
                'ps.last_heartbeat_at', 'ps.duration_seconds', 'ps.buffer_duration_ms']);
        $minutes = collect();
        foreach ($query->cursor() as $session) {
            $start = $session->started_playing_at ?: $session->started_at;
            $end = $session->ended_at ?: $session->last_heartbeat_at;
            $seconds = $start && $end
                ? max(0, min(21600, CarbonImmutable::parse($start)->diffInSeconds(CarbonImmutable::parse($end)))) : 0;
            if ((int) $session->duration_seconds > 0) {
                $seconds = min($seconds, (int) $session->duration_seconds);
            }
            $seconds = max(0, $seconds - (int) floor((int) $session->buffer_duration_ms / 1000));
            $id = (int) $session->user_id;
            $minutes->put($id, $minutes->get($id, 0.0) + $seconds / 60);
        }

        return $minutes;
    }
}
