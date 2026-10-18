<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlaybackSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PlaybackMetricsService
{
    public function finalizeStaleSessions(?int $staleMinutes = null, int $limit = 2000): int
    {
        $minutes = max(2, min(120, $staleMinutes ?? (int) config('playback.stale_after_minutes', 10)));
        $limit = max(1, min(10000, $limit));
        $cutoff = now()->subMinutes($minutes);

        $stale = static fn ($query) => $query->where(function ($query) use ($cutoff): void {
            $query->where('last_heartbeat_at', '<=', $cutoff)
                ->orWhere(function ($query) use ($cutoff): void {
                    $query->whereNull('last_heartbeat_at')->where('started_at', '<=', $cutoff);
                });
        });

        $ids = $stale(PlaybackSession::query()->whereNull('ended_at'))
            ->orderBy('started_at')
            ->limit($limit)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return $stale(PlaybackSession::query()->whereIn('id', $ids)->whereNull('ended_at'))
            ->update([
                'event_type' => 'stop',
                'end_reason' => 'stale_timeout',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function rollupEndedSessions(int $limit = 2000): int
    {
        $limit = max(1, min(10000, $limit));

        return DB::transaction(function () use ($limit): int {
            /** @var Collection<int, PlaybackSession> $sessions */
            $sessions = PlaybackSession::query()
                ->whereNotNull('ended_at')
                ->whereNull('metrics_rolled_up_at')
                ->orderBy('ended_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();
            if ($sessions->isEmpty()) {
                return 0;
            }

            $groups = $sessions->groupBy(fn (PlaybackSession $session): string => json_encode(
                $this->dimensions($session),
                JSON_THROW_ON_ERROR
            ));

            foreach ($groups as $encodedDimensions => $group) {
                /** @var array<string, int|string> $dimensions */
                $dimensions = json_decode((string) $encodedDimensions, true, 512, JSON_THROW_ON_ERROR);
                $values = $this->aggregate($group);

                $existing = DB::table('playback_metric_rollups')
                    ->where($dimensions)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    DB::table('playback_metric_rollups')->where('id', $existing->id)->update([
                        'session_count' => (int) $existing->session_count + $values['session_count'],
                        'completed_count' => (int) $existing->completed_count + $values['completed_count'],
                        'error_session_count' => (int) $existing->error_session_count + $values['error_session_count'],
                        'buffering_session_count' => (int) $existing->buffering_session_count + $values['buffering_session_count'],
                        'startup_sample_count' => (int) $existing->startup_sample_count + $values['startup_sample_count'],
                        'startup_latency_total_ms' => (int) $existing->startup_latency_total_ms + $values['startup_latency_total_ms'],
                        'startup_latency_max_ms' => max((int) $existing->startup_latency_max_ms, $values['startup_latency_max_ms']),
                        'buffer_event_count' => (int) $existing->buffer_event_count + $values['buffer_event_count'],
                        'buffer_duration_total_ms' => (int) $existing->buffer_duration_total_ms + $values['buffer_duration_total_ms'],
                        'recovery_total' => (int) $existing->recovery_total + $values['recovery_total'],
                        'bitrate_sample_count' => (int) $existing->bitrate_sample_count + $values['bitrate_sample_count'],
                        'bitrate_total_kbps' => (int) $existing->bitrate_total_kbps + $values['bitrate_total_kbps'],
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('playback_metric_rollups')->insert($dimensions + $values + [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            PlaybackSession::query()->whereKey($sessions->modelKeys())->update([
                'metrics_rolled_up_at' => now(),
                'updated_at' => now(),
            ]);

            return $sessions->count();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function summary(int $hours = 24, ?int $lessonId = null): array
    {
        $maxHours = max(1, (int) config('playback.metrics_max_window_hours', 720));
        $hours = max(1, min($maxHours, $hours));
        $from = now()->subHours($hours)->startOfHour();
        $base = DB::table('playback_metric_rollups')
            ->where('bucket_start', '>=', $from)
            ->when($lessonId !== null, fn ($query) => $query->where('lesson_id', $lessonId));

        $totals = (clone $base)->selectRaw(
            'COALESCE(SUM(session_count), 0) AS session_count,
             COALESCE(SUM(completed_count), 0) AS completed_count,
             COALESCE(SUM(error_session_count), 0) AS error_session_count,
             COALESCE(SUM(buffering_session_count), 0) AS buffering_session_count,
             COALESCE(SUM(startup_sample_count), 0) AS startup_sample_count,
             COALESCE(SUM(startup_latency_total_ms), 0) AS startup_latency_total_ms,
             COALESCE(MAX(startup_latency_max_ms), 0) AS startup_latency_max_ms,
             COALESCE(SUM(buffer_event_count), 0) AS buffer_event_count,
             COALESCE(SUM(buffer_duration_total_ms), 0) AS buffer_duration_total_ms,
             COALESCE(SUM(recovery_total), 0) AS recovery_total,
             COALESCE(SUM(bitrate_sample_count), 0) AS bitrate_sample_count,
             COALESCE(SUM(bitrate_total_kbps), 0) AS bitrate_total_kbps'
        )->first();

        $sessionCount = (int) $totals->session_count;
        $startupSamples = (int) $totals->startup_sample_count;
        $bitrateSamples = (int) $totals->bitrate_sample_count;
        $overall = [
            'sessions' => $sessionCount,
            'completed_sessions' => (int) $totals->completed_count,
            'completion_rate' => $this->rate((int) $totals->completed_count, $sessionCount),
            'error_sessions' => (int) $totals->error_session_count,
            'error_rate' => $this->rate((int) $totals->error_session_count, $sessionCount),
            'buffering_sessions' => (int) $totals->buffering_session_count,
            'buffering_rate' => $this->rate((int) $totals->buffering_session_count, $sessionCount),
            'average_startup_latency_ms' => $startupSamples > 0
                ? (int) round((int) $totals->startup_latency_total_ms / $startupSamples)
                : null,
            'maximum_startup_latency_ms' => (int) $totals->startup_latency_max_ms,
            'buffer_events' => (int) $totals->buffer_event_count,
            'buffer_duration_ms' => (int) $totals->buffer_duration_total_ms,
            'recoveries' => (int) $totals->recovery_total,
            'average_effective_bitrate_kbps' => $bitrateSamples > 0
                ? (int) round((int) $totals->bitrate_total_kbps / $bitrateSamples)
                : null,
        ];

        $series = (clone $base)
            ->selectRaw('bucket_start, SUM(session_count) AS sessions, SUM(error_session_count) AS errors, SUM(buffering_session_count) AS buffering_sessions')
            ->groupBy('bucket_start')
            ->orderBy('bucket_start')
            ->get()
            ->map(fn (object $row): array => [
                'bucket_start' => $row->bucket_start,
                'sessions' => (int) $row->sessions,
                'errors' => (int) $row->errors,
                'buffering_sessions' => (int) $row->buffering_sessions,
            ])->all();

        return [
            'window' => ['hours' => $hours, 'from' => $from->toIso8601String(), 'lesson_id' => $lessonId],
            'active_sessions' => $this->activeSessionCount(),
            'overall' => $overall,
            'series' => $series,
            'by_quality' => $this->breakdown($base, 'effective_quality'),
            'by_network' => $this->breakdown($base, 'connection_type'),
            'by_os' => $this->breakdown($base, 'os_family'),
            'errors' => $this->breakdown($base, 'error_code', true),
            'last_rollup_at' => (clone $base)->max('updated_at'),
        ];
    }

    /** @return array<string, int|string> */
    private function dimensions(PlaybackSession $session): array
    {
        $endedAt = $session->ended_at instanceof Carbon ? $session->ended_at : Carbon::parse($session->ended_at);

        return [
            'bucket_start' => $endedAt->copy()->startOfHour()->toDateTimeString(),
            'lesson_id' => max(0, (int) $session->lesson_id),
            'os_family' => $this->dimension($session->os_family, 12, 'other'),
            'connection_type' => $this->dimension($session->connection_type, 12),
            'effective_quality' => $this->dimension($session->effective_quality, 12),
            'playback_reason' => $this->dimension($session->playback_reason, 48),
            'error_code' => $this->dimension($session->last_error_code, 64, 'none'),
        ];
    }

    /** @param Collection<int, PlaybackSession> $sessions @return array<string, int> */
    private function aggregate(Collection $sessions): array
    {
        return [
            'session_count' => $sessions->count(),
            'completed_count' => $sessions->where('end_reason', 'completed')->count(),
            'error_session_count' => $sessions->filter(fn (PlaybackSession $session): bool => $session->last_error_code !== null)->count(),
            'buffering_session_count' => $sessions->filter(fn (PlaybackSession $session): bool => (int) $session->buffer_count > 0)->count(),
            'startup_sample_count' => $sessions->filter(fn (PlaybackSession $session): bool => $session->startup_latency_ms !== null)->count(),
            'startup_latency_total_ms' => (int) $sessions->sum('startup_latency_ms'),
            'startup_latency_max_ms' => (int) $sessions->max('startup_latency_ms'),
            'buffer_event_count' => (int) $sessions->sum('buffer_count'),
            'buffer_duration_total_ms' => (int) $sessions->sum('buffer_duration_ms'),
            'recovery_total' => (int) $sessions->sum('recovery_count'),
            'bitrate_sample_count' => $sessions->filter(fn (PlaybackSession $session): bool => $session->effective_bitrate_kbps !== null)->count(),
            'bitrate_total_kbps' => (int) $sessions->sum('effective_bitrate_kbps'),
        ];
    }

    private function activeSessionCount(): int
    {
        $cutoff = now()->subMinutes(max(2, (int) config('playback.stale_after_minutes', 10)));

        return PlaybackSession::query()
            ->whereNull('ended_at')
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_heartbeat_at', '>', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('last_heartbeat_at')->where('started_at', '>', $cutoff);
                    });
            })->count();
    }

    private function breakdown($base, string $column, bool $excludeNone = false): array
    {
        return (clone $base)
            ->when($excludeNone, fn ($query) => $query->where($column, '<>', 'none'))
            ->selectRaw("{$column} AS value, SUM(session_count) AS sessions")
            ->groupBy($column)
            ->orderByDesc('sessions')
            ->limit(20)
            ->get()
            ->map(fn (object $row): array => ['value' => (string) $row->value, 'sessions' => (int) $row->sessions])
            ->all();
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0.0;
    }

    private function dimension(mixed $value, int $maxLength, string $fallback = 'unknown'): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
