<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductEvent;
use Illuminate\Support\Collection;
use App\Support\BusinessClock;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ProductAnalyticsService
{
    /** @return array<string, mixed> */
    public function overview(?int $courseId = null, ?ReportPeriod $period = null): array
    {
        $period ??= ReportPeriod::fromKey('30d');
        $scope = $this->eventScope($courseId, $period);
        $quality = $this->quality($courseId, $period);
        $previousPeriod = $period->previous();
        $previousQuality = $previousPeriod ? $this->quality($courseId, $previousPeriod) : null;
        $ai = app(AiUsageReportService::class)->summary($period, $courseId);
        $previousAi = $previousPeriod
            ? app(AiUsageReportService::class)->summary($previousPeriod, $courseId)
            : null;
        $previousFunnel = $previousPeriod
            ? collect($this->funnel($courseId, $previousPeriod)['steps'])->keyBy('event')
            : collect();
        $funnel = collect($this->funnel($courseId, $period)['steps'])->map(
            fn (array $step): array => $step + [
                'change' => ReportPeriod::compare($step['total'], $previousFunnel->get($step['event'])['total'] ?? null),
            ]
        )->all();

        $attribution = (clone $scope)
            ->whereIn('event_name', ['course_opened', 'purchase_started', 'purchase_completed'])
            ->selectRaw("source, COALESCE(campaign_key, '') as campaign, event_name, COUNT(*) as total, COUNT(DISTINCT actor_key) as actors")
            ->groupBy('source', 'campaign_key', 'event_name')
            ->orderByDesc('total')
            ->limit(100)
            ->get()
            ->map(fn ($row): array => [
                'source' => (string) $row->source,
                'campaign' => (string) $row->campaign,
                'event' => (string) $row->event_name,
                'total' => (int) $row->total,
                'actors' => (int) $row->actors,
            ]);

        return [
            'period' => $period,
            'course_id' => $courseId,
            'funnel' => $funnel,
            'lesson_drop_off' => $this->lessonDropOff($courseId, $period),
            'cohorts' => $this->acquisitionCohorts($period, $courseId),
            'attribution' => $attribution,
            'quality' => $quality,
            'ai' => $ai,
            'changes' => [
                'actors' => ReportPeriod::compare($quality['actors'], $previousQuality['actors'] ?? null),
                'sessions' => ReportPeriod::compare($quality['sessions'], $previousQuality['sessions'] ?? null),
                'events' => ReportPeriod::compare($quality['events'], $previousQuality['events'] ?? null),
                'cost_usd' => ReportPeriod::compare(
                    $ai['cost_complete'] ? $ai['cost_usd'] : null,
                    ($previousAi['cost_complete'] ?? false) ? $previousAi['cost_usd'] : null
                ),
            ],
        ];
    }

    public function funnel(?int $courseId = null, ?ReportPeriod $period = null): array
    {
        $period ??= ReportPeriod::fromKey('30d');
        $events = [
            'course_opened', 'sample_started', 'sample_completed',
            'paywall_viewed', 'earn_tasks_opened', 'purchase_started', 'purchase_completed',
            'project_submitted', 'project_passed', 'certificate_issued',
        ];

        $query = $this->eventScope($courseId, $period)
            ->whereIn('event_name', $events);

        $counts = $query->selectRaw('event_name, COUNT(*) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name');

        $uniqueActors = $this->eventScope($courseId, $period)
            ->whereNotNull('actor_key')
            ->selectRaw('event_name, COUNT(DISTINCT actor_key) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name');

        return [
            'period' => $period,
            'course_id' => $courseId,
            'steps' => collect($events)->map(function (string $event) use ($counts, $uniqueActors) {
                return [
                    'event' => $event,
                    'total' => (int) ($counts[$event] ?? 0),
                    'unique_actors' => (int) ($uniqueActors[$event] ?? 0),
                ];
            })->values()->all(),
        ];
    }

    public function lessonDropOff(?int $courseId = null, ?ReportPeriod $period = null): Collection
    {
        return $this->eventScope($courseId, $period ?? ReportPeriod::fromKey('30d'))
            ->whereIn('event_name', ['lesson_started', 'lesson_completed'])
            ->whereNotNull('lesson_id')
            ->selectRaw("lesson_id, SUM(CASE WHEN event_name = 'lesson_started' THEN 1 ELSE 0 END) starts, SUM(CASE WHEN event_name = 'lesson_completed' THEN 1 ELSE 0 END) completions")
            ->groupBy('lesson_id')
            ->orderByDesc('starts')
            ->limit(100)
            ->get()
            ->map(function ($row) {
                $starts = (int) $row->starts;
                $completions = (int) $row->completions;
                return [
                    'lesson_id' => (int) $row->lesson_id,
                    'starts' => $starts,
                    'completions' => $completions,
                    'completion_rate' => $starts > 0 ? round(($completions / $starts) * 100, 1) : 0.0,
                ];
            });
    }

    /** @return Collection<int, array{date:string,actors:int}> */
    private function acquisitionCohorts(ReportPeriod $period, ?int $courseId): Collection
    {
        $firstSeen = ProductEvent::query()
            ->whereNotNull('actor_key')
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId))
            ->selectRaw('actor_key, MIN(occurred_at) as first_seen')
            ->groupBy('actor_key');

        return $period->apply(DB::query()->fromSub($firstSeen, 'actor_first_seen'), 'first_seen')
            ->orderBy('first_seen')
            ->get()
            ->groupBy(fn ($row): string => BusinessClock::format($row->first_seen, 'Y-m-d'))
            ->map(fn (Collection $actors, string $date): array => [
                'date' => $date,
                'actors' => $actors->count(),
            ])
            ->values();
    }

    private function eventScope(?int $courseId, ReportPeriod $period): Builder
    {
        return $period->apply(ProductEvent::query(), 'occurred_at')
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId));
    }

    private function quality(?int $courseId, ReportPeriod $period): array
    {
        $totals = $this->eventScope($courseId, $period)->selectRaw(
            'COUNT(*) as events, COUNT(DISTINCT actor_key) as actors, '
            .'COUNT(DISTINCT session_key) as sessions, '
            .'SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) as anonymous_events, '
            .'SUM(CASE WHEN campaign_key IS NOT NULL THEN 1 ELSE 0 END) as campaign_events, '
            .'MAX(received_at) as last_received_at'
        )->first();
        return [
            'events' => (int) ($totals?->events ?? 0),
            'actors' => (int) ($totals?->actors ?? 0),
            'sessions' => (int) ($totals?->sessions ?? 0),
            'anonymous_events' => (int) ($totals?->anonymous_events ?? 0),
            'campaign_events' => (int) ($totals?->campaign_events ?? 0),
            'last_received_at' => $totals?->last_received_at,
        ];
    }
}
