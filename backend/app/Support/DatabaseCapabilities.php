<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Schema capabilities for rolling-deploy compatibility.
 *
 * Schema inspection queries information_schema and must not run on learner
 * hot paths. PHP-FPM discards static state after every request, so the
 * process-local map is only a first level; the shared application cache is
 * the second level that makes one inspection serve every later request.
 *
 * Cache entries are namespaced by a schema cache version which changes when
 *  - `php artisan migrate`/rollback finishes (Illuminate MigrationsEnded
 *    bumps a generation in the shared cache), and
 *  - the deployed migrations directory changes (its mtime), so an
 *    instance-local cache store never carries capabilities across releases.
 * A missing table/column is cached only briefly: during a rolling deploy new
 * code may serve before its migration, and that negative answer must heal
 * itself without a manual cache flush.
 */
final class DatabaseCapabilities
{
    private const CACHE_PREFIX = 'db-capabilities:v1:';
    private const GENERATION_KEY = 'db-capabilities:generation';
    private const NEGATIVE_TTL_SECONDS = 60;

    /** @var array<string, bool> */
    private static array $tables = [];

    /** @var array<string, bool> */
    private static array $columns = [];

    private static ?string $cacheVersion = null;

    public static function hasTable(string $table): bool
    {
        if (self::schemaIsMutableInProcess()) {
            return Schema::hasTable($table);
        }
        $key = self::connectionKey() . ':table:' . $table;

        return self::$tables[$key] ??= self::remember($key, static fn (): bool => Schema::hasTable($table));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        if (self::schemaIsMutableInProcess()) {
            return Schema::hasColumn($table, $column);
        }
        $key = self::connectionKey() . ':column:' . $table . ':' . $column;

        return self::$columns[$key] ??= self::remember(
            $key,
            static fn (): bool => Schema::hasColumn($table, $column)
        );
    }

    /** @param list<string> $columns */
    public static function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!self::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /** Clears the process-local level only. */
    public static function flush(): void
    {
        self::$tables = [];
        self::$columns = [];
        self::$cacheVersion = null;
    }

    /**
     * Invalidates every shared capability entry, and every other cache keyed
     * by cacheVersion(), by moving to a new generation. Registered for
     * Illuminate\Database\Events\MigrationsEnded.
     */
    public static function invalidateSharedCache(): void
    {
        self::flush();
        try {
            Cache::forever(self::GENERATION_KEY, bin2hex(random_bytes(8)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Deploy-safe namespace for caches whose content depends on the migrated
     * schema or on data written by migrations (for example settings).
     */
    public static function cacheVersion(): string
    {
        if (self::$cacheVersion !== null) {
            return self::$cacheVersion;
        }

        $generation = '0';
        try {
            $generation = (string) (Cache::get(self::GENERATION_KEY) ?? '0');
        } catch (Throwable) {
            // The store outage is reported by remember(); readers fall back
            // to the schema itself.
        }
        $migrations = @filemtime(database_path('migrations'));

        return self::$cacheVersion = $generation . ':' . ($migrations === false ? '0' : (string) $migrations);
    }

    /** @param Closure(): bool $probe */
    private static function remember(string $key, Closure $probe): bool
    {
        try {
            $cacheKey = self::CACHE_PREFIX . self::cacheVersion() . ':' . $key;
            $cached = Cache::get($cacheKey);
            if (is_bool($cached)) {
                return $cached;
            }
        } catch (Throwable $exception) {
            report($exception);

            return $probe();
        }

        $result = $probe();
        try {
            if ($result) {
                Cache::forever($cacheKey, true);
            } else {
                Cache::put($cacheKey, false, self::NEGATIVE_TTL_SECONDS);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }

    private static function connectionKey(): string
    {
        $connection = DB::connection();

        return $connection->getName() . ':' . (string) $connection->getDatabaseName();
    }

    /**
     * A production worker sees one migrated schema for its whole lifetime.
     * The test runner intentionally creates and drops different partial
     * schemas on the same in-memory connection, so a process-wide negative
     * capability from one test is not evidence about the next one.
     */
    private static function schemaIsMutableInProcess(): bool
    {
        return app()->runningUnitTests();
    }
}
