<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StoreBillingRecoveryMigrationResumeTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge();
        parent::tearDown();
    }

    public static function interruptedShapes(): array
    {
        return [
            'before create' => [false, false, false, false, false],
            'create before foreign key' => [true, false, false, false, false],
            'accounts complete' => [true, true, false, false, false],
            'first purchase column' => [true, true, true, false, false],
            'retry column only' => [true, true, false, true, false],
            'columns before index' => [true, true, true, true, false],
            'complete before migration recorded' => [true, true, true, true, true],
        ];
    }

    #[DataProvider('interruptedShapes')]
    public function test_migration_resumes_without_losing_bindings_purchases_or_constraints(
        bool $accountsExist,
        bool $foreignKeyExists,
        bool $finalizedExists,
        bool $retryExists,
        bool $indexExists,
    ): void {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);

        Schema::create('store_purchases', function (Blueprint $table) use ($finalizedExists, $retryExists, $indexExists): void {
            $table->id();
            $table->string('provider');
            $table->string('transaction_id');
            $table->unique(['provider', 'transaction_id'], 'store_purchase_identity');
            if ($finalizedExists) {
                $table->timestamp('finalized_at')->nullable();
            }
            if ($retryExists) {
                $table->timestamp('finalization_retry_at')->nullable();
            }
            if ($indexExists) {
                $table->index(['provider', 'finalized_at', 'finalization_retry_at'], 'store_finalization_recovery');
            }
        });
        $purchase = ['id' => 1, 'provider' => 'google', 'transaction_id' => 'existing-purchase'];
        if ($finalizedExists) {
            $purchase['finalized_at'] = '2026-09-12 10:00:00';
        }
        if ($retryExists) {
            $purchase['finalization_retry_at'] = '2026-09-12 11:00:00';
        }
        DB::table('store_purchases')->insert($purchase);

        $binding = [
            'google_account_binding' => str_repeat('a', 64),
            'user_id' => 1,
            'created_at' => '2026-09-12 09:00:00',
        ];
        if ($accountsExist) {
            Schema::create('store_billing_accounts', function (Blueprint $table) use ($foreignKeyExists): void {
                // MySQL creates the primary key atomically with the columns,
                // then Laravel emits the foreign key as a separate statement.
                $table->char('google_account_binding', 64)->primary();
                $table->foreignId('user_id');
                $table->timestamp('created_at');
                if ($foreignKeyExists) {
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                }
            });
            DB::table('store_billing_accounts')->insert($binding);
        }

        $migration = require database_path('migrations/2026_09_12_000001_add_store_billing_recovery.php');
        $migration->up();
        $migration->up();

        self::assertFalse($migration->withinTransaction);
        self::assertTrue(Schema::hasColumns('store_purchases', ['finalized_at', 'finalization_retry_at']));
        self::assertTrue(Schema::hasIndex('store_purchases', 'store_finalization_recovery'));
        self::assertTrue(Schema::hasIndex('store_purchases', ['provider', 'transaction_id'], 'unique'));
        self::assertTrue(Schema::hasIndex('store_billing_accounts', ['google_account_binding'], 'primary'));
        self::assertTrue(collect(Schema::getForeignKeys('store_billing_accounts'))->contains(
            static fn (array $key): bool => $key['columns'] === ['user_id']
                && $key['foreign_table'] === 'users'
                && $key['foreign_columns'] === ['id']
                && $key['on_delete'] === 'cascade'
        ));
        self::assertSame([
            ...$purchase,
            'finalized_at' => $purchase['finalized_at'] ?? null,
            'finalization_retry_at' => $purchase['finalization_retry_at'] ?? null,
        ], (array) DB::table('store_purchases')->sole());
        self::assertSame($accountsExist ? [$binding] : [], DB::table('store_billing_accounts')->get()->map(
            static fn (object $row): array => (array) $row
        )->all());

        if (!$accountsExist) {
            DB::table('store_billing_accounts')->insert($binding);
        }
        try {
            DB::table('store_billing_accounts')->insert([...$binding, 'user_id' => 2]);
            self::fail('A binding was reassigned to a different user after migration resume.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('store_billing_accounts')->count());
        }
        // Rotation may add another binding for the same user; user_id is not unique.
        DB::table('store_billing_accounts')->insert([...$binding, 'google_account_binding' => str_repeat('b', 64)]);
        self::assertSame(2, DB::table('store_billing_accounts')->where('user_id', 1)->count());
        DB::table('users')->where('id', 1)->delete();
        self::assertSame(0, DB::table('store_billing_accounts')->count());
        self::assertSame(1, DB::table('store_purchases')->count());
    }
}
