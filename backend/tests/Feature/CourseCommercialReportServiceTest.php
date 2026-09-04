<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Services\CourseCommercialReportService;
use App\Services\PlatformCommercialReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseCommercialReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'student_notifications',
            'wallet_debit_allocations', 'wallet_credit_lots', 'ai_usage_events',
            'wallet_transactions',
            'operating_cost_pools', 'course_enrollments',
            'playback_sessions', 'course_sections',
            'orders', 'course_codes', 'courses', 'users', 'settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_it_separates_grants_rewards_gross_cash_and_gateway_net(): void
    {
        $now = now();
        DB::table('users')->insert([
            ['id' => 1, 'name_ar' => 'طالب مشترٍ', 'email' => 'paid@example.test', 'password' => 'x', 'role' => 'client', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name_ar' => 'طالب منحة', 'email' => 'grant@example.test', 'password' => 'x', 'role' => 'client', 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('courses')->insert(['id' => 10, 'name_ar' => 'كورس', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('course_codes')->insert(['id' => 8, 'code' => 'GRANT', 'is_grant' => 1, 'created_at' => $now, 'updated_at' => $now]);

        DB::table('orders')->insert([
            [
                'id' => 100, 'user_id' => 1, 'course_id' => null, 'package_id' => 1, 'course_code_id' => null, 'wallet_transaction_id' => null,
                'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'amount' => 100,
                'discount_amount' => 0, 'final_amount' => 100, 'gateway_gross_amount' => 100,
                'gateway_fee_amount' => 3, 'gateway_net_amount' => 97, 'gateway_currency' => 'EGP',
                'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
                'total_coins' => 0, 'paid_coins' => 0, 'reward_coins' => 0,
                'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => 101, 'user_id' => 1, 'course_id' => 10, 'package_id' => null, 'course_code_id' => null, 'wallet_transaction_id' => 501,
                'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 70,
                'discount_amount' => 0, 'final_amount' => 70, 'gateway_gross_amount' => null,
                'gateway_fee_amount' => null, 'gateway_net_amount' => null, 'gateway_currency' => null,
                'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
                'total_coins' => 70, 'paid_coins' => 50, 'reward_coins' => 20,
                'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => 102, 'user_id' => 2, 'course_id' => 10, 'package_id' => null, 'wallet_transaction_id' => null,
                'course_code_id' => 8, 'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
                'amount' => 0, 'discount_amount' => 0, 'final_amount' => 0,
                'gateway_gross_amount' => null, 'gateway_fee_amount' => null,
                'gateway_net_amount' => null, 'gateway_currency' => null,
                'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
                'total_coins' => 0, 'paid_coins' => 0, 'reward_coins' => 0,
                'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        DB::table('course_enrollments')->insert([
            ['id' => 201, 'user_id' => 1, 'course_id' => 10, 'order_id' => 101, 'access_plan_order_id' => 101, 'is_active' => 1, 'enrolled_at' => $now, 'access_granted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 202, 'user_id' => 2, 'course_id' => 10, 'order_id' => 102, 'access_plan_order_id' => null, 'is_active' => 1, 'enrolled_at' => $now, 'access_granted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('wallet_transactions')->insert([
            'id' => 501,
            'public_id' => '33333333-3333-4333-8333-333333333333',
            'user_id' => 1,
            'direction' => 'debit',
            'category' => 'course_purchase',
            'bucket' => 'mixed',
            'amount' => 70,
            'paid_amount' => 50,
            'reward_amount' => 20,
            'balance_after' => 30,
            'paid_balance_after' => 50,
            'reward_balance_after' => 0,
            'source_type' => Course::class,
            'source_id' => 10,
            'idempotency_key' => 'course-test-101',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('wallet_credit_lots')->insert([
            'id' => 301, 'user_id' => 1, 'source_order_id' => 100,
            'original_amount' => 100, 'remaining_amount' => 50,
            'credited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('wallet_debit_allocations')->insert([
            'id' => 401, 'credit_lot_id' => 301, 'course_order_id' => 101,
            'amount' => 50, 'allocated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('ai_usage_events')->insert([
            [
                'request_id' => '11111111-1111-4111-8111-111111111111',
                'user_id' => 1, 'course_id' => 10, 'feature' => 'course_chat', 'status' => 'completed',
                'total_tokens' => 500, 'cost_usd' => 0.2, 'fx_rate_to_egp' => 50, 'cost_egp' => 10,
                'metadata' => json_encode(['cost_usage_source' => 'provider']),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'request_id' => '22222222-2222-4222-8222-222222222222',
                'user_id' => 1, 'course_id' => 10, 'feature' => 'course_chat', 'status' => 'failed',
                'total_tokens' => 0, 'cost_usd' => 0, 'fx_rate_to_egp' => 50, 'cost_egp' => 0,
                'metadata' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        DB::table('student_notifications')->insert([
            [
                'user_id' => 1, 'is_read' => 1,
                'push_attempted_at' => $now, 'push_sent_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'user_id' => 1, 'is_read' => 0,
                'push_attempted_at' => null, 'push_sent_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'user_id' => 2, 'is_read' => 0,
                'push_attempted_at' => $now, 'push_sent_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        DB::table('operating_cost_pools')->insert([
            [
                'name' => 'سيرفر أغسطس', 'service_key' => 'infrastructure',
                'course_id' => 10, 'period_start' => $now->copy()->subDay()->toDateString(),
                'period_end' => $now->copy()->addDay()->toDateString(),
                'amount' => 20, 'currency' => 'EGP', 'fx_rate_to_egp' => null,
                'allocation_driver' => 'active_students', 'is_final' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'تقدير رسائل أغسطس', 'service_key' => 'notifications',
                'course_id' => 10, 'period_start' => $now->copy()->subDay()->toDateString(),
                'period_end' => $now->copy()->addDay()->toDateString(),
                'amount' => 10, 'currency' => 'EGP', 'fx_rate_to_egp' => null,
                'allocation_driver' => 'active_students', 'is_final' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $report = app(CourseCommercialReportService::class)->forCourse(Course::findOrFail(10));

        self::assertSame(2, $report['active_students']);
        self::assertSame(1, $report['grant_students']);
        self::assertSame(50, $report['paid_coins']);
        self::assertSame(20, $report['reward_coins']);
        self::assertSame(50.0, $report['cash_gross_egp']);
        self::assertSame(48.5, $report['cash_net_egp']);
        self::assertTrue($report['cash_net_complete']);
        self::assertSame(0.2, $report['ai_cost_usd']);
        self::assertSame(1, $report['rows']->firstWhere('user.id', 1)['ai_failed_requests']);
        self::assertSame(30.0, $report['service_cost_actual_egp']);
        self::assertSame(40.0, $report['service_cost_with_estimates_egp']);
        self::assertSame(18.5, $report['contribution_margin_egp']);
        self::assertSame(8.5, $report['estimated_contribution_margin_egp']);
        self::assertSame(61.86, $report['cost_to_net_revenue_percentage']);
        self::assertSame(38.14, $report['contribution_margin_percentage']);
        self::assertSame(
            10.0,
            $report['service_breakdown']->firstWhere('key', 'openrouter')['actual_egp']
        );
        self::assertSame(
            20.0,
            $report['service_breakdown']->firstWhere('key', 'infrastructure')['actual_egp']
        );
        self::assertSame(
            10.0,
            $report['service_breakdown']->firstWhere('key', 'notifications')['with_estimates_egp']
        );
        self::assertSame('منحة', $report['rows']->firstWhere('source', 'grant')['source_label']);
        $plan = $report['plan_breakdown']->firstWhere('plan_name', 'إتاحة قديمة');
        self::assertSame(10.0, $plan['service_breakdown_actual_egp']['openrouter']);
        self::assertSame(20.0, $plan['service_breakdown_actual_egp']['infrastructure']);

        $platform = app(PlatformCommercialReportService::class)->report();
        self::assertSame(2, $platform['unique_students']);
        self::assertSame(2, $platform['enrollments']);
        self::assertSame(30.0, $platform['service_cost_egp']);
        self::assertSame(15.0, $platform['average_cost_per_student_egp']);
        self::assertSame(1, $platform['ai_failed_requests']);
        self::assertSame(50.0, $platform['ai_failure_rate_percentage']);
        self::assertSame(3, $platform['in_app_notifications']);
        self::assertSame(1, $platform['read_notifications']);
        self::assertSame(2, $platform['push_attempts']);
        self::assertSame(1, $platform['push_provider_accepted']);
        self::assertSame(50.0, $platform['push_provider_acceptance_rate_percentage']);
        self::assertSame(2, $platform['student_rows']->firstWhere('user.id', 1)['in_app_notifications']);
        self::assertCount(2, $platform['student_rows']);
    }

    public function test_platform_report_allocates_shared_cost_once_across_courses(): void
    {
        $now = now();
        DB::table('users')->insert([
            'id' => 1, 'name_ar' => 'طالب متعدد الكورسات', 'email' => 'multi@example.test',
            'password' => 'x', 'role' => 'client', 'active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('courses')->insert([
            ['id' => 10, 'name_ar' => 'الأول', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'name_ar' => 'الثاني', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('course_enrollments')->insert([
            ['id' => 1, 'user_id' => 1, 'course_id' => 10, 'is_active' => 1, 'enrolled_at' => $now, 'access_granted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'user_id' => 1, 'course_id' => 11, 'is_active' => 1, 'enrolled_at' => $now, 'access_granted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('student_notifications')->insert([
            'user_id' => 1, 'is_read' => 0, 'push_attempted_at' => $now,
            'push_sent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('operating_cost_pools')->insert([
            'name' => 'سيرفر مشترك', 'service_key' => 'infrastructure', 'course_id' => null,
            'period_start' => $now->copy()->subDay()->toDateString(),
            'period_end' => $now->copy()->addDay()->toDateString(),
            'amount' => 100, 'currency' => 'EGP', 'fx_rate_to_egp' => null,
            'allocation_driver' => 'active_students', 'is_final' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $report = app(PlatformCommercialReportService::class)->report();

        self::assertSame(1, $report['unique_students']);
        self::assertSame(2, $report['enrollments']);
        self::assertSame(100.0, $report['service_cost_egp']);
        self::assertSame(100.0, $report['average_cost_per_student_egp']);
        self::assertSame(1, $report['push_attempts']);
        self::assertSame(1, $report['push_provider_accepted']);
        // The collection is keyed by the stable plan code so filters and
        // exports do not depend on a translated label. Legacy enrollments have
        // no code; locate their learner-facing fallback name explicitly.
        $legacyPlan = $report['plan_breakdown']->firstWhere('plan_name', 'إتاحة قديمة');
        self::assertSame(1, $legacyPlan['students']);
        self::assertSame(2, $legacyPlan['enrollments']);
        self::assertSame(100.0, $legacyPlan['average_cost_per_student_egp']);
        self::assertSame(50.0, $legacyPlan['average_cost_per_enrollment_egp']);
        $purchaseSource = $report['source_breakdown']->get('شراء');
        self::assertSame(1, $purchaseSource['students']);
        self::assertSame(2, $purchaseSource['enrollments']);
        self::assertSame(
            100.0,
            $report['service_breakdown']->firstWhere('key', 'infrastructure')['actual_egp']
        );
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
