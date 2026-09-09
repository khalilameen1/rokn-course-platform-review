<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PlatformCommercialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PlatformOperatingCostPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-09 12:00:00 UTC');
        $this->createSchema();
        Schema::create('course_authoring_revisions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('canonical_course_id'); $table->unsignedBigInteger('revision_course_id');
        });
        Schema::table('courses', fn (Blueprint $table) => $table->string('name_en')->nullable());
        DB::table('courses')->insert(['id' => 10, 'name_ar' => 'Course', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_platform_uses_provider_costs_and_invoices_once_not_student_allocations(): void
    {
        $this->invoice(100, null);
        $this->invoice(20, 10);
        $this->usage(1, 2, 'provider');
        $this->usage(2, 9, 'estimate');
        $report = app(PlatformCommercialReportService::class)->report(['period' => '7d']);
        self::assertSame(2.0, $report['ai_cost_usd']);
        self::assertSame(1, $report['ai_estimated_requests']);
        self::assertSame(120.0, $report['provider_invoice_report']['known_total_egp']);
        self::assertSame(120.0, $report['service_breakdown']->firstWhere('key', 'infrastructure')['actual_egp']);
        self::assertNull($report['service_cost_egp']);
        self::assertNull($report['average_cost_per_student_egp']);
        self::assertSame('7d', $report['period']->key);
        self::assertSame('unavailable', $report['comparisons']['ai_cost_usd']['status']);
        self::assertNull($report['ai_cost_per_1000_tokens_usd']);
        self::assertSame(10, $report['course_breakdown']->first()['course_id']);
    }

    public function test_unsuccessful_request_rate_is_the_same_for_platform_and_student(): void
    {
        DB::table('users')->insert(['id' => 1, 'name_ar' => 'Student', 'email' => 'student@example.test', 'password' => 'x', 'role' => 'client']);
        DB::table('course_enrollments')->insert(['id' => 1, 'user_id' => 1, 'course_id' => 10,
            'is_active' => true, 'enrolled_at' => '2026-01-01', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        $this->usage(1, 1, 'provider');
        $this->usage(2, 2, 'provider');
        $this->usage(3, 3, 'provider');
        DB::table('ai_usage_events')->where('id', 2)->update([
            'metadata' => json_encode(['cost_usage_source' => 'provider', 'entitlement_delivered' => false]),
        ]);
        DB::table('ai_usage_events')->where('id', 3)->update(['status' => 'failed']);

        $report = app(PlatformCommercialReportService::class)->report(['period' => '7d']);
        self::assertSame(1, $report['ai_requests']);
        self::assertSame(1, $report['ai_failed_requests']);
        self::assertSame(1, $report['ai_unanswered_requests']);
        self::assertSame(66.67, $report['ai_failure_rate_percentage']);
        self::assertNull($report['ai_cost_per_1000_tokens_usd']);
        self::assertSame($report['ai_failure_rate_percentage'], $report['student_rows']->first()['ai_failure_rate_percentage']);
    }

    public function test_course_scope_does_not_adopt_shared_invoice_and_cohort_never_allocates_it(): void
    {
        $this->invoice(100, null);
        $this->invoice(20, 10);
        $service = app(PlatformCommercialReportService::class);
        $course = $service->report(['course_id' => 10]);
        self::assertSame(20.0, $course['provider_invoice_report']['known_total_egp']);
        self::assertTrue($course['provider_invoice_report']['shared_costs']);
        self::assertNull($course['service_cost_egp']);
        $cohort = $service->report(['course_id' => 10, 'plan' => 'mentor']);
        self::assertNull($cohort['provider_invoice_report']);
        self::assertNull($cohort['service_cost_egp']);
        self::assertNull($cohort['service_breakdown']->firstWhere('key', 'infrastructure')['actual_egp']);
    }

    public function test_previous_period_uses_its_own_events_and_whole_invoice_not_prorating(): void
    {
        $this->usage(1, 2, 'provider');
        $this->usage(2, 1, 'provider', '2026-08-30 12:00:00');
        $this->invoice(70, null);
        $this->invoice(30, null, '2026-08-30');
        $report = app(PlatformCommercialReportService::class)->report(['period' => '7d']);
        self::assertSame(2.0, $report['ai_cost_usd']);
        self::assertSame(100.0, $report['comparisons']['ai_cost_usd']['percentage']);
        self::assertSame(70.0, $report['provider_invoice_report']['known_total_egp']);
        self::assertNull(app(PlatformCommercialReportService::class)->report()['comparisons']['ai_cost_usd']['percentage']);
    }

    public function test_notification_timestamps_are_independent_of_old_enrollment_and_creation(): void
    {
        DB::table('users')->insert(['id' => 1, 'name_ar' => 'Student', 'email' => 'student@example.test', 'password' => 'x', 'role' => 'client']);
        DB::table('course_enrollments')->insert(['id' => 1, 'user_id' => 1, 'course_id' => 10,
            'is_active' => true, 'enrolled_at' => '2026-01-01', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        DB::table('student_notifications')->insert(['user_id' => 1, 'is_read' => true,
            'created_at' => '2026-08-01', 'updated_at' => '2026-09-08',
            'push_attempted_at' => '2026-09-08', 'push_sent_at' => '2026-09-08']);
        $report = app(PlatformCommercialReportService::class)->report(['period' => '7d']);
        self::assertSame(0, $report['in_app_notifications']);
        self::assertSame(1, $report['push_attempts']);
        self::assertSame(1, $report['push_provider_accepted']);
        self::assertSame(1, $report['unique_students']);
        self::assertNull($report['student_rows']->first()['service_cost_egp']);
    }

    public function test_controller_preserves_valid_period_in_pagination_and_exports_no_estimated_money(): void
    {
        $request = \Illuminate\Http\Request::create('/dashboard/operating-costs/report', 'GET', ['period' => '7d', 'course_id' => 10]);
        $controller = app(\App\Http\Controllers\Admin\OperatingCostPoolController::class);
        $service = app(PlatformCommercialReportService::class);
        $data = $controller->report($request, $service)->getData();
        self::assertSame('7d', $data['report']['period']->key);
        self::assertStringContainsString('period=7d', $data['students']->url(2));
        self::assertStringContainsString('course_id=10', $data['students']->url(2));
        ob_start();
        $controller->exportReport($request, $service)->sendContent();
        $csv = ob_get_clean();
        self::assertStringContainsString('تكلفة OpenRouter المؤكدة USD', $csv);
        self::assertStringNotContainsString('تقدير', $csv);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->report(\Illuminate\Http\Request::create('/report', 'GET', ['period' => 'future']), $service);
    }

    public function test_course_rows_exclude_authoring_copies_and_empty_drafts_without_reading_a_third_window(): void
    {
        DB::table('courses')->insert([
            ['id' => 11, 'name_ar' => 'Draft'], ['id' => 12, 'name_ar' => 'Empty'],
        ]);
        DB::table('course_authoring_revisions')->insert(['canonical_course_id' => 10, 'revision_course_id' => 11]);
        $this->invoice(10, 10);
        $this->invoice(15, 11);
        DB::enableQueryLog();
        $report = app(PlatformCommercialReportService::class)->report(['period' => '7d']);
        $bindings = collect(DB::getQueryLog())->flatMap(fn (array $query): array => $query['bindings'])
            ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);
        DB::disableQueryLog();
        self::assertSame([10], $report['course_breakdown']->pluck('course_id')->all());
        self::assertFalse($bindings->containsStrict('2026-08-19 12:00:00'));
        self::assertTrue($bindings->containsStrict('2026-08-26 12:00:00'));
    }

    private function usage(int $id, float $cost, string $source, string $at = '2026-09-08 12:00:00'): void
    {
        DB::table('ai_usage_events')->insert(['id' => $id, 'request_id' => 'request-'.$id, 'course_id' => 10,
            'user_id' => 1, 'feature' => 'course_chat', 'status' => 'completed', 'total_tokens' => 100,
            'cost_usd' => $cost, 'cost_egp' => $cost * 50, 'metadata' => json_encode(['cost_usage_source' => $source]),
            'created_at' => $at, 'updated_at' => $at]);
    }

    private function invoice(float $amount, ?int $course, string $end = '2026-09-08'): void
    {
        DB::table('operating_cost_pools')->insert(['name' => 'Invoice', 'service_key' => 'infrastructure',
            'course_id' => $course, 'period_start' => '2026-08-01', 'period_end' => $end,
            'amount' => $amount, 'currency' => 'EGP', 'allocation_driver' => 'enrollments', 'is_final' => true,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function createSchema(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id(); $table->decimal('openrouter_usd_to_egp_rate', 12, 4)->nullable(); $table->timestamps();
        });
        DB::table('settings')->insert(['openrouter_usd_to_egp_rate' => 50, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name_ar')->nullable(); $table->string('name_en')->nullable();
            $table->string('email')->unique(); $table->string('password'); $table->string('role');
            $table->boolean('active')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar'); $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
        });
        Schema::create('playback_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->timestamp('started_playing_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('buffer_duration_ms')->default(0);
            $table->unsignedInteger('effective_bitrate_kbps')->nullable();
            $table->string('effective_quality')->nullable();
        });
        Schema::create('course_codes', function (Blueprint $table): void {
            $table->id(); $table->string('code'); $table->boolean('is_grant')->default(false);
            $table->json('allowed_email_domains')->nullable(); $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable(); $table->unsignedBigInteger('course_code_id')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->unsignedBigInteger('access_plan_id')->nullable(); $table->json('access_plan_snapshot')->nullable();
            $table->string('payment_method'); $table->decimal('amount', 12, 2); $table->decimal('discount_amount', 12, 2);
            $table->decimal('final_amount', 12, 2); $table->decimal('gateway_gross_amount', 12, 2)->nullable();
            $table->decimal('gateway_fee_amount', 12, 2)->nullable(); $table->decimal('gateway_net_amount', 12, 2)->nullable();
            $table->string('gateway_currency', 3)->nullable(); $table->string('status'); $table->string('financial_status');
            $table->unsignedInteger('total_coins')->default(0); $table->unsignedInteger('paid_coins')->default(0);
            $table->unsignedInteger('reward_coins')->default(0); $table->timestamp('approved_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id(); $table->uuid('public_id')->unique(); $table->unsignedBigInteger('user_id');
            $table->string('direction'); $table->string('category'); $table->string('bucket');
            $table->unsignedInteger('amount'); $table->unsignedInteger('paid_amount');
            $table->unsignedInteger('reward_amount'); $table->integer('balance_after');
            $table->integer('paid_balance_after'); $table->integer('reward_balance_after');
            $table->nullableMorphs('source'); $table->string('idempotency_key');
            $table->timestamp('occurred_at'); $table->timestamps();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable(); $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->json('access_plan_snapshot')->nullable(); $table->unsignedBigInteger('access_plan_order_id')->nullable();
            $table->timestamp('enrolled_at')->nullable(); $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active'); $table->timestamp('access_granted_at')->nullable(); $table->timestamps();
        });
        Schema::create('wallet_credit_lots', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('source_order_id')->nullable();
            $table->unsignedInteger('original_amount'); $table->unsignedInteger('remaining_amount');
            $table->timestamp('credited_at'); $table->timestamps();
        });
        Schema::create('wallet_debit_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('credit_lot_id'); $table->unsignedBigInteger('course_order_id')->nullable();
            $table->unsignedInteger('amount'); $table->timestamp('allocated_at'); $table->timestamps();
        });
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id(); $table->uuid('request_id'); $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->unsignedBigInteger('course_id'); $table->string('feature'); $table->string('status');
            $table->unsignedInteger('total_tokens')->default(0); $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('fx_rate_to_egp', 12, 4)->nullable(); $table->decimal('cost_egp', 14, 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('student_notifications', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id');
            $table->boolean('is_read')->default(false);
            $table->timestamp('push_attempted_at')->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('operating_cost_pools', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('service_key');
            $table->unsignedBigInteger('course_id')->nullable(); $table->date('period_start'); $table->date('period_end');
            $table->decimal('amount', 14, 4); $table->string('currency', 3);
            $table->decimal('fx_rate_to_egp', 12, 4)->nullable(); $table->string('allocation_driver');
            $table->boolean('is_final'); $table->text('notes')->nullable(); $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
    }

}
