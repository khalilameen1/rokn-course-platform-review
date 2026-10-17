<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\DatabaseCapabilities;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SettingRuntimeConfigCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DatabaseCapabilities::flush();
        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('site_name_ar')->nullable();
            $table->string('site_name_en')->nullable();
            $table->text('bunny_api_key_secret')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DatabaseCapabilities::flush();
        parent::tearDown();
    }

    public function test_projection_is_served_from_cache_on_later_requests(): void
    {
        Setting::query()->create(['site_name_ar' => 'ركن', 'site_name_en' => 'Rokn']);

        self::assertSame('ركن', Setting::runtimeConfig()['site_name_ar']);

        // A new PHP-FPM request starts with empty static state.
        DatabaseCapabilities::flush();
        $queries = $this->settingsQueries(static fn () => Setting::runtimeConfig());

        self::assertSame([], $queries);
        self::assertSame('Rokn', Setting::runtimeConfig()['site_name_en']);
    }

    public function test_hidden_credentials_never_enter_the_cached_projection(): void
    {
        Setting::query()->create(['site_name_ar' => 'ركن', 'bunny_api_key_secret' => 'secret-value']);

        self::assertArrayNotHasKey('bunny_api_key_secret', Setting::runtimeConfig());
    }

    public function test_save_and_delete_invalidate_the_cached_projection(): void
    {
        $setting = Setting::query()->create(['site_name_ar' => 'قديم']);
        self::assertSame('قديم', Setting::runtimeConfig()['site_name_ar']);

        $setting->update(['site_name_ar' => 'جديد']);
        DatabaseCapabilities::flush();
        self::assertSame('جديد', Setting::runtimeConfig()['site_name_ar']);

        $setting->delete();
        DatabaseCapabilities::flush();
        self::assertSame([], Setting::runtimeConfig());
    }

    public function test_migration_writes_are_visible_after_migrations_end(): void
    {
        Setting::query()->create(['site_name_ar' => 'قبل']);
        self::assertSame('قبل', Setting::runtimeConfig()['site_name_ar']);

        // Data migrations use the query builder and fire no model events.
        DB::table('settings')->update(['site_name_ar' => 'بعد']);
        event(new MigrationsEnded('up'));

        self::assertSame('بعد', Setting::runtimeConfig()['site_name_ar']);
    }

    public function test_missing_table_returns_empty_projection_without_caching_it(): void
    {
        Schema::drop('settings');
        self::assertSame([], Setting::runtimeConfig());

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('site_name_ar')->nullable();
            $table->timestamps();
        });
        DB::table('settings')->insert(['site_name_ar' => 'ركن']);

        self::assertSame('ركن', Setting::runtimeConfig()['site_name_ar']);
    }

    /** @return list<string> */
    private function settingsQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();
        } finally {
            $log = DB::getQueryLog();
            DB::disableQueryLog();
        }

        return array_values(array_filter(
            array_map(static fn (array $entry): string => (string) $entry['query'], $log),
            static fn (string $sql): bool => str_contains($sql, 'settings') || str_contains($sql, 'sqlite_master')
        ));
    }
}
