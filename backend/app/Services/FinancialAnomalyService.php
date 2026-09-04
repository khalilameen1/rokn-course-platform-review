<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\FinancialAnomaly;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

final readonly class FinancialAnomalyService
{
    public function __construct(
        private CourseAccessPlanService $plans,
        private WalletService $wallet,
        private InternalSignalService $internalSignals
    ) {
    }

    /**
     * Read-only entitlement decisions for a loaded enrollment set.
     *
     * @param Collection<int,CourseEnrollment> $enrollments
     * @return array<int,bool> keyed by enrollment id
     */
    public function variableCostDecisions(Collection $enrollments): array
    {
        $enrollments = $enrollments->filter(fn ($row) => $row instanceof CourseEnrollment)->values();
        if ($enrollments->isEmpty() || !Schema::hasTable('financial_anomalies')) {
            return $enrollments->mapWithKeys(fn (CourseEnrollment $row) => [(int) $row->id => true])->all();
        }

        $userIds = $enrollments->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values();
        $courseIds = $enrollments->pluck('course_id')->map(fn ($id) => (int) $id)->unique()->values();
        $paid = $this->wallet->coursePaidContributionTotals(
            $userIds->all(),
            $courseIds->all()
        );
        $openExpected = FinancialAnomaly::query()
            ->whereIn('user_id', $userIds)->whereIn('course_id', $courseIds)
            ->where('status', FinancialAnomaly::STATUS_OPEN)
            ->groupBy('user_id', 'course_id')
            ->selectRaw('user_id, course_id, MAX(expected_paid_coins) as expected_total')
            ->get()->keyBy(fn ($row) => ((int) $row->user_id).':'.((int) $row->course_id));

        return $enrollments->mapWithKeys(function (CourseEnrollment $enrollment) use ($paid, $openExpected): array {
            $key = ((int) $enrollment->user_id).':'.((int) $enrollment->course_id);
            $expected = max(0, (int) (($this->plans->termsForEnrollment($enrollment)['minimum_paid_coins'] ?? 0)));
            $actual = max(0, (int) ($paid->get($key)?->paid_total ?? 0));
            $unresolvedExpected = max(0, (int) ($openExpected->get($key)?->expected_total ?? 0));
            return [(int) $enrollment->id => $actual >= $expected && $actual >= $unresolvedExpected];
        })->all();
    }

    public function allowsVariableCostFeaturesReadOnly(CourseEnrollment $enrollment): bool
    {
        return $this->variableCostDecisions(collect([$enrollment]))[(int) $enrollment->id] ?? false;
    }

    public function allowsVariableCostFeatures(CourseEnrollment $enrollment): bool
    {
        if (!Schema::hasTable('financial_anomalies')) {
            // Lightweight test/upgrade schemas may not have reached the new
            // ledger yet. ProductionPreflight blocks a real release in that
            // state, so runtime remains backward-compatible during rollout.
            Log::warning('Variable-cost entitlement checks are unavailable because the anomaly ledger is missing.', [
                'enrollment_id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
                'course_id' => $enrollment->course_id,
            ]);

            return true;
        }

        $terms = $this->plans->termsForEnrollment($enrollment);
        $expected = max(0, (int) ($terms['minimum_paid_coins'] ?? 0));
        $actual = $this->wallet->coursePaidContribution(
            (int) $enrollment->user_id,
            (int) $enrollment->course_id
        );
        if ($actual >= $expected) {
            FinancialAnomaly::query()
                ->where('user_id', $enrollment->user_id)
                ->where('course_id', $enrollment->course_id)
                ->where('status', FinancialAnomaly::STATUS_OPEN)
                ->where('expected_paid_coins', '<=', $actual)
                ->update([
                    'status' => FinancialAnomaly::STATUS_RESOLVED,
                    'actual_paid_coins' => $actual,
                    'resolved_at' => now(),
                    'resolution_note' => 'Auto-resolved after the immutable paid-coin ledger reached the entitlement floor.',
                    'updated_at' => now(),
                ]);

            return !FinancialAnomaly::query()
                ->where('user_id', $enrollment->user_id)
                ->where('course_id', $enrollment->course_id)
                ->where('status', FinancialAnomaly::STATUS_OPEN)
                ->exists();
        }

        $orderId = (int) ($enrollment->access_plan_order_id ?: $enrollment->order_id ?: 0);
        $wasNewAlert = false;
        $anomaly = DB::transaction(function () use (
            $enrollment,
            $terms,
            $expected,
            $actual,
            $orderId,
            &$wasNewAlert
        ): FinancialAnomaly {
            $query = FinancialAnomaly::query()
                ->where('type', FinancialAnomaly::TYPE_PAID_FLOOR_SHORTFALL);
            $orderId > 0
                ? $query->where('order_id', $orderId)
                : $query->where('enrollment_id', $enrollment->id)->whereNull('order_id');

            $anomaly = $query->lockForUpdate()->first();
            if (!$anomaly) {
                $anomaly = new FinancialAnomaly([
                    'public_id' => (string) Str::uuid(),
                    'order_id' => $orderId ?: null,
                    'type' => FinancialAnomaly::TYPE_PAID_FLOOR_SHORTFALL,
                    'detected_at' => now(),
                ]);
                $wasNewAlert = true;
            } elseif ($anomaly->status !== FinancialAnomaly::STATUS_OPEN) {
                $wasNewAlert = true;
                $anomaly->detected_at = now();
            }

            $anomaly->fill([
                'user_id' => $enrollment->user_id,
                'course_id' => $enrollment->course_id,
                'enrollment_id' => $enrollment->id,
                'status' => FinancialAnomaly::STATUS_OPEN,
                'expected_paid_coins' => $expected,
                'actual_paid_coins' => $actual,
                'metadata' => [
                    'plan_code' => $terms['code'] ?? null,
                    'source' => 'entitlement_paid_floor',
                ],
                'resolved_by' => null,
                'resolved_at' => null,
                'resolution_note' => null,
            ])->save();

            if ($wasNewAlert) {
                $occurrence = (string) $anomaly->detected_at?->format('Y-m-d\TH:i:s.uP');
                $this->internalSignals->record(
                    'financial_anomaly.opened',
                    'anomaly:' . $anomaly->public_id . ':opened:'
                        . $occurrence,
                    ['anomaly_id' => (int) $anomaly->id, 'occurrence' => $occurrence],
                    FinancialAnomaly::class,
                    (int) $anomaly->id
                );
            }

            return $anomaly;
        }, 3);

        if ($wasNewAlert) {
            Log::critical('Variable-cost entitlement blocked by a paid-coin shortfall', [
                'anomaly_id' => $anomaly->public_id,
                'user_id' => $enrollment->user_id,
                'course_id' => $enrollment->course_id,
                'expected_paid_coins' => $expected,
                'actual_paid_coins' => $actual,
            ]);
        }

        return false;
    }
}
