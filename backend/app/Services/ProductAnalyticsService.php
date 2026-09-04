<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductEvent;
use Illuminate\Support\Collection;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProductAnalyticsService
{
    /** @return array<string, mixed> */
    public function overview(?int $courseId = null, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $fromBusiness = BusinessClock::now()->subDays($days)->startOfDay();
        $from = $fromBusiness->utc();
        $scope = ProductEvent::query()
            ->where('occurred_at', '>=', $from)
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId));

        $totals = (clone $scope)->selectRaw(
            'COUNT(*) as events, COUNT(DISTINCT actor_key) as actors, '
            .'COUNT(DISTINCT session_key) as sessions, '
            .'SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) as anonymous_events, '
            .'SUM(CASE WHEN campaign_key IS NOT NULL THEN 1 ELSE 0 END) as campaign_events, '
            .'MAX(received_at) as last_received_at'
        )->first();

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
            'from' => $fromBusiness->toDateString(),
            'days' => $days,
            'course_id' => $courseId,
            'funnel' => $this->funnel($courseId, $days)['steps'],
            'lesson_drop_off' => $this->lessonDropOff($courseId, $days),
            'cohorts' => $this->acquisitionCohorts($from, $courseId),
            'attribution' => $attribution,
            'quality' => [
                'events' => (int) ($totals?->events ?? 0),
                'actors' => (int) ($totals?->actors ?? 0),
                'sessions' => (int) ($totals?->sessions ?? 0),
                'anonymous_events' => (int) ($totals?->anonymous_events ?? 0),
                'campaign_events' => (int) ($totals?->campaign_events ?? 0),
                'last_received_at' => $totals?->last_received_at,
            ],
            'ai' => $this->aiUsage($from, $courseId),
        ];
    }

    public function funnel(?int $courseId = null, int $days = 30): array
    {
        $fromBusiness = BusinessClock::now()
            ->subDays(max(1, min($days, 365)))
            ->startOfDay();
        $from = $fromBusiness->utc();
        $events = [
            'course_opened', 'sample_started', 'sample_completed',
            'paywall_viewed', 'earn_tasks_opened', 'purchase_started', 'purchase_completed',
            'project_submitted', 'project_passed', 'certificate_issued',
        ];

        $query = ProductEvent::query()
            ->where('occurred_at', '>=', $from)
            ->whereIn('event_name', $events);
        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $counts = $query->selectRaw('event_name, COUNT(*) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name');

        $uniqueActors = ProductEvent::query()
            ->where('occurred_at', '>=', $from)
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId))
            ->whereNotNull('actor_key')
            ->selectRaw('event_name, COUNT(DISTINCT actor_key) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name');

        return [
            'from' => $fromBusiness->toDateString(),
            'days' => $days,
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

    public function lessonDropOff(?int $courseId = null, int $days = 30): Collection
    {
        return ProductEvent::query()
            ->where('occurred_at', '>=', BusinessClock::now()->subDays(max(1, min($days, 365)))->startOfDay()->utc())
            ->whereIn('event_name', ['lesson_started', 'lesson_completed'])
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId))
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
    private function acquisitionCohorts($from, ?int $courseId): Collection
    {
        $firstSeen = ProductEvent::query()
            ->whereNotNull('actor_key')
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId))
            ->selectRaw('actor_key, MIN(occurred_at) as first_seen')
            ->groupBy('actor_key');

        return DB::query()
            ->fromSub($firstSeen, 'actor_first_seen')
            ->where('first_seen', '>=', $from)
            ->orderBy('first_seen')
            ->get()
            ->groupBy(fn ($row): string => BusinessClock::format($row->first_seen, 'Y-m-d'))
            ->map(fn (Collection $actors, string $date): array => [
                'date' => $date,
                'actors' => $actors->count(),
            ])
            ->values();
    }

    /** @return array<string, int|float|bool|null> */
    private function aiUsage($from, ?int $courseId): array
    {
        if (!Schema::hasTable('ai_usage_events')) {
            return [
                'available' => false,
                'completed_requests' => 0,
                'failed_requests' => 0,
                'tokens' => 0,
                'cost_usd' => null,
                'provider_cost_requests' => 0,
                'estimated_cost_requests' => 0,
                'cost_complete' => false,
            ];
        }

        $query = DB::table('ai_usage_events')
            ->where('created_at', '>=', $from)
            ->when($courseId, fn ($builder) => $builder->where('course_id', $courseId));
        $failed = (clone $query)->whereIn('status', ['failed', 'cancelled', 'expired'])->count();
        $completed = (clone $query)->where('status', 'completed')
            ->get(['total_tokens', 'cost_usd', 'metadata']);
        $provider = 0;
        $estimated = 0;
        foreach ($completed as $event) {
            $metadata = json_decode((string) ($event->metadata ?? '{}'), true);
            $costSource = $metadata['cost_usage_source'] ?? null;
            if ($costSource === 'provider') {
                $provider++;
            } else {
                $estimated++;
            }
        }

        return [
            'available' => true,
            'completed_requests' => $completed->count(),
            'failed_requests' => (int) $failed,
            'tokens' => (int) $completed->sum('total_tokens'),
            'cost_usd' => round((float) $completed->sum('cost_usd'), 6),
            'provider_cost_requests' => $provider,
            'estimated_cost_requests' => $estimated,
            'cost_complete' => $estimated === 0,
        ];
    }
}
