<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProviderInvoiceReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProviderInvoiceReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Africa/Cairo']);
        Schema::create('operating_cost_pools', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('course_id')->nullable();
            $table->string('service_key'); $table->boolean('is_final');
            $table->date('period_start'); $table->date('period_end');
            $table->decimal('amount', 12, 4); $table->string('currency');
            $table->decimal('fx_rate_to_egp', 12, 4)->nullable();
            $table->timestamps(); $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('operating_cost_pools');
        parent::tearDown();
    }

    public function test_only_final_invoices_are_counted_once_without_course_allocation(): void
    {
        $this->invoice(100);
        $this->invoice(20, ['course_id' => 3]);
        $this->invoice(900, ['is_final' => false]);
        $this->invoice(800, ['deleted_at' => now()]);
        $service = app(ProviderInvoiceReportService::class);
        $platform = $service->summary(ReportPeriod::fromKey());
        self::assertSame(120.0, $platform['known_total_egp']);
        $course = $service->summary(ReportPeriod::fromKey(), 3);
        self::assertSame(20.0, $course['known_total_egp']);
        self::assertTrue($course['shared_costs']);
        self::assertSame(20.0, $course['services']->firstWhere('key', 'infrastructure')['actual_egp']);
    }

    public function test_missing_invoice_and_fx_are_unknown_but_zero_invoice_is_a_fact(): void
    {
        $service = app(ProviderInvoiceReportService::class);
        $empty = $service->summary(ReportPeriod::fromKey());
        self::assertFalse($empty['has_invoices']);
        self::assertNull($empty['services']->firstWhere('key', 'infrastructure')['actual_egp']);
        $this->invoice(0);
        self::assertSame(0.0, $service->summary(ReportPeriod::fromKey())['services']->firstWhere('key', 'infrastructure')['actual_egp']);
        $this->invoice(10, ['currency' => 'USD']);
        $incomplete = $service->summary(ReportPeriod::fromKey());
        self::assertSame(1, $incomplete['missing_fx']);
        self::assertNull($incomplete['services']->firstWhere('key', 'infrastructure')['actual_egp']);
    }

    public function test_invoice_is_never_prorated_or_duplicated_at_period_boundaries(): void
    {
        $period = ReportPeriod::fromKey('7d', CarbonImmutable::parse('2026-09-09 12:00:00Z'));
        $this->invoice(70, ['period_start' => '2026-08-01', 'period_end' => '2026-09-03']);
        $this->invoice(30, ['period_end' => '2026-09-02']);
        $this->invoice(1000, ['period_end' => '2026-09-10']);
        $service = app(ProviderInvoiceReportService::class);
        self::assertSame(70.0, $service->summary($period)['known_total_egp']);
        self::assertSame(30.0, $service->summary($period->previous())['known_total_egp']);
    }

    private function invoice(float $amount, array $overrides = []): void
    {
        DB::table('operating_cost_pools')->insert($overrides + [
            'course_id' => null, 'service_key' => 'infrastructure', 'is_final' => true,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-08',
            'amount' => $amount, 'currency' => 'EGP', 'fx_rate_to_egp' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
