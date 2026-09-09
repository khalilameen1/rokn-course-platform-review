<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Services\CourseCommercialReportService;
use App\Services\CourseCostReportService;
use App\Services\PlatformCommercialReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
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
            'course_authoring_revisions', 'course_access_plans',
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
        // A measured AI charge and one infrastructure invoice are not evidence
        // that every paid service has been reconciled, nor a per-student bill.
        self::assertNull($report['service_cost_actual_egp']);
        self::assertNull($report['service_cost_with_estimates_egp']);
        self::assertNull($report['contribution_margin_egp']);
        self::assertNull($report['estimated_contribution_margin_egp']);
        self::assertNull($report['cost_to_net_revenue_percentage']);
        self::assertNull($report['contribution_margin_percentage']);
        self::assertSame(
            10.0,
            $report['service_breakdown']->firstWhere('key', 'openrouter')['actual_egp']
        );
        self::assertSame(
            20.0,
            $report['service_breakdown']->firstWhere('key', 'infrastructure')['actual_egp']
        );
        self::assertNull($report['service_breakdown']->firstWhere('key', 'notifications')['actual_egp']);
        self::assertSame('منحة', $report['rows']->firstWhere('source', 'grant')['source_label']);
        $plan = $report['plan_breakdown']->firstWhere('plan_name', 'إتاحة قديمة');
        self::assertSame(10.0, $plan['service_breakdown_actual_egp']['openrouter']);
        self::assertNull($plan['service_breakdown_actual_egp']['infrastructure']);

        $platform = app(PlatformCommercialReportService::class)->report();
        self::assertSame(2, $platform['unique_students']);
        self::assertSame(2, $platform['enrollments']);
        self::assertNull($platform['service_cost_egp']);
        self::assertNull($platform['average_cost_per_student_egp']);
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

    public function test_shared_invoice_is_not_misrepresented_as_per_student_or_course_cost(): void
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
        self::assertNull($report['service_cost_egp']);
        self::assertNull($report['average_cost_per_student_egp']);
        self::assertSame(1, $report['push_attempts']);
        self::assertSame(1, $report['push_provider_accepted']);
        // The collection is keyed by the stable plan code so filters and
        // exports do not depend on a translated label. Legacy enrollments have
        // no code; locate their learner-facing fallback name explicitly.
        $legacyPlan = $report['plan_breakdown']->firstWhere('plan_name', 'إتاحة قديمة');
        self::assertSame(1, $legacyPlan['students']);
        self::assertSame(2, $legacyPlan['enrollments']);
        self::assertNull($legacyPlan['average_cost_per_student_egp']);
        self::assertNull($legacyPlan['average_cost_per_enrollment_egp']);
        $purchaseSource = $report['source_breakdown']->get('شراء');
        self::assertSame(1, $purchaseSource['students']);
        self::assertSame(2, $purchaseSource['enrollments']);
        self::assertSame(100.0, $report['service_breakdown']->firstWhere('key', 'infrastructure')['actual_egp']);
    }

    public function test_platform_funded_project_review_is_visible_in_course_ai_costs(): void
    {
        $now = now();
        DB::table('users')->insert([
            'id' => 1,
            'name_ar' => 'طالب',
            'email' => 'review@example.test',
            'password' => 'x',
            'role' => 'client',
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('courses')->insert([
            'id' => 10,
            'name_ar' => 'كورس',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('ai_usage_events')->insert([
            'request_id' => '33333333-3333-4333-8333-333333333333',
            'user_id' => 1,
            'course_id' => 10,
            'feature' => 'project_review',
            'status' => 'completed',
            'total_tokens' => 250,
            'cost_usd' => 0.1,
            'fx_rate_to_egp' => 50,
            'cost_egp' => 5,
            'metadata' => json_encode([
                'cost_usage_source' => 'provider',
                'funding_source' => 'platform',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $report = app(CourseCostReportService::class)->forCourse(
            Course::query()->findOrFail(10),
            collect([1])
        );
        $learner = $report['users']->get(1);

        self::assertSame('مراجعة المشروع', CourseCostReportService::aiFeatureLabels()['project_review']);
        self::assertSame(1, $learner['ai_requests']);
        self::assertSame(1, $learner['ai_by_feature']['project_review']['delivered_requests']);
        self::assertSame(0.1, $learner['ai_by_feature']['project_review']['cost_usd']);
        self::assertSame(5.0, $learner['ai_cost_egp']);
        self::assertNull($learner['service_cost_actual_egp']);
    }

    public function test_period_orders_keep_old_learners_and_compare_immutable_tiers(): void
    {
        [$course, $period] = $this->periodFixture();
        $this->periodPurchase(101, 50, 'guided', $period->start->subDay());
        $this->periodPurchase(102, 100, 'mentor', $period->start->addDay());
        // A later tier rename/reprice is not evidence about either old sale.
        DB::table('course_access_plans')->where('id', 1)->update(['code' => 'basic', 'name_ar' => 'اسم جديد']);

        $report = app(CourseCommercialReportService::class)->forCourse($course, $period);

        self::assertCount(1, $report['rows']);
        self::assertSame(1, $report['active_students']);
        self::assertSame(0, $report['new_students']);
        self::assertSame(100, $report['paid_coins']);
        self::assertSame(10.0, $report['cash_gross_egp']);
        self::assertSame(100.0, $report['comparisons']['cash_gross_egp']['percentage']);
        self::assertArrayNotHasKey('active_students', $report['comparisons']);
        self::assertSame(5.0, $report['plan_breakdown']['guided']['comparisons']['cash_gross_egp']['previous']);
        self::assertSame(-100.0, $report['plan_breakdown']['guided']['comparisons']['cash_gross_egp']['percentage']);
        self::assertSame('new', $report['plan_breakdown']['mentor']['comparisons']['cash_gross_egp']['status']);
        self::assertNull($report['plan_breakdown']['mentor']['comparisons']['cash_gross_egp']['percentage']);
    }

    public function test_period_purchase_boundaries_are_half_open_and_all_time_has_no_comparison(): void
    {
        [$course, $period] = $this->periodFixture();
        $this->periodPurchase(101, 20, 'guided', $period->start);
        $this->periodPurchase(102, 30, 'guided', $period->end);
        $this->periodPurchase(103, 40, 'guided', $period->previous()->start);

        $service = app(CourseCommercialReportService::class);
        $current = $service->forCourse($course, $period);
        self::assertSame(20, $current['paid_coins']);
        self::assertSame(40.0, $current['comparisons']['paid_coins']['previous']);
        self::assertSame(-50.0, $current['comparisons']['paid_coins']['percentage']);
        $all = $service->forCourse($course);
        self::assertSame(90, $all['paid_coins']);
        self::assertSame('unavailable', $all['comparisons']['paid_coins']['status']);
        self::assertNull($all['comparisons']['paid_coins']['previous']);
    }

    public function test_tier_usage_uses_contract_at_event_time_and_missing_contract_is_unavailable(): void
    {
        [$course, $period] = $this->periodFixture();
        $this->periodPurchase(101, 20, 'guided', $period->start->subDay());
        DB::table('ai_usage_events')->insert([
            'request_id' => 'usage-period', 'user_id' => 1, 'course_id' => 10,
            'access_plan_id' => 1, 'feature' => 'course_chat', 'status' => 'completed',
            'total_tokens' => 150, 'cost_usd' => 0.000321,
            'metadata' => json_encode(['cost_usage_source' => 'provider']),
            'created_at' => $period->start->addHour(), 'updated_at' => $period->start->addHour(),
        ]);
        DB::table('course_access_plans')->where('id', 1)->update(['code' => 'mentor']);
        $service = app(CourseCommercialReportService::class);
        $report = $service->forCourse($course, $period);
        self::assertSame(1, $report['plan_breakdown']['guided']['period_metrics']['ai_requests']);
        self::assertSame(0.000321, $report['plan_breakdown']['guided']['period_metrics']['ai_cost_usd']);
        self::assertSame('new', $report['plan_breakdown']['guided']['comparisons']['ai_requests']['status']);
        self::assertSame(0, $report['new_students']);

        DB::table('orders')->where('id', 101)->update(['access_plan_snapshot' => null]);
        $unknown = $service->forCourse($course, $period);
        foreach ($unknown['plan_breakdown'] as $plan) {
            self::assertSame('unavailable', $plan['comparisons']['ai_requests']['status']);
        }
    }

    public function test_csv_uses_selected_period_and_excludes_estimated_cost_columns(): void
    {
        [$course, $period] = $this->periodFixture();
        $this->periodPurchase(101, 20, 'guided', $period->start->subDay());
        $this->periodPurchase(102, 40, 'mentor', $period->start->addDay());
        $csv = app(\App\Services\AdminCourseReportService::class)->csv($course, $period);
        self::assertCount(1, $csv['rows']);
        self::assertSame(count($csv['headings']), count($csv['rows'][0]));
        self::assertSame(40, $csv['rows'][0][array_search('عملات مشتراة', $csv['headings'], true)]);
        self::assertNotContains('التكلفة شاملة التقديرات', $csv['headings']);
        self::assertNotContains('هامش المساهمة التقديري', $csv['headings']);
    }

    public function test_old_enrollment_usage_uses_event_window_and_keeps_failed_provider_charge(): void
    {
        [$course, $period] = $this->periodFixture();
        $this->periodPurchase(101, 20, 'guided', $period->previous()->start->subDay());
        foreach ([
            ['previous', $period->start->subHour(), 'completed', 0.1],
            ['current', $period->start, 'completed', 0.2],
            ['failed', $period->start->addHour(), 'failed', 0.1],
            ['end', $period->end, 'completed', 0.9],
        ] as [$request, $created, $status, $cost]) {
            DB::table('ai_usage_events')->insert([
                'request_id' => $request, 'user_id' => 1, 'course_id' => 10,
                'access_plan_id' => 1, 'feature' => 'course_chat', 'status' => $status,
                'total_tokens' => 150, 'cost_usd' => $cost,
                'metadata' => json_encode(['cost_usage_source' => 'provider']),
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        $report = app(CourseCommercialReportService::class)->forCourse($course, $period);
        self::assertCount(1, $report['rows']);
        self::assertSame(0, $report['new_students']);
        self::assertSame(1, $report['ai_requests']);
        self::assertSame(1, $report['ai_failed_requests']);
        self::assertSame(0.3, $report['ai_cost_usd']);
        self::assertSame(200.0, $report['comparisons']['ai_cost_usd']['percentage']);
        $tier = $report['plan_breakdown']['guided'];
        self::assertSame(1, $tier['period_metrics']['ai_requests']);
        self::assertSame(150, $tier['period_metrics']['ai_tokens']);
        self::assertEqualsWithDelta(0.3, $tier['period_metrics']['ai_cost_usd'], 0.000001);
        self::assertSame(200.0, $tier['comparisons']['ai_cost_usd']['percentage']);
        $course->setRelation('accessPlans', collect([(object) ['code' => 'mentor']]));
        $editorStats = app(\App\Services\AdminCourseReportService::class)->accessPlanStats($course, $period)['mentor'];
        self::assertSame(1, $editorStats['chat_requests']);
        self::assertSame(150, $editorStats['chat_tokens']);
        self::assertEqualsWithDelta(0.3, $editorStats['chat_cost_usd'], 0.000001);
    }

    public function test_pending_provider_cost_is_unknown_for_tier_comparison_not_zero(): void
    {
        [$course, $period] = $this->periodFixture();
        $this->periodPurchase(101, 20, 'guided', $period->start->subDay());
        foreach (['reservation', 'provider'] as $index => $source) {
            DB::table('ai_usage_events')->insert([
                'request_id' => 'pending-'.$index, 'user_id' => 1, 'course_id' => 10,
                'access_plan_id' => 1, 'feature' => 'course_chat', 'status' => 'completed',
                'cost_usd' => 0.1, 'metadata' => json_encode(['cost_usage_source' => $source]),
                'created_at' => $period->start->addHours($index), 'updated_at' => $period->start,
            ]);
        }

        $report = app(CourseCommercialReportService::class)->forCourse($course, $period);
        self::assertSame(0.1, $report['ai_cost_usd']);
        self::assertSame(1, $report['ai_pending_cost_requests']);
        self::assertSame('unavailable', $report['comparisons']['ai_cost_usd']['status']);
        self::assertNull($report['plan_breakdown']['guided']['period_metrics']['ai_cost_usd']);
        self::assertSame('unavailable', $report['plan_breakdown']['guided']['comparisons']['ai_cost_usd']['status']);
    }

    public function test_incomplete_cash_and_coin_evidence_never_becomes_growth(): void
    {
        [$course, $period] = $this->periodFixture();
        $this->periodPurchase(101, 20, 'guided', $period->start);
        DB::table('orders')->where('id', 100)->update(['gateway_net_amount' => null]);
        $service = app(CourseCommercialReportService::class);
        $unsettled = $service->forCourse($course, $period);
        self::assertSame(2.0, $unsettled['cash_gross_egp']);
        self::assertNull($unsettled['cash_net_egp']);
        self::assertSame('unavailable', $unsettled['comparisons']['cash_net_egp']['status']);

        DB::table('orders')->where('id', 100)->update(['gateway_gross_amount' => null]);
        $partialCash = $service->forCourse($course, $period);
        self::assertFalse($partialCash['cash_gross_complete']);
        self::assertSame('unavailable', $partialCash['comparisons']['cash_gross_egp']['status']);

        DB::table('wallet_transactions')->where('id', 101)->delete();
        $missingLedger = $service->forCourse($course, $period);
        self::assertFalse($missingLedger['coin_allocation_complete']);
        foreach (['paid_coins', 'reward_coins', 'cash_gross_egp', 'cash_net_egp'] as $metric) {
            self::assertSame('unavailable', $missingLedger['comparisons'][$metric]['status']);
            self::assertSame('unavailable', $missingLedger['plan_breakdown']['guided']['comparisons'][$metric]['status']);
        }
    }

    private function periodFixture(): array
    {
        $period = ReportPeriod::fromKey('7d', CarbonImmutable::parse('2026-09-09T12:00:00Z'));
        $old = $period->start->subMonth();
        DB::table('users')->insert(['id' => 1, 'name_ar' => 'طالب', 'email' => 'period@example.test',
            'password' => 'x', 'role' => 'client', 'active' => true, 'created_at' => $old, 'updated_at' => $old]);
        DB::table('courses')->insert(['id' => 10, 'name_ar' => 'كورس', 'created_at' => $old, 'updated_at' => $old]);
        Schema::create('course_access_plans', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('course_id'); $table->string('code'); $table->string('name_ar');
        });
        DB::table('course_access_plans')->insert(['id' => 1, 'course_id' => 10, 'code' => 'mentor', 'name_ar' => 'الفئة الحالية']);
        DB::table('course_enrollments')->insert(['id' => 1, 'user_id' => 1, 'course_id' => 10,
            'access_plan_id' => 1, 'access_plan_snapshot' => json_encode(['code' => 'mentor', 'name_ar' => 'الفئة الحالية']),
            'is_active' => true, 'enrolled_at' => $old, 'access_granted_at' => $period->start->addHour(),
            'created_at' => $old, 'updated_at' => $old]);
        DB::table('orders')->insert(['id' => 100, 'user_id' => 1, 'package_id' => 1,
            'payment_method' => 'kashier', 'status' => 'approved', 'financial_status' => 'settled',
            'amount' => 100, 'discount_amount' => 0, 'final_amount' => 100,
            'gateway_gross_amount' => 100, 'gateway_net_amount' => 95, 'gateway_currency' => 'EGP',
            'approved_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        DB::table('wallet_credit_lots')->insert(['id' => 1, 'user_id' => 1, 'source_order_id' => 100,
            'original_amount' => 1000, 'remaining_amount' => 500, 'credited_at' => $old,
            'created_at' => $old, 'updated_at' => $old]);

        return [Course::query()->findOrFail(10), $period];
    }

    private function periodPurchase(int $id, int $coins, string $plan, CarbonImmutable $approved): void
    {
        DB::table('orders')->insert(['id' => $id, 'user_id' => 1, 'course_id' => 10,
            'payment_method' => 'wallet_coins', 'status' => 'approved', 'financial_status' => 'settled',
            'amount' => $coins, 'discount_amount' => 0, 'final_amount' => $coins,
            'wallet_transaction_id' => $id, 'access_plan_id' => 1,
            'access_plan_snapshot' => json_encode(['code' => $plan, 'name_ar' => $plan, 'price_coins' => $coins]),
            'total_coins' => $coins, 'paid_coins' => $coins, 'reward_coins' => 0,
            'approved_at' => $approved, 'created_at' => $approved, 'updated_at' => $approved]);
        DB::table('wallet_transactions')->insert(['id' => $id, 'public_id' => 'wallet-'.$id, 'user_id' => 1,
            'direction' => 'debit', 'category' => 'course_purchase', 'bucket' => 'paid',
            'amount' => $coins, 'paid_amount' => $coins, 'reward_amount' => 0,
            'balance_after' => 500, 'paid_balance_after' => 500, 'reward_balance_after' => 0,
            'source_type' => Course::class, 'source_id' => 10, 'idempotency_key' => 'period-'.$id,
            'occurred_at' => $approved, 'created_at' => $approved, 'updated_at' => $approved]);
        DB::table('wallet_debit_allocations')->insert(['credit_lot_id' => 1, 'course_order_id' => $id,
            'amount' => $coins, 'allocated_at' => $approved, 'created_at' => $approved, 'updated_at' => $approved]);
    }

    private function createSchema(): void
    {
        Schema::create('course_authoring_revisions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('canonical_course_id'); $table->unsignedBigInteger('revision_course_id');
        });
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
