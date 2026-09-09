<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OperatingCostPool;
use App\Support\BusinessClock;
use App\Support\ReportPeriod;

/** Recorded final invoices; no inferred bill or allocation by usage. */
final class ProviderInvoiceReportService
{
    public function summary(ReportPeriod $period, ?int $courseId = null): array
    {
        $query = OperatingCostPool::query()->where('is_final', true);
        // Recognize a complete invoice at the end of its billing period.
        // Never prorate an invoice into imaginary daily/student charges.
        if ($period->start !== null) {
            $query->where('period_end', '>=', BusinessClock::format($period->start, 'Y-m-d'))
                ->where('period_end', '<=', BusinessClock::format($period->end, 'Y-m-d'));
        }
        $inPeriod = fn (OperatingCostPool $invoice): bool => $period->contains(
            BusinessClock::localDate($invoice->period_end->format('Y-m-d'))->utc()
        );
        $sharedCosts = $courseId !== null && (clone $query)->whereNull('course_id')->get()->contains($inPeriod);
        $invoices = $query->when($courseId !== null, fn ($q) => $q->where('course_id', $courseId))
            ->get()->filter($inPeriod);
        $services = collect(OperatingCostPool::SERVICES)->map(function (string $label, string $key) use ($invoices): array {
            $records = $invoices->where('service_key', $key);
            $amounts = $records->map(fn (OperatingCostPool $invoice): ?float => $invoice->amountEgp());

            return [
                'key' => $key, 'label' => $label, 'invoices' => $records->count(),
                'actual_egp' => $records->isNotEmpty() && !$amounts->containsStrict(null)
                    ? round((float) $amounts->sum(), 4) : null,
                'known_egp' => round((float) $amounts->filter(fn ($amount) => $amount !== null)->sum(), 4),
                'missing_fx' => $amounts->filter(fn ($amount) => $amount === null)->count(),
            ];
        })->values();

        return [
            'services' => $services,
            'has_invoices' => $invoices->isNotEmpty(),
            'known_total_egp' => round((float) $services->sum('known_egp'), 4),
            'missing_fx' => (int) $services->sum('missing_fx'),
            'shared_costs' => $sharedCosts,
        ];
    }
}
