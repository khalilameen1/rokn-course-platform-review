<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\FinancialAnomaly;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletDebitAllocation;
use App\Models\WalletTransaction;
use App\Services\FinancialProvenanceService;
use App\Services\FinancialAnomalyService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CourseRewardContributionCapTest extends TestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);
        $this->withoutMiddleware(WebsiteVisitorCount::class);

        // The disposable SQLite schema intentionally retains tenant columns
        // that the production MySQL cutover removes. Supply their legacy test
        // value without changing the production purchase contract.
        if (Schema::hasColumn('bills', 'tenant_id')) {
            Bill::creating(static fn (Bill $bill) => $bill->setAttribute('tenant_id', 1));
        }
        if (Schema::hasColumn('course_enrollments', 'tenant_id')) {
            CourseEnrollment::creating(
                static fn (CourseEnrollment $enrollment) => $enrollment->setAttribute('tenant_id', 1)
            );
        }

        Setting::query()->create([
            'site_name_ar' => 'Rokn',
            'max_reward_contribution_per_course' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge();
        parent::tearDown();
    }

    public function test_wallet_domain_cap_uses_course_debit_ledger_and_reward_refills_do_not_reset_it(): void
    {
        $user = $this->user();
        $course = $this->course(false);
        $wallet = app(WalletService::class);

        $this->creditPaid($user, 20);
        $this->creditReward($user, 40);
        $wallet->debit(
            (int) $user->id,
            40,
            'course_purchase',
            $this->key('base-debit'),
            $course,
            [],
            100
        );

        $this->creditReward($user, 40);
        $policy = $wallet->courseRewardContribution((int) $user->id, (int) $course->id, 100);
        self::assertSame(['cap' => 100, 'used' => 40, 'remaining' => 60], $policy);
        $wallet->debit(
            (int) $user->id,
            40,
            'course_full_track_upgrade',
            $this->key('guided-debit'),
            $course,
            [],
            $policy['remaining']
        );

        $this->creditReward($user, 40);
        $policy = $wallet->courseRewardContribution((int) $user->id, (int) $course->id, 100);
        self::assertSame(['cap' => 100, 'used' => 80, 'remaining' => 20], $policy);
        $finalDebit = $wallet->debit(
            (int) $user->id,
            40,
            'course_full_track_upgrade',
            $this->key('mentor-debit'),
            $course,
            [],
            $policy['remaining']
        );

        self::assertSame(20, (int) $finalDebit->reward_amount);
        self::assertSame(20, (int) $finalDebit->paid_amount);
        self::assertSame(
            ['cap' => 100, 'used' => 100, 'remaining' => 0],
            $wallet->courseRewardContribution((int) $user->id, (int) $course->id, 100)
        );
        self::assertSame(100, (int) WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('source_type', Course::class)
            ->where('source_id', $course->id)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->sum('reward_amount'));

        $fresh = $user->fresh();
        self::assertSame(20, (int) $fresh->wallet_coins);
        self::assertSame(0, (int) $fresh->wallet_purchased_coins);
        self::assertSame(20, (int) $fresh->wallet_reward_coins);
    }

    public function test_basic_guided_and_mentor_http_flow_shares_one_cap_and_quote_reports_remaining_deficit(): void
    {
        $user = $this->user();
        $course = $this->course(true);
        $this->plans($course);
        $this->creditPaid($user, 100);
        $this->creditReward($user, 40);

        $baseKey = 'test-course-base-purchase-0001';
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::BASIC,
                'idempotency_key' => $baseKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.allocation.paid_coins', 0)
            ->assertJsonPath('data.allocation.reward_coins', 40)
            ->assertJsonPath('data.reward_contribution_used_for_course', 40)
            ->assertJsonPath('data.reward_contribution_remaining_for_course', 60);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::BASIC,
                'idempotency_key' => $baseKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.amount_deducted', 0)
            ->assertJsonPath('data.reward_contribution_used_for_course', 40)
            ->assertJsonPath('data.reward_contribution_remaining_for_course', 60);

        $this->creditReward($user, 40);
        $guidedKey = 'test-course-guided-upgrade-0001';
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/courses/{$course->id}/full-track-upgrade", [
                'target_plan_code' => CourseAccessPlan::GUIDED,
                'expected_price' => 40,
                'idempotency_key' => $guidedKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.amount_deducted', 40)
            ->assertJsonPath('data.reward_contribution_used_for_course', 79)
            ->assertJsonPath('data.reward_contribution_remaining_for_course', 21);

        $this->creditReward($user, 40);
        Package::query()->firstOrFail()->update([
            'google_enabled' => true,
            'apple_enabled' => true,
            'google_product_id' => 'rokn.coins.100.google',
            'apple_product_id' => 'rokn.coins.100.apple',
        ]);
        Package::query()->create([
            'name_ar' => 'باقة بلا قناة',
            'name_en' => 'Package without channel',
            'price' => 1,
            'coins' => 31,
            'is_active' => true,
            'direct_enabled' => false,
            'google_enabled' => false,
            'apple_enabled' => false,
        ]);
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/courses/{$course->id}/full-track-upgrade")
            ->assertOk()
            ->assertJsonPath('data.target_plan_code', CourseAccessPlan::MENTOR)
            ->assertJsonPath('data.upgrade_price', 150);

        $this->actingAs($user, 'api')
            ->getJson(
                "/api/v1/courses/{$course->id}/full-track-upgrade?target_plan_code=mentor"
            )
            ->assertOk()
            ->assertJsonPath('data.upgrade_price', 150)
            ->assertJsonPath('data.reward_contribution_cap_per_course', 100)
            ->assertJsonPath('data.reward_contribution_used_for_course', 79)
            ->assertJsonPath('data.reward_contribution_remaining_for_course', 21)
            ->assertJsonPath('data.estimated_allocation.reward_coins', 21)
            ->assertJsonPath('data.estimated_allocation.paid_coins', 99)
            ->assertJsonPath('data.spendable_balance', 120)
            ->assertJsonPath('data.deficit', 30)
            ->assertJsonPath('data.recommended_packages.0.price', 100)
            ->assertJsonPath('data.recommended_packages.0.direct_price', 90)
            ->assertJsonPath('data.recommended_packages.0.channels.google', true)
            ->assertJsonPath(
                'data.recommended_packages.0.store_products.apple',
                'rokn.coins.100.apple'
            );

        $this->creditPaid($user, 30);
        $mentorKey = 'test-course-mentor-upgrade-0001';
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/courses/{$course->id}/full-track-upgrade", [
                'target_plan_code' => CourseAccessPlan::MENTOR,
                'expected_price' => 150,
                'idempotency_key' => $mentorKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.amount_deducted', 150)
            ->assertJsonPath('data.reward_contribution_used_for_course', 100)
            ->assertJsonPath('data.reward_contribution_remaining_for_course', 0);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/courses/{$course->id}/full-track-upgrade", [
                'target_plan_code' => CourseAccessPlan::MENTOR,
                'expected_price' => 150,
                'idempotency_key' => $mentorKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.amount_deducted', 0);

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->orderBy('id')
            ->get();
        self::assertCount(3, $orders);
        self::assertSame(['basic', 'guided', 'mentor'], $orders
            ->map(fn (Order $order): string => (string) data_get($order->access_plan_snapshot, 'code'))
            ->all());
        self::assertSame([40, 39, 21], $orders->pluck('reward_coins')->map(fn ($value): int => (int) $value)->all());
        self::assertSame([0, 1, 129], $orders->pluck('paid_coins')->map(fn ($value): int => (int) $value)->all());
        self::assertSame((int) $orders[0]->id, (int) $orders[1]->parent_order_id);
        self::assertSame((int) $orders[1]->id, (int) $orders[2]->parent_order_id);

        $courseDebits = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('source_type', Course::class)
            ->where('source_id', $course->id)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT);
        self::assertSame(3, (clone $courseDebits)->count());
        self::assertSame(100, (int) (clone $courseDebits)->sum('reward_amount'));
        self::assertSame(130, (int) (clone $courseDebits)->sum('paid_amount'));
        self::assertSame(130, (int) WalletDebitAllocation::query()
            ->whereIn('course_order_id', $orders->pluck('id'))
            ->sum('amount'));

        $fresh = $user->fresh();
        self::assertSame(20, (int) $fresh->wallet_coins);
        self::assertSame(0, (int) $fresh->wallet_purchased_coins);
        self::assertSame(20, (int) $fresh->wallet_reward_coins);
    }

    public function test_ai_plan_floor_uses_paid_coins_even_when_reward_balance_is_large_and_isolates_a_forged_enrollment(): void
    {
        Queue::fake();
        $user = $this->user();
        $course = $this->course(true);
        $this->plans($course);
        CourseAccessPlan::query()->where('course_id', $course->id)->where('code', 'guided')
            ->update(['minimum_paid_coins' => 40]);
        CourseAccessPlan::query()->where('course_id', $course->id)->where('code', 'mentor')
            ->update(['minimum_paid_coins' => 190]);
        $this->creditPaid($user, 190);
        $this->creditReward($user, 500);

        $this->actingAs($user, 'api')->postJson('/api/v1/courses/authorize', [
            'course_id' => $course->id,
            'access_plan_code' => 'basic',
            'idempotency_key' => 'paid-floor-base-purchase-0001',
        ])->assertOk()->assertJsonPath('data.allocation.reward_coins', 40);

        $this->actingAs($user, 'api')->postJson("/api/v1/courses/{$course->id}/full-track-upgrade", [
            'target_plan_code' => 'guided',
            'expected_price' => 40,
            'idempotency_key' => 'paid-floor-guided-upgrade-0001',
        ])->assertOk()
            ->assertJsonPath('data.allocation.paid_coins', 40)
            ->assertJsonPath('data.allocation.reward_coins', 0)
            ->assertJsonPath('data.financial_review_required', false);

        $this->actingAs($user, 'api')->postJson("/api/v1/courses/{$course->id}/full-track-upgrade", [
            'target_plan_code' => 'mentor',
            'expected_price' => 150,
            'idempotency_key' => 'paid-floor-mentor-upgrade-0001',
        ])->assertOk()
            ->assertJsonPath('data.allocation.paid_coins', 150)
            ->assertJsonPath('data.allocation.reward_coins', 0)
            ->assertJsonPath('data.financial_review_required', false);

        self::assertSame(190, app(WalletService::class)->coursePaidContribution(
            (int) $user->id,
            (int) $course->id
        ));
        self::assertSame(40, (int) WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('source_type', Course::class)
            ->where('source_id', $course->id)
            ->sum('reward_amount'));

        $forgedUser = $this->user();
        $forgedOrder = Order::query()->create([
            'user_id' => $forgedUser->id,
            'course_id' => $course->id,
            'access_plan_id' => $course->accessPlans()->where('code', 'mentor')->value('id'),
            'access_plan_snapshot' => app(\App\Services\CourseAccessPlanService::class)->snapshot(
                $course->accessPlans()->where('code', 'mentor')->firstOrFail()
            ),
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 230,
            'final_amount' => 230,
            'total_coins' => 230,
            'paid_coins' => 0,
            'reward_coins' => 230,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
        $forgedEnrollment = CourseEnrollment::query()->create([
            'user_id' => $forgedUser->id,
            'course_id' => $course->id,
            'order_id' => $forgedOrder->id,
            'access_plan_order_id' => $forgedOrder->id,
            'access_plan_id' => $forgedOrder->access_plan_id,
            'access_plan_snapshot' => $forgedOrder->access_plan_snapshot,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        self::assertFalse(app(FinancialAnomalyService::class)
            ->allowsVariableCostFeatures($forgedEnrollment));
        self::assertTrue(app(FinancialAnomalyService::class)
            ->allowsVariableCostFeatures(CourseEnrollment::query()
                ->where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail()));
        self::assertSame(1, FinancialAnomaly::query()->where('status', 'open')->count());
        self::assertSame((int) $forgedUser->id, (int) FinancialAnomaly::query()->value('user_id'));
    }

    public function test_purchase_returns_actionable_validation_when_dashboard_disables_every_plan(): void
    {
        $user = $this->user();
        $course = $this->course(true);
        $this->plans($course);
        $course->accessPlans()->update(['is_active' => false]);
        $this->creditPaid($user, 100);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::BASIC,
                'idempotency_key' => 'disabled-course-plan-purchase-0001',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'course_plan_unavailable')
            ->assertJsonPath('errors.access_plan_code.0', 'فتح هذا الكورس متوقف مؤقتًا حتى تُنشر خطة متاحة.');

        self::assertSame(0, Order::query()->where('course_id', $course->id)->count());
        self::assertSame(100, (int) $user->fresh()->wallet_coins);
    }

    public function test_stale_purchase_plan_never_reports_an_unperformed_upgrade_or_downgrade(): void
    {
        $course = $this->course(true);
        $this->plans($course);

        $basicOwner = $this->user();
        $this->creditPaid($basicOwner, 300);
        $basicKey = 'stale-plan-basic-purchase-0001';
        $this->actingAs($basicOwner, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::BASIC,
                'expected_price' => 40,
                'idempotency_key' => $basicKey,
            ])
            ->assertOk();

        $basicBalance = (int) $basicOwner->fresh()->wallet_coins;
        $basicOrders = Order::query()
            ->where('user_id', $basicOwner->id)
            ->where('course_id', $course->id)
            ->count();

        $this->actingAs($basicOwner, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::MENTOR,
                'expected_price' => 230,
                'idempotency_key' => 'stale-plan-false-upgrade-0001',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'course_access_changed')
            ->assertJsonPath('data.requested_access_plan_code', CourseAccessPlan::MENTOR)
            ->assertJsonPath('data.current_access_plan.code', CourseAccessPlan::BASIC)
            ->assertJsonPath('data.already_enrolled', true);

        self::assertSame($basicBalance, (int) $basicOwner->fresh()->wallet_coins);
        self::assertSame($basicOrders, Order::query()
            ->where('user_id', $basicOwner->id)
            ->where('course_id', $course->id)
            ->count());

        // A real completed receipt wins over later catalogue edits. Retrying
        // that exact operation remains a replay, not a stale-plan failure.
        $course->accessPlans()->where('code', CourseAccessPlan::BASIC)
            ->update(['price_coins' => 60]);
        $course->forceFill([
            'authoring_version' => 2,
            'last_published_authoring_version' => 2,
        ])->save();
        $this->actingAs($basicOwner, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::BASIC,
                'expected_price' => 40,
                'expected_course_revision' => 1,
                'idempotency_key' => $basicKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.amount_deducted', 0);

        $mentorOwner = $this->user();
        $this->creditPaid($mentorOwner, 300);
        $this->actingAs($mentorOwner, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::MENTOR,
                'expected_price' => 230,
                'idempotency_key' => 'stale-plan-mentor-purchase-0001',
            ])
            ->assertOk();

        $mentorBalance = (int) $mentorOwner->fresh()->wallet_coins;
        $mentorOrders = Order::query()
            ->where('user_id', $mentorOwner->id)
            ->where('course_id', $course->id)
            ->count();

        $this->actingAs($mentorOwner, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $course->id,
                'access_plan_code' => CourseAccessPlan::BASIC,
                'expected_price' => 60,
                'idempotency_key' => 'stale-plan-false-downgrade-0001',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'course_access_changed')
            ->assertJsonPath('data.requested_access_plan_code', CourseAccessPlan::BASIC)
            ->assertJsonPath('data.current_access_plan.code', CourseAccessPlan::MENTOR);

        self::assertSame($mentorBalance, (int) $mentorOwner->fresh()->wallet_coins);
        self::assertSame($mentorOrders, Order::query()
            ->where('user_id', $mentorOwner->id)
            ->where('course_id', $course->id)
            ->count());
    }

    private function user(): User
    {
        return User::query()->forceCreate([
            'name' => 'Reward cap learner',
            'email' => 'reward-cap-' . (++$this->sequence) . '@example.test',
            'phone' => '0100000' . str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'role' => 'client',
            'gender' => 'other',
            'active' => true,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
    }

    private function course(bool $withSection): Course
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1,
            'name_ar' => 'Reward cap course',
            'name_en' => 'Reward cap course',
            'description_ar' => 'Test course',
            'description_en' => 'Test course',
            'course_type' => 'online',
            'price' => 40,
            'is_main_course' => true,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'ai_chat_enabled' => true,
        ]);

        if ($withSection) {
            $module = CourseModule::query()->create([
                'course_id' => $course->id,
                'title_ar' => 'Test module',
                'order' => 1,
            ]);
            $lesson = Lesson::query()->create([
                'list_id' => $course->id,
                'title_ar' => 'Test reel',
                'duration_minutes' => 1,
            ]);
            CourseSection::query()->create([
                'course_id' => $course->id,
                'module_id' => $module->id,
                'title_ar' => 'Test section',
                'title_en' => 'Test section',
                'section_type' => 'lesson',
                'sectionable_type' => Lesson::class,
                'sectionable_id' => $lesson->id,
                'order' => 1,
            ]);
        }

        return $course;
    }

    private function plans(Course $course): void
    {
        foreach ([
            [
                'code' => 'basic', 'name_ar' => 'Basic', 'price_coins' => 40,
                'minimum_paid_coins' => 0,
                'chat_enabled' => false, 'chat_message_limit' => 0, 'chat_token_budget' => 0,
                'ai_budget_usd' => 0, 'request_reserve_usd' => 0,
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => 0, 'project_feedback_reserve_usd' => 0,
                'project_followup_message_limit' => 0, 'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0, 'project_followup_reserve_usd' => 0,
                'max_output_tokens' => 260, 'project_feedback_level' => 'pass_only',
                'project_output_enabled' => false, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 10,
            ],
            [
                'code' => 'guided', 'name_ar' => 'Guided', 'price_coins' => 80,
                'minimum_paid_coins' => 1,
                'chat_enabled' => true, 'chat_message_limit' => 25, 'chat_token_budget' => 12000,
                'ai_budget_usd' => 0.45, 'request_reserve_usd' => 0.015,
                'project_feedback_token_budget' => 6000,
                'project_feedback_budget_usd' => 0.20, 'project_feedback_reserve_usd' => 0.04,
                'project_followup_message_limit' => 0, 'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0, 'project_followup_reserve_usd' => 0,
                'max_output_tokens' => 320, 'project_feedback_level' => 'report',
                'project_output_enabled' => false, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 20,
            ],
            [
                'code' => 'mentor', 'name_ar' => 'Mentor', 'price_coins' => 230,
                'minimum_paid_coins' => 1,
                'chat_enabled' => true, 'chat_message_limit' => 80, 'chat_token_budget' => 42000,
                'ai_budget_usd' => 1.50, 'request_reserve_usd' => 0.025,
                'project_feedback_token_budget' => 16000,
                'project_feedback_budget_usd' => 0.60, 'project_feedback_reserve_usd' => 0.08,
                'project_followup_message_limit' => 20, 'project_followup_token_budget' => 12000,
                'project_followup_budget_usd' => 0.30, 'project_followup_reserve_usd' => 0.025,
                'max_output_tokens' => 480, 'project_feedback_level' => 'enhanced',
                'project_output_enabled' => true, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 30,
            ],
        ] as $definition) {
            $course->accessPlans()->create($definition);
        }
    }

    private function creditPaid(User $user, int $coins): void
    {
        $package = Package::query()->create([
            'name_ar' => 'Test package',
            'name_en' => 'Test package',
            'price' => $coins,
            'coins' => $coins,
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => $coins,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => $coins,
            'discount_amount' => 0,
            'final_amount' => $coins,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
        $credit = app(WalletService::class)->credit(
            (int) $user->id,
            $coins,
            'package_purchase',
            $this->key('paid-credit'),
            $order,
            ['package_id' => (int) $package->id],
            WalletTransaction::BUCKET_PAID
        );
        app(FinancialProvenanceService::class)->recordPaidPackageCredit($order, $credit);
    }

    private function creditReward(User $user, int $coins): void
    {
        app(WalletService::class)->credit(
            (int) $user->id,
            $coins,
            'test_reward_refill',
            $this->key('reward-credit'),
            null,
            [],
            WalletTransaction::BUCKET_REWARD
        );
    }

    private function key(string $operation): string
    {
        return sprintf('test:%s:%04d', $operation, ++$this->sequence);
    }
}
