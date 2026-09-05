<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourseAccessPlan;
use App\Services\CourseAccessPlanService;
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
