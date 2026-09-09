<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiUsageReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiUsageReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature'); $table->string('status'); $table->integer('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable(); $table->decimal('cost_egp', 12, 6)->nullable();
            $table->json('metadata')->nullable(); $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_usage_events');
        parent::tearDown();
    }

    public function test_provider_amounts_only_and_failed_billed_calls_are_not_lost(): void
    {
        $this->event('provider', 0.125, 6.25);
        $this->event('reservation_fallback', 7, 350);
        $this->event('provider', 0.025, 1.25, ['status' => 'failed']);
        $report = app(AiUsageReportService::class)->summary(ReportPeriod::fromKey());
        self::assertSame(0.15, $report['cost_usd']);
        self::assertSame(1, $report['estimated_cost_requests']);
        self::assertFalse($report['cost_complete']);
        self::assertNull($report['cost_egp']);
        self::assertSame(2, $report['completed_requests']);
        self::assertSame(1, $report['failed_requests']);
    }

    public function test_zero_receipt_is_known_but_missing_cost_is_not_zero(): void
    {
        $this->event('provider', 0, null);
        $this->event('cache_zero_cost', 0, null);
        $report = app(AiUsageReportService::class)->summary(ReportPeriod::fromKey());
        self::assertTrue($report['cost_complete']);
        self::assertSame(0.0, $report['cost_egp']);
        $this->event('cache_zero_cost', null, null);
        $this->event('provider', null, null);
        $report = app(AiUsageReportService::class)->summary(ReportPeriod::fromKey());
        self::assertSame(2, $report['estimated_cost_requests']);
        self::assertFalse($report['cost_complete']);
    }

    public function test_no_current_fx_or_reservation_is_substituted_for_a_missing_historical_fact(): void
    {
        config(['openrouter.usd_to_egp_rate' => 999]);
        $this->event('provider', 0.1, null);
        $report = app(AiUsageReportService::class)->summary(ReportPeriod::fromKey());
        self::assertSame(0.1, $report['cost_usd']);
        self::assertTrue($report['cost_complete']);
        self::assertNull($report['cost_egp']);
    }

    public function test_course_and_user_scope_and_exact_time_boundaries_match(): void
    {
        $period = ReportPeriod::fromKey('7d', CarbonImmutable::parse('2026-09-09 12:00:00Z'));
        $this->event('provider', 0.1, 5, ['created_at' => $period->start]);
        $this->event('provider', 9, 450, ['created_at' => $period->end]);
        $this->event('provider', 8, 400, ['course_id' => 2, 'created_at' => $period->start]);
        $this->event('provider', 0.2, 10, ['user_id' => null, 'created_at' => $period->start]);
        $service = app(AiUsageReportService::class);
        self::assertSame(0.3, $service->summary($period, 1)['cost_usd']);
        self::assertSame(8.3, $service->summary($period)['cost_usd']);
        self::assertSame(0.1, $service->byUser($period, 1, collect([1]))->get(1)['cost_usd']);
        self::assertSame(0.1, $service->byFeature($period, 1, collect([1]))->first()['cost_usd']);
    }

    public function test_empty_database_is_known_zero_but_delivery_failure_still_has_a_charge(): void
    {
        $report = app(AiUsageReportService::class)->summary(ReportPeriod::fromKey());
        self::assertSame(0.0, $report['cost_usd']);
        self::assertTrue($report['cost_complete']);
        $this->event('provider', 0.05, 2.5, ['metadata' => json_encode([
            'cost_usage_source' => 'provider', 'entitlement_delivered' => false,
        ])]);
        $report = app(AiUsageReportService::class)->summary(ReportPeriod::fromKey());
        self::assertSame(0, $report['completed_requests']);
        self::assertSame(1, $report['unanswered_requests']);
        self::assertSame(0.05, $report['cost_usd']);
    }

    private function event(string $source, ?float $usd, ?float $egp, array $overrides = []): void
    {
        DB::table('ai_usage_events')->insert($overrides + [
            'course_id' => 1, 'user_id' => 1, 'feature' => 'course_chat', 'status' => 'completed',
            'total_tokens' => 100, 'cost_usd' => $usd, 'cost_egp' => $egp,
            'metadata' => json_encode(['cost_usage_source' => $source]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
