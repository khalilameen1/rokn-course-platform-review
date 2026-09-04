<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessClock;

/** Allocates measurable and invoiced service costs to course learners. */
final class CourseCostReportService
{
    public const OPENROUTER_SERVICE = 'openrouter';

    /** @return array<string, string> */
    public static function serviceLabels(): array
    {
        return [self::OPENROUTER_SERVICE => 'OpenRouter: الذكاء الاصطناعي']
            + OperatingCostPool::SERVICES;
    }

    /** @param Collection<int, int> $userIds @return array<string, mixed> */
    public function forCourse(Course $course, Collection $userIds): array
    {
        $userIds = $userIds->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $rows = $userIds->mapWithKeys(fn (int $id): array => [$id => $this->emptyUserCost()]);
        $usdToEgp = (float) (Setting::query()->value('openrouter_usd_to_egp_rate') ?? 0);
        $aiMeasurementAvailable = true;

        if ($userIds->isNotEmpty()) {
            $costSource = match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.cost_usage_source'))",
                'pgsql' => "metadata->>'cost_usage_source'",
                'sqlite' => "json_extract(metadata, '$.cost_usage_source')",
                default => "''",
            };
            $deliverySource = match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.entitlement_delivered'))",
                'pgsql' => "metadata->>'entitlement_delivered'",
                'sqlite' => "CAST(json_extract(metadata, '$.entitlement_delivered') AS TEXT)",
                default => "'true'",
            };
            $delivered = "COALESCE({$deliverySource}, 'true') NOT IN ('false', '0')";
            $unanswered = "COALESCE({$deliverySource}, 'true') IN ('false', '0')";
            $estimatedCost = "COALESCE({$costSource}, '') <> 'provider'";
            $egpSelect = ", SUM(CASE WHEN status = 'completed' AND COALESCE({$costSource}, '') = 'provider' THEN COALESCE(cost_egp, 0) ELSE 0 END) as cost_egp, SUM(CASE WHEN status = 'completed' AND COALESCE({$costSource}, '') = 'provider' AND cost_usd > 0 AND cost_egp IS NULL THEN cost_usd ELSE 0 END) as unsnapped_cost_usd, SUM(CASE WHEN status = 'completed' AND {$estimatedCost} THEN COALESCE(cost_egp, 0) ELSE 0 END) as estimated_cost_egp, SUM(CASE WHEN status = 'completed' AND {$estimatedCost} AND cost_usd > 0 AND cost_egp IS NULL THEN cost_usd ELSE 0 END) as estimated_unsnapped_cost_usd";
            $ai = DB::table('ai_usage_events')
                ->where('course_id', $course->id)
                ->whereIn('user_id', $userIds)
                ->selectRaw("user_id, SUM(CASE WHEN status = 'completed' AND {$delivered} THEN 1 ELSE 0 END) as completed_requests, SUM(CASE WHEN status = 'completed' AND {$unanswered} THEN 1 ELSE 0 END) as unanswered_requests, SUM(CASE WHEN status = 'completed' AND {$estimatedCost} THEN 1 ELSE 0 END) as estimated_requests, SUM(CASE WHEN status IN ('failed','cancelled','expired') THEN 1 ELSE 0 END) as failed_requests, SUM(CASE WHEN status = 'completed' THEN total_tokens ELSE 0 END) as total_tokens, SUM(CASE WHEN status = 'completed' THEN cost_usd ELSE 0 END) as cost_usd{$egpSelect}")
                ->groupBy('user_id')
                ->get();
            foreach ($ai as $usage) {
                $row = $rows->get((int) $usage->user_id, $this->emptyUserCost());
                $row['ai_requests'] = (int) $usage->completed_requests;
                $row['ai_unanswered_requests'] = (int) $usage->unanswered_requests;
                $row['ai_estimated_requests'] = (int) $usage->estimated_requests;
                $row['ai_cost_complete'] = (int) $usage->estimated_requests === 0;
                $row['ai_failed_requests'] = (int) $usage->failed_requests;
                $row['ai_tokens'] = (int) $usage->total_tokens;
                $row['ai_cost_usd'] = round((float) $usage->cost_usd, 6);
                $unsnappedUsd = (float) $usage->unsnapped_cost_usd;
                $estimatedUnsnappedUsd = (float) $usage->estimated_unsnapped_cost_usd;
                $row['ai_cost_egp'] = $unsnappedUsd < 0.0000005
                    ? round((float) $usage->cost_egp, 4)
                    : null;
                $knownEstimatedEgp = (float) $usage->cost_egp
                    + (float) $usage->estimated_cost_egp;
                $allUnsnappedUsd = $unsnappedUsd + $estimatedUnsnappedUsd;
                $row['ai_cost_egp_estimated'] = $allUnsnappedUsd < 0.0000005
                    ? round($knownEstimatedEgp, 4)
                    : ($usdToEgp > 0
                        ? round($knownEstimatedEgp + $allUnsnappedUsd * $usdToEgp, 4)
                        : null);
                $row['actual_cost_by_service_egp'][self::OPENROUTER_SERVICE] = $row['ai_cost_egp'];
                $row['cost_with_estimates_by_service_egp'][self::OPENROUTER_SERVICE]
                    = $row['ai_cost_egp_estimated'];
                $rows->put((int) $usage->user_id, $row);
            }
            $byFeature = DB::table('ai_usage_events')
                ->where('course_id', $course->id)
                ->whereIn('user_id', $userIds)
                ->whereIn('feature', ['course_chat', 'project_feedback', 'project_followup'])
                ->selectRaw("user_id, feature, SUM(CASE WHEN status = 'completed' AND {$delivered} THEN 1 ELSE 0 END) as delivered_requests, SUM(CASE WHEN status = 'completed' AND {$unanswered} THEN 1 ELSE 0 END) as unanswered_requests, SUM(CASE WHEN status = 'completed' THEN cost_usd ELSE 0 END) as cost_usd")
                ->groupBy('user_id', 'feature')->get();
            foreach ($byFeature as $usage) {
                $row = $rows->get((int) $usage->user_id, $this->emptyUserCost());
                $row['ai_by_feature'][(string) $usage->feature] = [
                    'delivered_requests' => (int) $usage->delivered_requests,
                    'unanswered_requests' => (int) $usage->unanswered_requests,
                    'cost_usd' => round((float) $usage->cost_usd, 6),
                ];
                $rows->put((int) $usage->user_id, $row);
            }
        }

        $playback = $this->playbackUsage((int) $course->id, null, null, $userIds);
        foreach ($rows as $userId => $row) {
            $usage = $playback['users']->get((int) $userId, ['minutes' => 0.0, 'gb' => 0.0]);
            $row['playback_minutes'] = round((float) $usage['minutes'], 2);
            $row['playback_gb_estimated'] = round((float) $usage['gb'], 4);
            $rows->put($userId, $row);
        }

        $unallocatedPools = [];
        $incompleteFinalPool = false;
        $incompleteEstimatedPool = false;
        $incompleteActualServices = [];
        $incompleteEstimatedServices = [];
        if (!$aiMeasurementAvailable) {
            $incompleteActualServices[self::OPENROUTER_SERVICE] = true;
            $incompleteEstimatedServices[self::OPENROUTER_SERVICE] = true;
        }
        $pools = OperatingCostPool::query()
                ->where(function ($query) use ($course): void {
                    $query->whereNull('course_id')->orWhere('course_id', $course->id);
                })
                ->orderBy('period_start')
                ->get();
        $driverAllocations = [];
        foreach ($pools as $pool) {
                $amountEgp = $pool->amountEgp();
                if ($amountEgp === null) {
                    $unallocatedPools[] = "{$pool->name}: سعر التحويل غير موجود";
                    $incompleteFinalPool = $incompleteFinalPool || (bool) $pool->is_final;
                    $incompleteEstimatedPool = $incompleteEstimatedPool || !(bool) $pool->is_final;
                    $target = $pool->is_final
                        ? $incompleteActualServices
                        : $incompleteEstimatedServices;
                    $target[$pool->service_key] = true;
                    if ($pool->is_final) {
                        $incompleteActualServices = $target;
                    } else {
                        $incompleteEstimatedServices = $target;
                    }
                    continue;
                }
                $allocationKey = implode(':', [
                    $pool->allocation_driver,
                    $pool->course_id ?: 'platform',
                    $pool->period_start->format('Y-m-d'),
                    $pool->period_end->format('Y-m-d'),
                ]);
                $allocation = $driverAllocations[$allocationKey]
                    ??= $this->poolDriver($pool, (int) $course->id, $userIds);
                if ($allocation['denominator'] <= 0) {
                    $unallocatedPools[] = "{$pool->name}: لا توجد بيانات لمسبب التكلفة";
                    $incompleteFinalPool = $incompleteFinalPool || (bool) $pool->is_final;
                    $incompleteEstimatedPool = $incompleteEstimatedPool || !(bool) $pool->is_final;
                    $target = $pool->is_final
                        ? $incompleteActualServices
                        : $incompleteEstimatedServices;
                    $target[$pool->service_key] = true;
                    if ($pool->is_final) {
                        $incompleteActualServices = $target;
                    } else {
                        $incompleteEstimatedServices = $target;
                    }
                    continue;
                }
                foreach ($rows as $userId => $row) {
                    $share = (float) ($allocation['users']->get((int) $userId, 0))
                        / (float) $allocation['denominator'];
                    $allocated = round($amountEgp * min(1, max(0, $share)), 4);
                    $key = $pool->is_final ? 'allocated_operating_cost_egp' : 'estimated_operating_cost_egp';
                    $row[$key] = round((float) $row[$key] + $allocated, 4);
                    $serviceMapKey = $pool->is_final
                        ? 'actual_cost_by_service_egp'
                        : 'estimated_pool_cost_by_service_egp';
                    $row[$serviceMapKey][$pool->service_key] = round(
                        (float) ($row[$serviceMapKey][$pool->service_key] ?? 0) + $allocated,
                        4
                    );
                    $rows->put($userId, $row);
                }
        }

        foreach ($rows as $userId => $row) {
            $hasUnsnapshottedAiCost = !$row['ai_cost_complete']
                || ((float) $row['ai_cost_usd'] > 0 && $row['ai_cost_egp'] === null);
            $row['service_cost_complete'] = !$hasUnsnapshottedAiCost && !$incompleteFinalPool;
            $row['service_cost_actual_egp'] = !$row['service_cost_complete']
                ? null
                : round((float) ($row['ai_cost_egp'] ?? 0) + (float) $row['allocated_operating_cost_egp'], 4);
            $row['service_cost_estimate_complete'] = $row['ai_cost_egp_estimated'] !== null
                && !$incompleteFinalPool
                && !$incompleteEstimatedPool;
            $row['service_cost_with_estimates_egp'] = !$row['service_cost_estimate_complete']
                ? null
                : round(
                    (float) $row['ai_cost_egp_estimated']
                    + (float) $row['allocated_operating_cost_egp']
                    + (float) $row['estimated_operating_cost_egp'],
                    4
                );
            foreach (array_keys(self::serviceLabels()) as $serviceKey) {
                $actual = $row['actual_cost_by_service_egp'][$serviceKey] ?? 0.0;
                $estimate = $row['estimated_pool_cost_by_service_egp'][$serviceKey] ?? 0.0;
                $estimatedBase = $serviceKey === self::OPENROUTER_SERVICE
                    ? $row['ai_cost_egp_estimated']
                    : $actual;
                $actualIncomplete = isset($incompleteActualServices[$serviceKey])
                    || ($serviceKey === self::OPENROUTER_SERVICE && $hasUnsnapshottedAiCost);
                $estimateIncomplete = isset($incompleteEstimatedServices[$serviceKey])
                    || ($serviceKey === self::OPENROUTER_SERVICE && $estimatedBase === null);
                $row['actual_cost_by_service_egp'][$serviceKey]
                    = $actualIncomplete ? null : round((float) $actual, 4);
                $row['cost_with_estimates_by_service_egp'][$serviceKey]
                    = ($actualIncomplete
                            && $serviceKey !== self::OPENROUTER_SERVICE)
                        || $estimateIncomplete
                            ? null
                            : round((float) $estimatedBase + (float) $estimate, 4);
            }
            unset($row['estimated_pool_cost_by_service_egp']);
            $rows->put($userId, $row);
        }

        $complete = $rows->every(fn (array $row): bool => (bool) $row['service_cost_complete']);
        $estimateComplete = $rows->every(
            fn (array $row): bool => (bool) $row['service_cost_estimate_complete']
        );
        $serviceBreakdown = collect(self::serviceLabels())->map(function (
            string $label,
            string $serviceKey
        ) use ($rows, $incompleteActualServices, $incompleteEstimatedServices): array {
            $actualComplete = !isset($incompleteActualServices[$serviceKey])
                && $rows->every(fn (array $row): bool =>
                    ($row['actual_cost_by_service_egp'][$serviceKey] ?? null) !== null
                );
            $estimateComplete = !isset($incompleteEstimatedServices[$serviceKey])
                && ($actualComplete || $serviceKey === self::OPENROUTER_SERVICE)
                && $rows->every(fn (array $row): bool =>
                    ($row['cost_with_estimates_by_service_egp'][$serviceKey] ?? null) !== null
                );

            return [
                'key' => $serviceKey,
                'label' => $label,
                'actual_complete' => $actualComplete,
                'actual_egp' => $actualComplete
                    ? round((float) $rows->sum(fn (array $row): float =>
                        (float) ($row['actual_cost_by_service_egp'][$serviceKey] ?? 0)
                    ), 4)
                    : null,
                'estimate_complete' => $estimateComplete,
                'with_estimates_egp' => $estimateComplete
                    ? round((float) $rows->sum(fn (array $row): float =>
                        (float) ($row['cost_with_estimates_by_service_egp'][$serviceKey] ?? 0)
                    ), 4)
                    : null,
            ];
        })->values();

        return [
            'users' => $rows,
            'openrouter_usd_to_egp_rate' => $usdToEgp > 0 ? $usdToEgp : null,
            'ai_measurement_available' => $aiMeasurementAvailable,
            'ai_cost_usd' => $aiMeasurementAvailable
                ? round((float) $rows->sum('ai_cost_usd'), 6)
                : null,
            'service_cost_actual_egp' => $complete
                ? round((float) $rows->sum('service_cost_actual_egp'), 4)
                : null,
            'service_cost_with_estimates_egp' => $estimateComplete
                ? round((float) $rows->sum('service_cost_with_estimates_egp'), 4)
                : null,
            'playback_minutes' => round((float) $rows->sum('playback_minutes'), 2),
            'playback_gb_estimated' => round((float) $rows->sum('playback_gb_estimated'), 4),
            'complete' => $complete,
            'estimate_complete' => $estimateComplete,
            'service_breakdown' => $serviceBreakdown,
            'unallocated_pools' => $unallocatedPools,
        ];
    }

    /** @return array<string, int|float|bool|null> */
    private function emptyUserCost(): array
    {
        return [
            'ai_requests' => 0, 'ai_failed_requests' => 0,
            'ai_unanswered_requests' => 0, 'ai_tokens' => 0,
            'ai_by_feature' => [],
            'ai_estimated_requests' => 0, 'ai_cost_complete' => true,
            'ai_measurement_available' => true,
            'ai_cost_usd' => 0.0, 'ai_cost_egp' => 0.0,
            'ai_cost_egp_estimated' => 0.0,
            'playback_minutes' => 0.0, 'playback_gb_estimated' => 0.0,
            'allocated_operating_cost_egp' => 0.0,
            'estimated_operating_cost_egp' => 0.0,
            'service_cost_actual_egp' => 0.0,
            'service_cost_with_estimates_egp' => 0.0,
            'service_cost_complete' => true,
            'service_cost_estimate_complete' => true,
            'actual_cost_by_service_egp' => array_fill_keys(
                array_keys(self::serviceLabels()),
                0.0
            ),
            'estimated_pool_cost_by_service_egp' => array_fill_keys(
                array_keys(self::serviceLabels()),
                0.0
            ),
            'cost_with_estimates_by_service_egp' => array_fill_keys(
                array_keys(self::serviceLabels()),
                0.0
            ),
        ];
    }

    /** @return array{users:Collection<int,float>,denominator:float} */
    private function poolDriver(
        OperatingCostPool $pool,
        int $courseId,
        Collection $userIds
    ): array {
        $scopedCourseId = $pool->course_id ? (int) $pool->course_id : null;
        if (in_array($pool->allocation_driver, ['playback_gb', 'playback_minutes'], true)) {
            $numerator = $this->playbackUsage(
                $courseId,
                $pool->period_start,
                $pool->period_end,
                $userIds
            );
            $denominator = $this->playbackUsage(
                $scopedCourseId,
                $pool->period_start,
                $pool->period_end,
                null
            );
            $metric = $pool->allocation_driver === 'playback_gb' ? 'gb' : 'minutes';

            return [
                'users' => $numerator['users']->map(fn (array $row): float => (float) $row[$metric]),
                'denominator' => (float) $denominator[$metric],
            ];
        }

        [$periodStart] = BusinessClock::localDayRangeUtc($pool->period_start->format('Y-m-d'));
        [, $periodEnd] = BusinessClock::localDayRangeUtc($pool->period_end->format('Y-m-d'));
        $query = DB::table('course_enrollments')
            ->where('enrolled_at', '<', $periodEnd)
            ->where(function ($active) use ($periodStart): void {
                $active->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $periodStart);
            });
        if ($scopedCourseId) $query->where('course_id', $scopedCourseId);
        $denominator = (float) (clone $query)->count();
        $userCounts = (clone $query)
            ->where('course_id', $courseId)
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn ($value): float => (float) $value);

        return ['users' => $userCounts, 'denominator' => $denominator];
    }

    /**
     * Data volume is an estimate from measured session duration × effective
     * bitrate; invoices remain the actual monetary source of truth.
     *
     * @param Collection<int,int>|null $userIds
     * @return array{users:Collection<int,array{minutes:float,gb:float}>,minutes:float,gb:float}
     */
    private function playbackUsage(
        ?int $courseId,
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        ?Collection $userIds
    ): array {
        $query = DB::table('playback_sessions as ps')
            ->join('course_sections as cs', 'cs.id', '=', 'ps.course_section_id')
            ->select([
                'ps.user_id', 'ps.started_playing_at', 'ps.started_at', 'ps.ended_at',
                'ps.last_heartbeat_at', 'ps.duration_seconds', 'ps.buffer_duration_ms',
                'ps.effective_bitrate_kbps', 'ps.effective_quality',
            ]);
        if ($courseId) $query->where('cs.course_id', $courseId);
        if ($start) {
            [$startUtc] = BusinessClock::localDayRangeUtc($start->format('Y-m-d'));
            $query->where('ps.started_at', '>=', $startUtc);
        }
        if ($end) {
            [, $endUtc] = BusinessClock::localDayRangeUtc($end->format('Y-m-d'));
            $query->where('ps.started_at', '<', $endUtc);
        }
        if ($userIds !== null) {
            if ($userIds->isEmpty()) return ['users' => collect(), 'minutes' => 0.0, 'gb' => 0.0];
            $query->whereIn('ps.user_id', $userIds);
        }

        $users = collect();
        foreach ($query->cursor() as $session) {
            $started = $session->started_playing_at ?: $session->started_at;
            $ended = $session->ended_at ?: $session->last_heartbeat_at;
            $seconds = $started && $ended
                ? max(0, min(21600, Carbon::parse($started)->diffInSeconds(Carbon::parse($ended))))
                : 0;
            if ((int) $session->duration_seconds > 0) {
                $seconds = min($seconds, (int) $session->duration_seconds);
            }
            $seconds = max(0, $seconds - (int) floor(((int) $session->buffer_duration_ms) / 1000));
            $bitrate = (int) $session->effective_bitrate_kbps;
            if ($bitrate <= 0) {
                $bitrate = match ((string) $session->effective_quality) {
                    '1080p' => 5000, '720p' => 2800, '480p' => 1400, '360p' => 750,
                    default => 1800,
                };
            }
            $row = $users->get((int) $session->user_id, ['minutes' => 0.0, 'gb' => 0.0]);
            $row['minutes'] += $seconds / 60;
            $row['gb'] += ($seconds * $bitrate * 1000 / 8) / 1_000_000_000;
            $users->put((int) $session->user_id, $row);
        }

        return [
            'users' => $users,
            'minutes' => (float) $users->sum('minutes'),
            'gb' => (float) $users->sum('gb'),
        ];
    }
}
