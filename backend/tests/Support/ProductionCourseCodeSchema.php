<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProductionCourseCodeSchema
{
    /** Mirror retired-column MySQL migrations in the isolated SQLite test schema. */
    public static function applySqliteBridge(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') return;
        if (!app()->environment('testing') || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \LogicException('The course-code schema bridge is restricted to in-memory tests.');
        }
        // Historical SQLite migrations skip table rebuilds to preserve inbound
        // foreign keys. Native DROP COLUMN removes only these retired fields.
        foreach (['course_codes', 'course_code_usages', 'course_enrollments'] as $table) {
            if (!Schema::hasColumn($table, 'tenant_id')) continue;
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['columns'] === ['tenant_id']) {
                    DB::statement('DROP INDEX "'.str_replace('"', '""', $index['name']).'"');
                }
            }
            DB::statement('ALTER TABLE "'.$table.'" DROP COLUMN tenant_id');
        }
    }
}
