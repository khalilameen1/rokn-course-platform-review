<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireProductFeature;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Package;
use App\Models\CourseAccessPlan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourseAccessPlanService;
use App\Services\FinancialProvenanceService;
use App\Services\WalletService;
use App\Support\CourseAccessPlanSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccessPlanSnapshotMysqlConstraintTest extends TestCase
{
    private bool $mysqlTransactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $required = filter_var(
            env('ROKN_REQUIRE_MYSQL_CONTRACT_TEST'),
            FILTER_VALIDATE_BOOL
        );
        if (!$required) {
            self::markTestSkipped('MySQL enforces the production JSON CHECK constraints.');
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        if ($connection->getDriverName() !== 'mysql') {
            self::fail('The access-plan snapshot contract test requires the actual MySQL schema.');
        }
        self::assertSame('testing', app()->environment());
        self::assertMatchesRegularExpression('/(?:^|_)test(?:_|$)/i', $database);

        DB::beginTransaction();
        $this->mysqlTransactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->mysqlTransactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_orders_and_enrollments_accept_historical_and_current_snapshots(): void
    {
        self::assertSame('mysql', DB::connection()->getDriverName());
        $versions = [
            ...array_map(
                static fn (int $version): array => ['version' => $version, 'code' => 'basic'],
                range(1, CourseAccessPlanSnapshot::CURRENT_VERSION - 1)
            ),
            ['version' => CourseAccessPlanSnapshot::CURRENT_VERSION, 'code' => 'basic'],
            ['version' => CourseAccessPlanSnapshot::CURRENT_VERSION, 'code' => 'guided'],
            ['version' => CourseAccessPlanSnapshot::CURRENT_VERSION, 'code' => 'mentor'],
        ];

        foreach ($versions as $case) {
            $fixture = $this->commercialFixture($case['code']);
            $snapshot = $this->snapshot(
                (int) $case['version'],
                $fixture['plan_id'],
                $case['code']
            );
            $orderId = $this->insertOrder($fixture, $snapshot);
            $this->insertEnrollment($fixture, $orderId, $snapshot);
        }

        self::assertSame(count($versions), DB::table('orders')->count());
        self::assertSame(count($versions), DB::table('course_enrollments')->count());
    }

    public function test_both_constraints_reject_incomplete_and_plan_mismatched_snapshots(): void
    {
        $fixture = $this->commercialFixture('mentor');
        $valid = $this->snapshot(
            CourseAccessPlanSnapshot::CURRENT_VERSION,
            $fixture['plan_id'],
            'mentor'
        );
        $incomplete = $valid;
        unset($incomplete['project_followup_attachment_max_files']);
        $mismatched = $valid;
        $mismatched['plan_id'] = $fixture['plan_id'] + 100000;

        $this->assertConstraintRejects(
            'orders_access_plan_snapshot_check',
            fn (): int => $this->insertOrder($fixture, $incomplete)
        );
        $this->assertConstraintRejects(
            'orders_access_plan_snapshot_check',
            fn (): int => $this->insertOrder($fixture, $mismatched)
        );

        $orderId = $this->insertOrder($fixture, $valid);
        $this->assertConstraintRejects(
            'enrollments_access_plan_snapshot_check',
            fn () => $this->insertEnrollment($fixture, $orderId, $incomplete)
        );
        $this->assertConstraintRejects(
            'enrollments_access_plan_snapshot_check',
            fn () => $this->insertEnrollment($fixture, $orderId, $mismatched)
        );
    }

    public function test_wallet_course_purchase_and_same_key_replay_write_one_complete_financial_receipt(): void
    {
        $fixture = $this->commercialFixture('mentor');
        $now = now();
        DB::table('courses')->where('id', $fixture['course_id'])->update([
            'is_catalog_visible' => true,
            'is_coming_soon' => false,
            'last_published_authoring_version' => 1,
        ]);
        $moduleId = (int) DB::table('course_modules')->insertGetId([
            'course_id' => $fixture['course_id'],
            'title' => 'Published module',
            'order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $fixture['course_id'],
            'title' => 'Published lesson',
            'is_opened' => true,
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $fixture['course_id'],
            'module_id' => $moduleId,
            'title' => 'Published lesson',
            'section_type' => 'lesson',
            'order' => 1,
            'sectionable_id' => $lesson->id,
            'sectionable_type' => Lesson::class,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = User::query()->findOrFail($fixture['user_id']);
        $package = Package::query()->create([
            'name_ar' => 'باقة اختبار عقد الشراء',
            'name_en' => 'Purchase contract package',
            'price' => 10,
            'coins' => $fixture['price'],
        ]);
        $packageOrder = Order::query()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => $fixture['price'],
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => 10,
            'discount_amount' => 0,
            'final_amount' => 10,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => $now,
        ]);
        $credit = app(WalletService::class)->credit(
            (int) $user->id,
            $fixture['price'],
            'package_purchase',
            'mysql-contract:package-credit:' . $packageOrder->id,
            $packageOrder,
            ['package_id' => (int) $package->id],
            WalletTransaction::BUCKET_PAID
        );
        $paidLot = app(FinancialProvenanceService::class)
            ->recordPaidPackageCredit($packageOrder, $credit);
        app(WalletService::class)->credit(
            (int) $user->id,
            45,
            'test_reward_refill',
            'mysql-contract:reward-credit:' . $packageOrder->id,
            null,
            [],
            WalletTransaction::BUCKET_REWARD
        );

        $checkoutKey = 'mysql-contract:course-purchase:' . bin2hex(random_bytes(12));
        $request = [
            'course_id' => $fixture['course_id'],
            'access_plan_code' => 'mentor',
            'expected_price' => $fixture['price'],
            'expected_course_revision' => 1,
            'idempotency_key' => $checkoutKey,
        ];
        $first = $this->withoutMiddleware(RequireProductFeature::class)
            ->actingAs($user, 'api')
            ->postJson('/api/v1/courses/authorize', $request);
        $first->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_deducted', $fixture['price'])
            ->assertJsonPath('data.allocation.paid_coins', 855)
            ->assertJsonPath('data.allocation.reward_coins', 45)
            ->assertJsonPath('data.financial_review_required', false)
            ->assertJsonPath('data.idempotent_replay', false);

        $courseOrderId = (int) $first->json('data.order_id');
        $billId = (int) $first->json('data.bill_id');
        $enrollmentId = (int) $first->json('data.enrollment_id');
        $debitId = (int) DB::table('orders')->where('id', $courseOrderId)
            ->value('wallet_transaction_id');
        $afterFirst = [
            'balance' => (int) User::query()->findOrFail($user->id)->wallet_coins,
            'paid_balance' => (int) User::query()->findOrFail($user->id)->wallet_purchased_coins,
            'reward_balance' => (int) User::query()->findOrFail($user->id)->wallet_reward_coins,
            'course_orders' => DB::table('orders')->where('course_id', $fixture['course_id'])->count(),
            'bills' => DB::table('bills')->where('order_id', $courseOrderId)->count(),
            'enrollments' => DB::table('course_enrollments')
                ->where('user_id', $user->id)->where('course_id', $fixture['course_id'])->count(),
            'debits' => DB::table('wallet_transactions')
                ->where('user_id', $user->id)->where('category', 'course_purchase')->count(),
            'allocations' => DB::table('wallet_debit_allocations')
                ->where('wallet_transaction_id', $debitId)->count(),
            'paid_lots' => DB::table('wallet_credit_lots')
                ->where('source_order_id', $packageOrder->id)->count(),
            'lot_remaining' => (int) DB::table('wallet_credit_lots')
                ->where('id', $paidLot->id)->value('remaining_amount'),
        ];
        self::assertSame([
            'balance' => 45,
            'paid_balance' => 45,
            'reward_balance' => 0,
            'course_orders' => 1,
            'bills' => 1,
            'enrollments' => 1,
            'debits' => 1,
            'allocations' => 1,
            'paid_lots' => 1,
            'lot_remaining' => 45,
        ], $afterFirst);

        $replay = $this->withoutMiddleware(RequireProductFeature::class)
            ->actingAs($user->fresh(), 'api')
            ->postJson('/api/v1/courses/authorize', $request);
        $replay->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $courseOrderId)
            ->assertJsonPath('data.bill_id', $billId)
            ->assertJsonPath('data.enrollment_id', $enrollmentId)
            ->assertJsonPath('data.amount_deducted', 0)
            ->assertJsonPath('data.idempotent_replay', true);

        self::assertSame($afterFirst, [
            'balance' => (int) User::query()->findOrFail($user->id)->wallet_coins,
            'paid_balance' => (int) User::query()->findOrFail($user->id)->wallet_purchased_coins,
            'reward_balance' => (int) User::query()->findOrFail($user->id)->wallet_reward_coins,
            'course_orders' => DB::table('orders')->where('course_id', $fixture['course_id'])->count(),
            'bills' => DB::table('bills')->where('order_id', $courseOrderId)->count(),
            'enrollments' => DB::table('course_enrollments')
                ->where('user_id', $user->id)->where('course_id', $fixture['course_id'])->count(),
            'debits' => DB::table('wallet_transactions')
                ->where('user_id', $user->id)->where('category', 'course_purchase')->count(),
            'allocations' => DB::table('wallet_debit_allocations')
                ->where('wallet_transaction_id', $debitId)->count(),
            'paid_lots' => DB::table('wallet_credit_lots')
                ->where('source_order_id', $packageOrder->id)->count(),
            'lot_remaining' => (int) DB::table('wallet_credit_lots')
                ->where('id', $paidLot->id)->value('remaining_amount'),
        ]);
    }

    /** @return array{user_id:int,course_id:int,plan_id:int,price:int} */
    private function commercialFixture(string $code): array
    {
        $suffix = bin2hex(random_bytes(8));
        $now = now();
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Snapshot contract learner',
            'email' => "snapshot-{$suffix}@example.test",
            'role' => 'client',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $courseId = (int) DB::table('courses')->insertGetId([
            'name_ar' => 'عقد تجريبي',
            'name_en' => "Snapshot contract {$suffix}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $terms = $this->terms($code);
        $planId = (int) DB::table('course_access_plans')->insertGetId([
            'course_id' => $courseId,
            'code' => $code,
            'name_ar' => $terms['name_ar'],
            'name_en' => ucfirst($code),
            'price_coins' => $terms['price_coins'],
            'minimum_paid_coins' => $terms['minimum_paid_coins'],
            'chat_enabled' => $terms['chat_enabled'],
            'chat_message_limit' => $terms['chat_message_limit'],
            'chat_token_budget' => $terms['chat_token_budget'],
            'chat_attachments_enabled' => $terms['chat_attachments_enabled'],
            'chat_attachment_max_files' => $terms['chat_attachment_max_files'],
            'project_followup_attachments_enabled' => $terms['project_followup_attachments_enabled'],
            'project_followup_attachment_max_files' => $terms['project_followup_attachment_max_files'],
            'ai_budget_usd' => $terms['ai_budget_usd'],
            'request_reserve_usd' => $terms['request_reserve_usd'],
            'project_feedback_token_budget' => $terms['project_feedback_token_budget'],
            'project_feedback_budget_usd' => $terms['project_feedback_budget_usd'],
            'project_feedback_reserve_usd' => $terms['project_feedback_reserve_usd'],
            'project_followup_message_limit' => $terms['project_followup_message_limit'],
            'project_followup_token_budget' => $terms['project_followup_token_budget'],
            'project_followup_budget_usd' => $terms['project_followup_budget_usd'],
            'project_followup_reserve_usd' => $terms['project_followup_reserve_usd'],
            'max_output_tokens' => $terms['max_output_tokens'],
            'model_override' => null,
            'project_feedback_level' => $terms['project_feedback_level'],
            'project_output_enabled' => $terms['project_output_enabled'],
            'certificate_enabled' => true,
            'is_active' => true,
            'sort_order' => $terms['sort_order'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'course_id' => $courseId,
            'plan_id' => $planId,
            'price' => $terms['price_coins'],
        ];
    }

    /** @param array{user_id:int,course_id:int,plan_id:int,price:int} $fixture */
    private function insertOrder(array $fixture, array $snapshot): int
    {
        return (int) DB::table('orders')->insertGetId([
            'user_id' => $fixture['user_id'],
            'course_id' => $fixture['course_id'],
            'access_plan_id' => $fixture['plan_id'],
            'access_plan_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'payment_method' => 'wallet_coins',
            'amount' => $fixture['price'],
            'discount_amount' => 0,
            'final_amount' => $fixture['price'],
            'total_coins' => $fixture['price'],
            'paid_coins' => $fixture['price'],
            'reward_coins' => 0,
            'status' => 'approved',
            'financial_status' => 'settled',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array{user_id:int,course_id:int,plan_id:int,price:int} $fixture */
    private function insertEnrollment(array $fixture, int $orderId, array $snapshot): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $fixture['user_id'],
            'course_id' => $fixture['course_id'],
            'order_id' => $orderId,
            'access_plan_id' => $fixture['plan_id'],
            'access_plan_order_id' => $orderId,
            'access_plan_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'enrolled_at' => now(),
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertConstraintRejects(string $constraint, callable $write): void
    {
        try {
            $write();
            self::fail("MySQL accepted an invalid snapshot for {$constraint}.");
        } catch (QueryException $exception) {
            self::assertStringContainsString($constraint, $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(int $version, int $planId, string $code): array
    {
        if ($version === CourseAccessPlanSnapshot::CURRENT_VERSION) {
            $snapshot = app(CourseAccessPlanService::class)->snapshot(
                CourseAccessPlan::query()->findOrFail($planId),
                now()
            );
            self::assertSame(CourseAccessPlanSnapshot::CURRENT_VERSION, $snapshot['version']);

            return $snapshot;
        }

        $terms = $this->terms($code);
        $snapshot = [
            'version' => $version,
            'plan_id' => $planId,
            'code' => $code,
            'name_ar' => $terms['name_ar'],
            'price_coins' => $terms['price_coins'],
            'chat_enabled' => $terms['chat_enabled'],
            'chat_message_limit' => $terms['chat_message_limit'],
            'chat_token_budget' => $terms['chat_token_budget'],
            'ai_budget_usd' => $version === 1 ? (float) $terms['ai_budget_usd'] : $terms['ai_budget_usd'],
            'request_reserve_usd' => $version === 1 ? (float) $terms['request_reserve_usd'] : $terms['request_reserve_usd'],
            'project_feedback_token_budget' => $terms['project_feedback_token_budget'],
            'project_feedback_budget_usd' => $version === 1
                ? (float) $terms['project_feedback_budget_usd']
                : $terms['project_feedback_budget_usd'],
            'project_feedback_reserve_usd' => $version === 1
                ? (float) $terms['project_feedback_reserve_usd']
                : $terms['project_feedback_reserve_usd'],
            'max_output_tokens' => $terms['max_output_tokens'],
            'model_override' => null,
            'project_feedback_level' => $terms['project_feedback_level'],
            'project_output_enabled' => $terms['project_output_enabled'],
            'certificate_enabled' => true,
            'purchased_at' => now()->toIso8601String(),
        ];
        if ($version >= 2) {
            $snapshot['sort_order'] = $terms['sort_order'];
            $snapshot['minimum_paid_coins'] = $terms['minimum_paid_coins'];
        }
        if ($version >= 3) {
            $snapshot['project_followup_message_limit'] = $terms['project_followup_message_limit'];
            $snapshot['project_followup_token_budget'] = $terms['project_followup_token_budget'];
            $snapshot['project_followup_budget_usd'] = $terms['project_followup_budget_usd'];
            $snapshot['project_followup_reserve_usd'] = $terms['project_followup_reserve_usd'];
        }
        if ($version >= 4) {
            $snapshot['chat_attachments_enabled'] = $terms['chat_attachments_enabled'];
            $snapshot['chat_attachment_max_files'] = $terms['chat_attachment_max_files'];
        }
        if ($version >= 5) {
            $snapshot['project_followup_attachments_enabled'] = $terms['project_followup_attachments_enabled'];
            $snapshot['project_followup_attachment_max_files'] = $terms['project_followup_attachment_max_files'];
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function terms(string $code): array
    {
        return match ($code) {
            'guided' => [
                'name_ar' => 'التعلّم بإرشاد', 'price_coins' => 650,
                'minimum_paid_coins' => 450, 'chat_enabled' => true,
                'chat_message_limit' => 50, 'chat_token_budget' => 20000,
                'chat_attachments_enabled' => true, 'chat_attachment_max_files' => 2,
                'ai_budget_usd' => '1.000000', 'request_reserve_usd' => '0.010000',
                'project_feedback_token_budget' => 6000,
                'project_feedback_budget_usd' => '0.200000',
                'project_feedback_reserve_usd' => '0.040000',
                'project_followup_message_limit' => 0,
                'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => '0.000000',
                'project_followup_reserve_usd' => '0.000000',
                'project_followup_attachments_enabled' => false,
                'project_followup_attachment_max_files' => 0,
                'max_output_tokens' => 400, 'project_feedback_level' => 'report',
                'project_output_enabled' => false, 'sort_order' => 20,
            ],
            'mentor' => [
                'name_ar' => 'التعلّم بمتابعة', 'price_coins' => 900,
                'minimum_paid_coins' => 450, 'chat_enabled' => true,
                'chat_message_limit' => 50, 'chat_token_budget' => 30000,
                'chat_attachments_enabled' => true, 'chat_attachment_max_files' => 3,
                'ai_budget_usd' => '2.000000', 'request_reserve_usd' => '0.020000',
                'project_feedback_token_budget' => 10000,
                'project_feedback_budget_usd' => '0.500000',
                'project_feedback_reserve_usd' => '0.050000',
                'project_followup_message_limit' => 50,
                'project_followup_token_budget' => 20000,
                'project_followup_budget_usd' => '0.500000',
                'project_followup_reserve_usd' => '0.050000',
                'project_followup_attachments_enabled' => true,
                'project_followup_attachment_max_files' => 3,
                'max_output_tokens' => 400, 'project_feedback_level' => 'enhanced',
                'project_output_enabled' => true, 'sort_order' => 30,
            ],
            default => [
                'name_ar' => 'التعلّم', 'price_coins' => 400,
                'minimum_paid_coins' => 0, 'chat_enabled' => false,
                'chat_message_limit' => 0, 'chat_token_budget' => 0,
                'chat_attachments_enabled' => false, 'chat_attachment_max_files' => 0,
                'ai_budget_usd' => '0.000000', 'request_reserve_usd' => '0.000000',
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => '0.000000',
                'project_feedback_reserve_usd' => '0.000000',
                'project_followup_message_limit' => 0,
                'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => '0.000000',
                'project_followup_reserve_usd' => '0.000000',
                'project_followup_attachments_enabled' => false,
                'project_followup_attachment_max_files' => 0,
                'max_output_tokens' => 260, 'project_feedback_level' => 'pass_only',
                'project_output_enabled' => false, 'sort_order' => 10,
            ],
        };
    }
}
