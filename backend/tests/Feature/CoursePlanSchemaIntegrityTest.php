<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CoursePlanSchemaIntegrityTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge();
        parent::tearDown();
    }

    public function test_fresh_schema_contains_course_plan_scale_and_recovery_guards(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);

        self::assertTrue(Schema::hasColumn('ai_usage_events', 'reservation_expires_at'));
        self::assertTrue(Schema::hasColumn('orders', 'parent_order_id'));
        self::assertTrue(Schema::hasColumn('course_enrollments', 'access_plan_order_id'));
        self::assertTrue(
            Schema::hasColumn('financial_entitlement_holds', 'plan_reverted_at')
        );

        $this->assertIndexExists(
            'orders',
            'orders_course_plan_sales_rollup',
            ['course_id', 'status', 'access_plan_id']
        );
        $this->assertIndexExists(
            'orders',
            'orders_parent_user_course_identity',
            ['parent_order_id', 'user_id', 'course_id']
        );
        $this->assertIndexExists(
            'course_enrollments',
            'enrollments_active_course_lookup',
            ['course_id', 'is_active', 'expires_at', 'id']
        );
        $this->assertIndexExists(
            'ai_usage_events',
            'ai_events_reservation_expiry',
            ['status', 'reservation_expires_at', 'id']
        );
        $this->assertIndexExists(
            'ai_usage_events',
            'ai_events_course_plan_cost_rollup',
            ['course_id', 'status', 'access_plan_id', 'feature']
        );

        if (DB::connection()->getDriverName() === 'sqlite') {
            $foreignKeys = DB::select("PRAGMA foreign_key_list('course_enrollments')");
            $orderForeignKey = collect($foreignKeys)->first(
                static fn (object $key): bool => (string) ($key->from ?? '') === 'order_id'
                    && (string) ($key->table ?? '') === 'orders'
                    && strtolower((string) ($key->on_delete ?? '')) === 'restrict'
            );

            self::assertNotNull(
                $orderForeignKey,
                'course_enrollments.order_id must be protected on fresh SQLite test databases.'
            );
        }
    }

    /** @param list<string> $expectedColumns */
    private function assertIndexExists(
        string $tableName,
        string $indexName,
        array $expectedColumns
    ): void {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$tableName}')");
            self::assertNotNull(
                collect($indexes)->first(
                    static fn (object $index): bool => (string) ($index->name ?? '') === $indexName
                ),
                "Missing index {$indexName} on {$tableName}."
            );
            $columns = collect(DB::select("PRAGMA index_info('{$indexName}')"))
                ->sortBy('seqno')
                ->pluck('name')
                ->all();
            self::assertSame($expectedColumns, $columns, "Wrong columns for {$indexName}.");

            return;
        }

        $physicalTable = DB::connection()->getTablePrefix().$tableName;
        $columns = collect(DB::select(
            'SELECT column_name FROM information_schema.statistics '
            .'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? '
            .'ORDER BY seq_in_index',
            [$physicalTable, $indexName]
        ))->pluck('column_name')->all();

        self::assertSame($expectedColumns, $columns, "Wrong columns for {$indexName}.");
    }
}
