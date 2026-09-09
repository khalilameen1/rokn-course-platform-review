<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Financial reporting reads provider charges, never budget reservations. */
final class AiUsageReportService
{
    public function summary(ReportPeriod $period, ?int $courseId = null): array
    {
        return $this->normalize($this->query($period, $courseId)->first());
    }

    public function byUser(ReportPeriod $period, int $courseId, Collection $userIds): Collection
    {
        return $this->query($period, $courseId)->whereIn('user_id', $userIds)
            ->addSelect('user_id')->groupBy('user_id')->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->user_id => $this->normalize($row)]);
    }

    public function byFeature(ReportPeriod $period, int $courseId, Collection $userIds): Collection
    {
        return $this->query($period, $courseId)->whereIn('user_id', $userIds)
            ->addSelect(['user_id', 'feature'])->groupBy('user_id', 'feature')->get()
            ->map(fn ($row): array => ['user_id' => (int) $row->user_id, 'feature' => $row->feature]
                + $this->normalize($row));
    }

    private function query(ReportPeriod $period, ?int $courseId): Builder
    {
        $source = $this->jsonValue('cost_usage_source');
        $delivery = $this->jsonValue('entitlement_delivered');
        $provider = "COALESCE({$source}, '') = 'provider' AND cost_usd IS NOT NULL AND cost_usd >= 0";
        $cached = "COALESCE({$source}, '') = 'cache_zero_cost' AND cost_usd IS NOT NULL AND cost_usd = 0";
        $known = "(({$provider}) OR ({$cached}))";
        $delivered = "status = 'completed' AND COALESCE({$delivery}, 'true') NOT IN ('false', '0')";
        $unanswered = "status = 'completed' AND COALESCE({$delivery}, 'true') IN ('false', '0')";
        $pending = "status = 'completed' AND NOT {$known}";

        return $period->apply(DB::table('ai_usage_events'), 'created_at')
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->selectRaw("SUM(CASE WHEN {$delivered} THEN 1 ELSE 0 END) as completed_requests")
            ->selectRaw("SUM(CASE WHEN {$unanswered} THEN 1 ELSE 0 END) as unanswered_requests")
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'cancelled', 'expired') THEN 1 ELSE 0 END) as failed_requests")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN total_tokens ELSE 0 END) as tokens")
            ->selectRaw("SUM(CASE WHEN {$provider} THEN 1 ELSE 0 END) as provider_cost_requests")
            ->selectRaw("SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) as estimated_cost_requests")
            ->selectRaw("SUM(CASE WHEN {$known} THEN cost_usd ELSE 0 END) as cost_usd")
            ->selectRaw("SUM(CASE WHEN {$known} THEN COALESCE(cost_egp, 0) ELSE 0 END) as known_cost_egp")
            ->selectRaw("SUM(CASE WHEN {$known} AND cost_usd > 0 AND cost_egp IS NULL THEN 1 ELSE 0 END) as missing_fx_requests");
    }

    private function normalize(object $row): array
    {
        $pending = (int) $row->estimated_cost_requests;

        return [
            'available' => true,
            'completed_requests' => (int) $row->completed_requests,
            'unanswered_requests' => (int) $row->unanswered_requests,
            'failed_requests' => (int) $row->failed_requests,
            'tokens' => (int) $row->tokens,
            'cost_usd' => round((float) $row->cost_usd, 6),
            'cost_egp' => $pending === 0 && (int) $row->missing_fx_requests === 0
                ? round((float) $row->known_cost_egp, 4) : null,
            'provider_cost_requests' => (int) $row->provider_cost_requests,
            // Kept as a count for existing consumers; no estimated amount is reported.
            'estimated_cost_requests' => $pending,
            'cost_complete' => $pending === 0,
        ];
    }

    private function jsonValue(string $key): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.{$key}'))",
            'pgsql' => "metadata->>'{$key}'",
            'sqlite' => "CAST(json_extract(metadata, '$.{$key}') AS TEXT)",
            default => throw new \LogicException('Unsupported reporting database.'),
        };
    }
}
