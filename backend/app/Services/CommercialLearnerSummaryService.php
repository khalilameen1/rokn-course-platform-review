<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;

/** Summarizes already-read learner rows without querying or repricing their contracts. */
final class CommercialLearnerSummaryService
{
    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function forRows(Collection $rows): array
    {
        $enrollments = $rows->count();
        $students = $rows->map(function (array $row): int {
            return (int) ($row['enrollment']?->user_id ?? $row['user']?->id ?? 0);
        })->filter()->unique()->count();
        $netComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_net_complete']);
        $costComplete = $rows->every(fn (array $row): bool => (bool) $row['service_cost_complete']);
        $estimatedComplete = $rows->every(
            fn (array $row): bool => $row['service_cost_with_estimates_egp'] !== null
        );
        $net = $netComplete ? round((float) $rows->sum('cash_net_known_egp'), 2) : null;
        $cost = $costComplete ? round((float) $rows->sum('service_cost_actual_egp'), 2) : null;
        $margin = $net !== null && $cost !== null ? round($net - $cost, 2) : null;
        $aiRequests = (int) $rows->sum('ai_requests');
        $aiFailedRequests = (int) $rows->sum('ai_failed_requests');
        $aiUnansweredRequests = (int) $rows->sum('ai_unanswered_requests');
        $aiEstimatedRequests = (int) $rows->sum('ai_estimated_requests');
        $aiMeasurementAvailable = $rows->every(
            fn (array $row): bool => (bool) ($row['ai_measurement_available'] ?? true)
        );
        $aiAttempts = $aiRequests + $aiFailedRequests + $aiUnansweredRequests;
        $actualCostByService = collect(CourseCostReportService::serviceLabels())
            ->mapWithKeys(function (string $_label, string $serviceKey) use ($rows): array {
                $complete = $rows->every(fn (array $row): bool =>
                    ($row['actual_cost_by_service_egp'][$serviceKey] ?? null) !== null
                );

                return [$serviceKey => $complete
                    ? round((float) $rows->sum(fn (array $row): float =>
                        (float) $row['actual_cost_by_service_egp'][$serviceKey]
                    ), 4)
                    : null];
            })->all();
        $estimatedCostByService = collect(CourseCostReportService::serviceLabels())
            ->mapWithKeys(function (string $_label, string $serviceKey) use ($rows): array {
                $complete = $rows->every(fn (array $row): bool =>
                    ($row['cost_with_estimates_by_service_egp'][$serviceKey] ?? null) !== null
                );

                return [$serviceKey => $complete
                    ? round((float) $rows->sum(fn (array $row): float =>
                        (float) $row['cost_with_estimates_by_service_egp'][$serviceKey]
                    ), 4)
                    : null];
            })->all();

        return [
            'students' => $students,
            'active_students' => $rows->where('is_active', true)
                ->map(fn (array $row): int => (int) ($row['enrollment']?->user_id ?? 0))
                ->filter()
                ->unique()
                ->count(),
            'enrollments' => $enrollments,
            'coin_allocation_complete' => $rows->every(
                fn (array $row): bool => (bool) ($row['coin_allocation_complete'] ?? false)
            ),
            'coins' => (int) $rows->sum('total_coins'),
            'discount_coins' => (int) $rows->sum('discount_coins'),
            'gross_egp' => round((float) $rows->sum('cash_gross_egp'), 2),
            'net_egp' => $net,
            'ai_requests' => $aiRequests,
            'ai_failed_requests' => $aiFailedRequests,
            'ai_unanswered_requests' => $aiUnansweredRequests,
            'ai_estimated_requests' => $aiEstimatedRequests,
            'ai_cost_complete' => $aiMeasurementAvailable && $aiEstimatedRequests === 0,
            'ai_failure_rate_percentage' => $aiAttempts > 0
                ? round((($aiFailedRequests + $aiUnansweredRequests) / $aiAttempts) * 100, 2)
                : null,
            'ai_tokens' => (int) $rows->sum('ai_tokens'),
            'ai_measurement_available' => $aiMeasurementAvailable,
            'ai_cost_usd' => $aiMeasurementAvailable
                ? round((float) $rows->sum('ai_cost_usd'), 6)
                : null,
            'playback_minutes' => round((float) $rows->sum('playback_minutes'), 2),
            'playback_gb_estimated' => round((float) $rows->sum('playback_gb_estimated'), 4),
            'service_cost_egp' => $cost,
            'service_breakdown_actual_egp' => $actualCostByService,
            'margin_egp' => $margin,
            'estimated_cost_egp' => $estimatedComplete
                ? round((float) $rows->sum('service_cost_with_estimates_egp'), 2)
                : null,
            'service_breakdown_with_estimates_egp' => $estimatedCostByService,
            'estimated_margin_egp' => $netComplete && $estimatedComplete
                ? round((float) $rows->sum('estimated_contribution_margin_egp'), 2)
                : null,
            'average_net_per_student_egp' => $students > 0 && $net !== null
                ? round($net / $students, 2)
                : null,
            'average_cost_per_student_egp' => $students > 0 && $cost !== null
                ? round($cost / $students, 2)
                : null,
            'average_net_per_enrollment_egp' => $enrollments > 0 && $net !== null
                ? round($net / $enrollments, 2)
                : null,
            'average_cost_per_enrollment_egp' => $enrollments > 0 && $cost !== null
                ? round($cost / $enrollments, 2)
                : null,
        ] + $this->unitEconomics($net, $cost, $margin);
    }

    /** @return array<string, float|null> */
    public function unitEconomics(?float $net, ?float $cost, ?float $margin): array
    {
        return [
            'cost_to_net_revenue_percentage' => $net !== null && $net > 0 && $cost !== null
                ? round(($cost / $net) * 100, 2)
                : null,
            'contribution_margin_percentage' => $net !== null && $net > 0 && $margin !== null
                ? round(($margin / $net) * 100, 2)
                : null,
        ];
    }
}
