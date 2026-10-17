<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DatabaseCapabilities;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DatabaseCapabilitiesTest extends TestCase
{
    public function test_capabilities_follow_schema_changes_inside_one_test_process(): void
    {
        Schema::dropIfExists('capability_probe');
        self::assertFalse(DatabaseCapabilities::hasTable('capability_probe'));

        Schema::create('capability_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('state');
        });

        self::assertTrue(DatabaseCapabilities::hasTable('capability_probe'));
        self::assertTrue(DatabaseCapabilities::hasColumn('capability_probe', 'state'));

        Schema::drop('capability_probe');
        self::assertFalse(DatabaseCapabilities::hasTable('capability_probe'));
        self::assertFalse(DatabaseCapabilities::hasColumn('capability_probe', 'state'));
    }

    public function test_production_requests_share_capabilities_through_the_application_cache(): void
    {
        Schema::dropIfExists('capability_probe');
        Schema::create('capability_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('state');
        });

        $this->asLongLivedSchema(function (): void {
            self::assertTrue(DatabaseCapabilities::hasTable('capability_probe'));
            self::assertTrue(DatabaseCapabilities::hasColumn('capability_probe', 'state'));

            // The next PHP-FPM request has no static state but must not
            // inspect the schema again.
            DatabaseCapabilities::flush();
            self::assertSame(0, $this->schemaQueries(static function (): void {
                self::assertTrue(DatabaseCapabilities::hasTable('capability_probe'));
                self::assertTrue(DatabaseCapabilities::hasColumn('capability_probe', 'state'));
            }));
        });
    }

    public function test_missing_capabilities_expire_and_migrations_start_a_new_namespace(): void
    {
        Schema::dropIfExists('capability_probe');

        $this->asLongLivedSchema(function (): void {
            self::assertFalse(DatabaseCapabilities::hasTable('capability_probe'));

            Schema::create('capability_probe', function (Blueprint $table): void {
                $table->id();
            });

            // Code that served before its migration heals without a flush.
            DatabaseCapabilities::flush();
            $this->travel(61)->seconds();
            self::assertTrue(DatabaseCapabilities::hasTable('capability_probe'));

            Schema::drop('capability_probe');
            DatabaseCapabilities::flush();
            self::assertTrue(DatabaseCapabilities::hasTable('capability_probe'));

            event(new MigrationsEnded('down'));
            self::assertFalse(DatabaseCapabilities::hasTable('capability_probe'));
        });
    }

    public function test_cache_outage_falls_back_to_schema_inspection(): void
    {
        Schema::dropIfExists('capability_probe');
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache unavailable'));

        $this->asLongLivedSchema(function (): void {
            self::assertFalse(DatabaseCapabilities::hasTable('capability_probe'));
        });
    }

    private function asLongLivedSchema(callable $callback): void
    {
        $environment = $this->app['env'];
        $this->app['env'] = 'production';
        DatabaseCapabilities::flush();
        try {
            $callback();
        } finally {
            $this->app['env'] = $environment;
            DatabaseCapabilities::flush();
        }
    }

    private function schemaQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();
        } finally {
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $count;
    }
}
