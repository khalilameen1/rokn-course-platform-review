<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\WalletCreditLot;
use App\Models\WalletDebitAllocation;
use App\Services\CommercialLearnerSummaryService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseCashAttributionService;
use App\Services\CourseCommercialReportService;
use App\Services\CourseCostReportService;
use App\Services\CourseFinancialLedgerReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CommercialAttributionBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            CourseCommercialReportService::class,
            CourseCostReportService::class,
            CourseAccessPlanService::class,
            CourseFinancialLedgerReportService::class,
        ] as $service) {
            $this->app->bind($service, static function () use ($service): never {
                throw new \LogicException('Attribution must not resolve ' . $service);
            });
        }
        DB::connection()->enableQueryLog();
    }

    #[DataProvider('cashEvidence')]
    public function test_cash_projection_preserves_source_evidence_without_querying_or_repricing(
        array $sourceOverrides,
        array $expected,
        string $channel,
    ): void {
        $source = $this->source($sourceOverrides);
        $allocation = $this->allocation($source, 250, 1000);
        $originalSource = $source->getAttributes();
        $originalAllocation = $allocation->getAttributes();
        $report = $this->cash(collect([$allocation]), 250);

        foreach ($expected as $key => $value) {
            self::assertSame($value, $report[$key], $key);
        }
        self::assertSame(250, $report['cash_channels'][$channel]['paid_coins']);
        self::assertSame($originalSource, $source->getAttributes());
        self::assertSame($originalAllocation, $allocation->getAttributes());
        self::assertSame([], DB::getQueryLog());
    }

    public static function cashEvidence(): array
    {
        return [
            'actual net outranks fee' => [[], [
                'cash_gross_egp' => 25.0, 'cash_net_known_egp' => 22.5,
                'cash_gross_complete' => true, 'cash_net_complete' => true,
            ], Order::PAYMENT_METHOD_KASHIER],
            'fee-backed net' => [['gateway_net_amount' => null, 'gateway_fee_amount' => 5], [
                'cash_net_known_egp' => 23.75, 'cash_net_complete' => true,
            ], Order::PAYMENT_METHOD_KASHIER],
            'pending settlement' => [['gateway_net_amount' => null, 'gateway_fee_amount' => null], [
                'cash_net_known_egp' => 0.0, 'cash_pending_settlement_egp' => 25.0,
                'cash_net_complete' => false,
            ], Order::PAYMENT_METHOD_KASHIER],
            'explicit zero net' => [['gateway_net_amount' => 0], [
                'cash_net_known_egp' => 0.0, 'cash_net_complete' => true,
            ], Order::PAYMENT_METHOD_KASHIER],
            'catalogue estimate is not gross cash' => [['gateway_settlement_status' => 'catalog_estimate'], [
                'cash_gross_egp' => 0.0, 'cash_estimated_gross_egp' => 25.0,
                'cash_gross_complete' => false,
            ], Order::PAYMENT_METHOD_KASHIER],
            'foreign currency remains separate' => [['gateway_currency' => 'USD', 'gateway_gross_amount' => 12], [
                'cash_gross_egp' => 0.0, 'cash_net_known_egp' => 0.0,
                'cash_gross_complete' => false, 'cash_net_complete' => false,
                'cash_foreign_currency_amounts' => ['USD' => 3.0],
            ], Order::PAYMENT_METHOD_KASHIER],
            'store currency not supplied' => [[
                'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY,
                'gateway_currency' => null, 'gateway_gross_amount' => null,
                'final_amount' => 999,
            ], [
                'cash_gross_egp' => 0.0, 'cash_estimated_gross_egp' => 0.0,
                'cash_net_known_egp' => 0.0, 'cash_gross_complete' => false,
                'cash_net_complete' => false,
            ], Order::PAYMENT_METHOD_GOOGLE_PLAY],
            'reversed funding source' => [['financial_status' => Order::FINANCIAL_REVERSED], [
                'cash_gross_egp' => 0.0, 'cash_net_known_egp' => 0.0,
                'cash_gross_complete' => false, 'cash_net_complete' => false,
            ], 'unreconciled'],
            'test purchase is not revenue' => [[
                'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY,
                'gateway_settlement_status' => 'test_purchase',
            ], [
                'cash_gross_egp' => 0.0, 'cash_net_known_egp' => 0.0,
                'cash_gross_complete' => true, 'cash_net_complete' => true,
            ], Order::PAYMENT_METHOD_GOOGLE_PLAY . '_test'],
        ];
    }

    public function test_compensation_is_zero_new_cash_but_an_unproven_source_is_not(): void
    {
        $proven = $this->allocation(null, 50, 50, ['provenance_type' => 'course_service_compensation']);
        $compensation = $this->cash(collect([$proven]), 50);
        self::assertTrue($compensation['cash_net_complete']);
        self::assertSame(0.0, $compensation['cash_gross_egp']);
        self::assertSame(50, $compensation['allocated_paid_coins']);
        self::assertSame(50, $compensation['cash_channels']['service_compensation']['paid_coins']);

        $unproven = $this->cash(collect([$this->allocation(null, 50, 50)]), 50);
        self::assertFalse($unproven['cash_net_complete']);
        self::assertSame(0, $unproven['allocated_paid_coins']);
        self::assertSame(50, $unproven['cash_channels']['unreconciled']['paid_coins']);
    }

    public function test_reward_only_spend_and_missing_ledger_evidence_are_distinct(): void
    {
        $rewardOnly = $this->cash(collect(), 0);
        self::assertTrue($rewardOnly['cash_net_complete']);
        self::assertSame(0.0, $rewardOnly['cash_gross_egp']);
        self::assertSame([], $rewardOnly['cash_channels']);

        $missingLedger = $this->cash(collect(), 0, false);
        self::assertFalse($missingLedger['cash_net_complete']);
        self::assertSame(0.0, $missingLedger['cash_net_known_egp']);
    }

    public function test_rounding_happens_after_summing_allocations_not_per_fragment(): void
    {
        $source = $this->source(['gateway_gross_amount' => 1.01, 'gateway_net_amount' => 0.93]);
        $report = $this->cash(collect([
            $this->allocation($source, 1, 3),
            $this->allocation($source, 1, 3),
        ]), 2);
        self::assertSame(0.67, $report['cash_gross_egp']);
        self::assertSame(0.62, $report['cash_net_known_egp']);
        self::assertSame(0.67, $report['cash_channels'][Order::PAYMENT_METHOD_KASHIER]['gross_egp']);
    }

    public function test_learner_summary_is_independent_of_the_report_and_counts_people_separately_from_enrollments(): void
    {
        $summary = app(CommercialLearnerSummaryService::class)->forRows(collect([
            $this->row(), $this->row(['is_active' => false]),
        ]));
        self::assertSame(1, $summary['students']);
        self::assertSame(1, $summary['active_students']);
        self::assertSame(2, $summary['enrollments']);
        self::assertSame(180.0, $summary['net_egp']);
        self::assertSame(140.0, $summary['margin_egp']);
        self::assertSame(180.0, $summary['average_net_per_student_egp']);
        self::assertSame(90.0, $summary['average_net_per_enrollment_egp']);
        self::assertSame([], DB::getQueryLog());
    }

    public function test_unknown_cost_or_net_does_not_become_a_zero_margin(): void
    {
        $summaries = app(CommercialLearnerSummaryService::class);
        $unknownCost = $summaries->forRows(collect([$this->row([
            'service_cost_complete' => false, 'service_cost_actual_egp' => null,
            'service_cost_with_estimates_egp' => null,
        ])]));
        self::assertNull($unknownCost['service_cost_egp']);
        self::assertNull($unknownCost['margin_egp']);
        self::assertNull($unknownCost['estimated_margin_egp']);
        self::assertNull($unknownCost['cost_to_net_revenue_percentage']);

        $unknownNet = $summaries->forRows(collect([$this->row(['cash_net_complete' => false])]));
        self::assertNull($unknownNet['net_egp']);
        self::assertNull($unknownNet['margin_egp']);
        self::assertNull($unknownNet['average_net_per_student_egp']);
    }

    public function test_percentage_denominators_require_positive_known_net(): void
    {
        $summaries = app(CommercialLearnerSummaryService::class);
        foreach ([null, 0.0, -1.0] as $net) {
            self::assertSame([
                'cost_to_net_revenue_percentage' => null,
                'contribution_margin_percentage' => null,
            ], $summaries->unitEconomics($net, 20.0, 70.0));
        }
        self::assertSame([
            'cost_to_net_revenue_percentage' => 20.0,
            'contribution_margin_percentage' => 80.0,
        ], $summaries->unitEconomics(100.0, 20.0, 80.0));
    }

    private function source(array $overrides = []): Order
    {
        return (new Order())->forceFill(array_replace([
            'id' => 99, 'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'gateway_currency' => 'EGP', 'gateway_gross_amount' => 100,
            'gateway_net_amount' => 90, 'gateway_fee_amount' => 3,
            'gateway_settlement_status' => 'settled', 'final_amount' => 100,
        ], $overrides));
    }

    private function allocation(?Order $source, int $coins, int $lotCoins, array $metadata = []): WalletDebitAllocation
    {
        $lot = (new WalletCreditLot())->forceFill(['original_amount' => $lotCoins, 'metadata' => $metadata]);
        $lot->setRelation('sourceOrder', $source);
        return (new WalletDebitAllocation())->forceFill(['amount' => $coins])->setRelation('creditLot', $lot);
    }

    private function cash(Collection $allocations, int $paidCoins, bool $complete = true): array
    {
        // Mutable order counters deliberately disagree: attribution consumes
        // already-validated wallet evidence, never those cached price fields.
        $order = (new Order())->forceFill(['id' => 1, 'paid_coins' => 9999, 'total_coins' => 9999]);
        return app(CourseCashAttributionService::class)->forOrders(
            collect([$order]),
            collect([1 => $allocations]),
            collect([1 => ['total_coins' => $paidCoins + 20, 'paid_coins' => $paidCoins,
                'reward_coins' => 20, 'complete' => $complete]]),
        );
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'enrollment' => (object) ['user_id' => 1], 'user' => null, 'is_active' => true,
            'cash_net_complete' => true, 'cash_net_known_egp' => 90.0,
            'cash_gross_egp' => 100.0, 'coin_allocation_complete' => true,
            'total_coins' => 120, 'discount_coins' => 0,
            'service_cost_complete' => true, 'service_cost_actual_egp' => 20.0,
            'service_cost_with_estimates_egp' => 20.0, 'estimated_contribution_margin_egp' => 70.0,
            'ai_requests' => 1, 'ai_failed_requests' => 0, 'ai_unanswered_requests' => 0,
            'ai_estimated_requests' => 0, 'ai_measurement_available' => true,
            'ai_cost_usd' => 0.01, 'ai_tokens' => 100,
            'playback_minutes' => 1.0, 'playback_gb_estimated' => 0.0,
        ], $overrides);
    }
}
