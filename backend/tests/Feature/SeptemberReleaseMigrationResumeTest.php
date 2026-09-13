<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SeptemberReleaseMigrationResumeTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge();
        parent::tearDown();
    }

    public static function interruptedShapes(): array
    {
        $migrations = [
            '2026_09_12_000010_add_apple_revocation_credentials_to_social_accounts' => ['social_accounts', [
                'apple_refresh_token' => ['text', null, 'encrypted-fixture-token'],
                'apple_client_id' => ['string', null, 'test.client'],
            ]],
            '2026_09_12_230000_add_ai_consent_to_users' => ['users', [
                'ai_consent_version' => ['string', null, 'third-party-ai-v1'],
                'ai_consent_accepted_at' => ['timestamp', null, '2026-09-12 10:00:00'],
            ]],
            '2026_09_12_230100_add_portfolio_sharing_suspension' => ['users', [
                'portfolio_sharing_suspended_at' => ['timestamp', null, '2026-09-12 10:00:00'],
            ]],
            '2026_09_13_180000_add_portfolio_prepublication_review' => ['users', [
                'portfolio_sharing_status' => ['string', 'pending', 'rejected'],
                'portfolio_sharing_revision' => ['unsignedBigInteger', 1, 7],
                'portfolio_approved_hash' => ['char', null, str_repeat('a', 64)],
                'portfolio_reviewed_at' => ['timestamp', null, '2026-09-12 10:00:00'],
                'portfolio_reviewed_by' => ['unsignedBigInteger', null, 2],
                'portfolio_sharing_rejection_reason' => ['text', null, 'Existing review reason'],
            ]],
        ];

        $cases = [];
        foreach ($migrations as $migration => [$table, $columns]) {
            for ($completed = 0; $completed <= count($columns); $completed++) {
                $cases[$migration . ' after ' . $completed . ' columns'] = [$migration, $table, $columns, $completed, false];
            }
            if (array_key_exists('portfolio_sharing_status', $columns)) {
                $cases[$migration . ' after index'] = [$migration, $table, $columns, count($columns), true];
            }
        }

        return $cases;
    }

    #[DataProvider('interruptedShapes')]
    public function test_forward_resume_preserves_existing_values_and_safe_defaults(
        string $file,
        string $tableName,
        array $columns,
        int $completed,
        bool $indexExists,
    ): void {
        $existingColumns = array_slice($columns, 0, $completed, true);
        Schema::create($tableName, function (Blueprint $table) use ($existingColumns, $indexExists): void {
            $table->id();
            $table->string('existing_identity')->unique();
            foreach ($existingColumns as $name => [$type, $default]) {
                $column = $table->{$type}($name);
                if ($default === null) {
                    $column->nullable();
                } else {
                    $column->default($default);
                }
            }
            if ($indexExists) {
                $table->index('portfolio_sharing_status');
            }
        });
        $existingRow = ['id' => 1, 'existing_identity' => 'retained-identity'];
        foreach ($existingColumns as $name => [, , $value]) {
            $existingRow[$name] = $value;
        }
        DB::table($tableName)->insert($existingRow);

        $migration = require database_path('migrations/' . $file . '.php');
        $migration->up();
        $migration->up();

        self::assertFalse($migration->withinTransaction);
        self::assertTrue(Schema::hasColumns($tableName, array_keys($columns)));
        self::assertTrue(Schema::hasIndex($tableName, ['existing_identity'], 'unique'));
        $expectedRow = $existingRow;
        foreach ($columns as $name => [, $default]) {
            if (!array_key_exists($name, $expectedRow)) {
                $expectedRow[$name] = $default;
            }
        }
        self::assertSame($expectedRow, (array) DB::table($tableName)->sole());

        DB::table($tableName)->insert(['id' => 2, 'existing_identity' => 'new-identity']);
        $newRow = (array) DB::table($tableName)->where('id', 2)->sole();
        foreach ($columns as $name => [, $default]) {
            self::assertSame($default, $newRow[$name], 'Changed safe default for ' . $name);
        }
        if (array_key_exists('portfolio_sharing_status', $columns)) {
            self::assertTrue(Schema::hasIndex($tableName, ['portfolio_sharing_status']));
            self::assertSame(1, collect(Schema::getIndexes($tableName))->filter(
                static fn (array $index): bool => $index['columns'] === ['portfolio_sharing_status']
            )->count());
        }
    }
}
