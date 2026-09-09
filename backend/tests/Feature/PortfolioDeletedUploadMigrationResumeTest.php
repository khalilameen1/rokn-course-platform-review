<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PortfolioDeletedUploadMigrationResumeTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge();
        parent::tearDown();
    }

    public static function interruptedShapes(): array
    {
        return [['absent'], ['columns_only'], ['foreign_only'], ['unique_only'], ['complete']];
    }

    #[DataProvider('interruptedShapes')]
    public function test_migration_resumes_without_losing_receipts_or_constraints(string $shape): void
    {
        Schema::create('portfolio_items', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('portfolio_items')->insert([['id' => 1], ['id' => 2]]);

        if ($shape !== 'absent') {
            Schema::create('portfolio_deleted_uploads', function (Blueprint $table) use ($shape): void {
                $table->id();
                $table->foreignId('portfolio_item_id');
                $table->uuid('client_request_id');
                if (in_array($shape, ['foreign_only', 'complete'], true)) {
                    $table->foreign('portfolio_item_id')->references('id')->on('portfolio_items')->cascadeOnDelete();
                }
                if (in_array($shape, ['unique_only', 'complete'], true)) {
                    $table->unique(['portfolio_item_id', 'client_request_id'], 'portfolio_deleted_upload_request_unique');
                }
            });
            DB::table('portfolio_deleted_uploads')->insert([
                'portfolio_item_id' => 1,
                'client_request_id' => '11111111-1111-4111-8111-111111111111',
            ]);
        }

        $migration = require database_path('migrations/2026_09_09_000001_create_portfolio_deleted_uploads_table.php');
        $migration->up();
        $migration->up();

        self::assertTrue(Schema::hasColumns('portfolio_deleted_uploads', ['id', 'portfolio_item_id', 'client_request_id']));
        self::assertTrue(Schema::hasIndex('portfolio_deleted_uploads', ['portfolio_item_id', 'client_request_id'], 'unique'));
        self::assertTrue(collect(Schema::getForeignKeys('portfolio_deleted_uploads'))->contains(
            static fn (array $key): bool => $key['columns'] === ['portfolio_item_id']
                && $key['foreign_table'] === 'portfolio_items'
                && $key['foreign_columns'] === ['id']
                && $key['on_delete'] === 'cascade'
        ));
        self::assertSame($shape === 'absent' ? 0 : 1, DB::table('portfolio_deleted_uploads')->count());

        if ($shape === 'absent') {
            DB::table('portfolio_deleted_uploads')->insert([
                'portfolio_item_id' => 1,
                'client_request_id' => '11111111-1111-4111-8111-111111111111',
            ]);
        }
        try {
            DB::table('portfolio_deleted_uploads')->insert([
                'portfolio_item_id' => 1,
                'client_request_id' => '11111111-1111-4111-8111-111111111111',
            ]);
            self::fail('Duplicate receipt was admitted after migration resume.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('portfolio_deleted_uploads')->count());
        }
        DB::table('portfolio_deleted_uploads')->insert([
            'portfolio_item_id' => 2,
            'client_request_id' => '11111111-1111-4111-8111-111111111111',
        ]);
        DB::table('portfolio_items')->where('id', 1)->delete();
        self::assertSame([2], DB::table('portfolio_deleted_uploads')->pluck('portfolio_item_id')->all());
    }
}
