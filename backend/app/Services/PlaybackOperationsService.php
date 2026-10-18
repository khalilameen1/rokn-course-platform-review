<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlaybackSession;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Privacy-safe read model for video operations, and the operator's one
 * action on it: ending a stale session.
 *
 * This service deliberately never selects a learner identifier, diagnostics,
 * signed source URL, IP address, or device identifier. Operators need failure
 * patterns and anonymous session state, not student identity.
 */
final class PlaybackOperationsService
{
    public function __construct(private PlaybackMetricsService $metrics) {}

    /** @return array<string, mixed> */
    public function snapshot(int $recentLimit = 30): array
    {
        $empty = $this->emptySnapshot();
        if (! $this->schemaIsReady()) {
            return $empty;
        }

        try {
            $now = now();
            $staleCutoff = $now->copy()->subMinutes($this->staleAfterMinutes());
            $periodStart = $now->copy()->subDays($this->metricsDays());

            $summary = [
                // This uses the same cut-off as playback:maintain so every
                // open session is classified as either active or stale.
                'active' => $this->openSessionsSince($staleCutoff)->count(),
                'stale' => $this->openSessionsBefore($staleCutoff)->count(),
                'completed' => DB::table('playback_sessions')
                    ->whereNotNull('ended_at')
                    ->where('ended_at', '>=', $periodStart)
                    ->where('end_reason', 'completed')
                    ->count(),
                'errors' => DB::table('playback_sessions')
                    ->where('started_at', '>=', $periodStart)
                    ->whereNotNull('last_error_code')
                    ->where('last_error_code', '<>', '')
                    ->count(),
                'recovery_sessions' => DB::table('playback_sessions')
                    ->where('started_at', '>=', $periodStart)
                    ->where('recovery_count', '>', 0)
                    ->count(),
                'sessions' => DB::table('playback_sessions')
                    ->where('started_at', '>=', $periodStart)
                    ->count(),
            ];

            return [
                'available' => true,
                'summary' => $summary,
                'top_failing_lessons' => $this->topFailingLessons($periodStart),
                'quality_mix' => $this->qualityMix($periodStart),
                'latest_errors' => $this->latestErrors(20),
                'recent_sessions' => $this->recentSessions(max(1, min(100, $recentLimit))),
                'rollup' => $this->rollupSummary(),
                'period_days' => $this->metricsDays(),
                'stale_after_minutes' => $this->staleAfterMinutes(),
                'generated_at' => $now,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $empty;
        }
    }

    /**
     * End one session that stopped reporting, recorded like a player stop so
     * it lands in the same rollup.
     *
     * @return 'ended'|'already_ended'|'still_active'|'missing'
     */
    public function terminateStale(string $sessionId): string
    {
        return DB::transaction(function () use ($sessionId): string {
            /** @var PlaybackSession|null $locked */
            $locked = PlaybackSession::query()
                ->lockForUpdate()
                ->find($sessionId);
            if (! $locked) {
                return 'missing';
            }
            if ($locked->ended_at) {
                return 'already_ended';
            }
            if (! $this->isStale($locked)) {
                return 'still_active';
            }

            $locked->forceFill([
                'ended_at' => now(),
                'event_type' => 'stop',
                'end_reason' => 'admin_stale_termination',
            ])->save();

            return 'ended';
        }, 3);
    }

    public function isStale(object $session, ?Carbon $at = null): bool
    {
        if (! empty($session->ended_at)) {
            return false;
        }

        $lastSeen = $session->last_heartbeat_at ?: $session->started_at;
        if (! $lastSeen) {
            return true;
        }

        $seenAt = $lastSeen instanceof Carbon ? $lastSeen : Carbon::parse($lastSeen);

        return $seenAt->lte(($at ?: now())->copy()->subMinutes($this->staleAfterMinutes()));
    }

    private function schemaIsReady(): bool
    {
        if (! Schema::hasTable('playback_sessions')) {
            return false;
        }

        foreach ([
            'id', 'lesson_id', 'started_at', 'last_heartbeat_at', 'ended_at',
            'end_reason', 'event_type', 'effective_quality', 'recovery_count',
            'last_error_code', 'last_position_seconds', 'duration_seconds',
            'source_protocol',
        ] as $column) {
            if (! Schema::hasColumn('playback_sessions', $column)) {
                return false;
            }
        }

        return true;
    }

    private function openSessionsSince(Carbon $cutoff): Builder
    {
        return DB::table('playback_sessions')
            ->whereNull('ended_at')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('last_heartbeat_at', '>', $cutoff)
                    ->orWhere(function (Builder $fresh) use ($cutoff): void {
                        $fresh->whereNull('last_heartbeat_at')
                            ->where('started_at', '>', $cutoff);
                    });
            });
    }

    private function openSessionsBefore(Carbon $cutoff): Builder
    {
        return DB::table('playback_sessions')
            ->whereNull('ended_at')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('last_heartbeat_at', '<=', $cutoff)
                    ->orWhere(function (Builder $neverReported) use ($cutoff): void {
                        $neverReported->whereNull('last_heartbeat_at')
                            ->where('started_at', '<=', $cutoff);
                    });
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function topFailingLessons(Carbon $periodStart): Collection
    {
        $rows = DB::table('playback_sessions')
            ->select('lesson_id', DB::raw('COUNT(*) as failures'))
            ->where('started_at', '>=', $periodStart)
            ->whereNotNull('last_error_code')
            ->where('last_error_code', '<>', '')
            ->groupBy('lesson_id')
            ->orderByDesc('failures')
            ->limit(10)
            ->get();

        return $this->attachLessonLabels($rows);
    }

    /** @return Collection<int, array{quality:string,sessions:int}> */
    private function qualityMix(Carbon $periodStart): Collection
    {
        return DB::table('playback_sessions')
            ->select('effective_quality', DB::raw('COUNT(*) as sessions'))
            ->where('started_at', '>=', $periodStart)
            ->groupBy('effective_quality')
            ->orderByDesc('sessions')
            ->get()
            ->map(fn (object $row): array => [
                'quality' => $this->safeDimension($row->effective_quality, 'unknown'),
                'sessions' => (int) $row->sessions,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function latestErrors(int $limit): Collection
    {
        $rows = DB::table('playback_sessions')
            ->select([
                'lesson_id', 'last_error_code', 'recovery_count',
                'effective_quality', 'event_type', 'started_at',
                'last_heartbeat_at', 'ended_at',
            ])
            ->whereNotNull('last_error_code')
            ->where('last_error_code', '<>', '')
            ->orderByDesc('last_heartbeat_at')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        return $this->attachLessonLabels($rows);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recentSessions(int $limit): Collection
    {
        $rows = DB::table('playback_sessions')
            ->select([
                'id', 'lesson_id', 'event_type', 'end_reason',
                'last_position_seconds', 'duration_seconds', 'source_protocol',
                'effective_quality', 'recovery_count', 'last_error_code',
                'started_at', 'last_heartbeat_at', 'ended_at',
            ])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(function (object $row): object {
                $row->is_stale = $this->isStale($row);
                $row->session_reference = substr(hash('sha256', (string) $row->id), 0, 10);
                // Kept only to address the mutation route; it is never shown.
                $row->session_key = (string) $row->id;

                return $row;
            });

        return $this->attachLessonLabels($rows);
    }

    /**
     * Adds editorial labels without selecting a learner or delivery URL.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function attachLessonLabels(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $labels = collect();
        if (Schema::hasTable('lessons')) {
            $lessonColumns = ['id'];
            foreach (['title_ar', 'title_en', 'title', 'list_id'] as $column) {
                if (Schema::hasColumn('lessons', $column)) {
                    $lessonColumns[] = $column;
                }
            }
            $lessons = DB::table('lessons')
                ->select($lessonColumns)
                ->whereIn('id', $rows->pluck('lesson_id')->filter()->unique()->all())
                ->get()
                ->keyBy('id');

            $courseLabels = collect();
            if (
                in_array('list_id', $lessonColumns, true)
                && Schema::hasTable('courses')
                && Schema::hasColumn('courses', 'id')
            ) {
                $courseColumns = ['id'];
                foreach (['name_ar', 'name_en'] as $column) {
                    if (Schema::hasColumn('courses', $column)) {
                        $courseColumns[] = $column;
                    }
                }
                $courseLabels = DB::table('courses')
                    ->select($courseColumns)
                    ->whereIn('id', $lessons->pluck('list_id')->filter()->unique()->all())
                    ->get()
                    ->keyBy('id');
            }

            foreach ($lessons as $lesson) {
                $course = isset($lesson->list_id) ? $courseLabels->get($lesson->list_id) : null;
                $labels->put((int) $lesson->id, [
                    'lesson_title' => $this->firstFilled($lesson, ['title_ar', 'title_en', 'title']) ?: 'مقطع بلا عنوان',
                    'course_title' => $course
                        ? ($this->firstFilled($course, ['name_ar', 'name_en']) ?: 'كورس غير معنون')
                        : '—',
                ]);
            }
        }

        return $rows->map(function (object $row) use ($labels): array {
            $data = (array) $row;
            unset($data['id']);
            if (array_key_exists('last_error_code', $data)) {
                $data['last_error_code'] = $this->safeErrorCode($data['last_error_code']);
            }
            if (array_key_exists('effective_quality', $data)) {
                $data['effective_quality'] = $this->safeDimension($data['effective_quality'], 'unknown');
            }

            return array_merge(
                $data,
                $labels->get((int) ($row->lesson_id ?? 0), [
                    'lesson_title' => 'مقطع محذوف أو غير متاح',
                    'course_title' => '—',
                ])
            );
        })->values();
    }

    private function safeErrorCode(mixed $value): ?string
    {
        $code = trim((string) $value);
        if ($code === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $code) === 1
            ? $code
            : 'client_error';
    }

    private function safeDimension(mixed $value, string $fallback): string
    {
        $dimension = trim((string) $value);
        if ($dimension === '') {
            return $fallback;
        }

        return preg_match('/^[A-Za-z0-9._:+-]{1,24}$/', $dimension) === 1
            ? $dimension
            : $fallback;
    }

    /** @param array<int, string> $fields */
    private function firstFilled(object $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string) ($row->{$field} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function rollupSummary(): ?array
    {
        if (! Schema::hasTable('playback_metric_rollups')) {
            return null;
        }

        try {
            return $this->metrics->summary($this->metricsDays() * 24);
        } catch (Throwable $exception) {
            // Live diagnostics remain useful while rollups are catching up.
            report($exception);

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(): array
    {
        return [
            'available' => false,
            'summary' => [
                'active' => 0,
                'stale' => 0,
                'completed' => 0,
                'errors' => 0,
                'recovery_sessions' => 0,
                'sessions' => 0,
            ],
            'top_failing_lessons' => collect(),
            'quality_mix' => collect(),
            'latest_errors' => collect(),
            'recent_sessions' => collect(),
            'rollup' => null,
            'period_days' => $this->metricsDays(),
            'stale_after_minutes' => $this->staleAfterMinutes(),
            'generated_at' => now(),
        ];
    }

    private function staleAfterMinutes(): int
    {
        return max(2, min(120, (int) config(
            'playback.stale_after_minutes',
            config('operations.playback_stale_after_minutes', 10)
        )));
    }

    private function metricsDays(): int
    {
        return max(1, min(30, (int) config('operations.playback_metrics_days', 7)));
    }
}
