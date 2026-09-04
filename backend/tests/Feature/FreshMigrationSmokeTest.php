<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FreshMigrationSmokeTest extends TestCase
{
    protected function tearDown(): void
    {
        // Closing the in-memory SQLite connection discards the full schema so
        // this smoke test cannot leak tables into hand-built legacy test cases.
        DB::purge();
        parent::tearDown();
    }

    public function test_release_schema_migrates_from_an_empty_sqlite_database(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);

        foreach ([
            'courses',
            'course_codes',
            'course_modules',
            'course_sections',
            'course_enrollments',
            'project_submissions',
            'wallet_transactions',
            'certificates',
            'student_notifications',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing release table: {$table}");
        }

        foreach ([
            'exam_security_logs',
            'exam_answers',
            'exams_result_details',
            'exams_result_detailss',
            'exams_results',
            'exam_attempts',
            'questions',
            'random_quizzes',
            'quizzes',
            'exams',
            'lists',
        ] as $table) {
            self::assertFalse(Schema::hasTable($table), "Retired exam table still exists: {$table}");
        }

        self::assertFalse(Schema::hasColumn('lessons', 'quiz_id'));
        self::assertFalse(Schema::hasColumn('courses', 'questions_count'));
        self::assertFalse(Schema::hasColumn('courses', 'exam_count'));

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $this->assertSqliteForeignKey('course_codes', 'course_id', 'courses');
        $this->assertSqliteForeignKey('bills', 'course_id', 'courses');
        $this->assertSqliteForeignKey('course_enrollments', 'course_id', 'courses');
        $this->assertSqliteForeignKey('course_modules', 'course_id', 'courses');
        $this->assertSqliteForeignKey('course_sections', 'course_id', 'courses');
    }

    private function assertSqliteForeignKey(string $table, string $column, string $target): void
    {
        $foreignKeys = DB::select("PRAGMA foreign_key_list('{$table}')");
        $matches = array_filter($foreignKeys, static function (object $foreignKey) use ($column, $target): bool {
            return ($foreignKey->from ?? null) === $column
                && ($foreignKey->table ?? null) === $target;
        });

        self::assertNotEmpty(
            $matches,
            "Missing SQLite foreign key {$table}.{$column} -> {$target}.id"
        );
    }
}
