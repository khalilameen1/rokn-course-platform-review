<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RewardRule;
use App\Models\Setting;
use App\Support\AfterCommitCacheInvalidation;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AfterCommitCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    public function test_callbacks_wait_for_outer_commit_and_preserve_framework_transaction_order(): void
    {
        $events = [];
        $append = static function (string $event) use (&$events): \Closure {
            return static function () use ($event, &$events): void { $events[] = $event; };
        };
        AfterCommitCacheInvalidation::run($append('immediate'));
        DB::beginTransaction();
        AfterCommitCacheInvalidation::run($append('parent'));
        DB::transaction(static function () use ($append): void {
            AfterCommitCacheInvalidation::run($append('child'));
        });
        AfterCommitCacheInvalidation::run($append('parent-second'));
        self::assertSame(['immediate'], $events);
        DB::commit();
        // Laravel stages a committed child before its parent, while preserving
        // registration order within each transaction. This helper retains that.
        self::assertSame(['immediate', 'child', 'parent', 'parent-second'], $events);
    }

    public function test_rolled_back_writes_do_not_invalidate_the_cache(): void
    {
        $events = [];
        DB::beginTransaction();
        AfterCommitCacheInvalidation::run(static function () use (&$events): void { $events[] = 'outer'; });
        DB::beginTransaction();
        AfterCommitCacheInvalidation::run(static function () use (&$events): void { $events[] = 'rolled-back-inner'; });
        DB::rollBack();
        DB::commit();
        self::assertSame(['outer'], $events);
        DB::beginTransaction();
        AfterCommitCacheInvalidation::run(static function () use (&$events): void { $events[] = 'rolled-back-outer'; });
        DB::rollBack();
        DB::transaction(static fn () => null);
        self::assertSame(['outer'], $events);
    }

    public function test_execution_and_reporting_errors_do_not_interrupt_later_callbacks(): void
    {
        $events = [];
        DB::beginTransaction();
        AfterCommitCacheInvalidation::run(static function (): never {
            throw new \RuntimeException('cache down');
        }, static function (\Throwable $error) use (&$events): never {
            $events[] = $error->getMessage();
            throw new \RuntimeException('reporter down');
        });
        AfterCommitCacheInvalidation::run(static function () use (&$events): void { $events[] = 'next cache'; });
        self::assertSame([], $events);
        DB::commit();
        self::assertSame(['cache down', 'next cache'], $events);
        AfterCommitCacheInvalidation::run(static function (): never { throw new \RuntimeException('optional silent cache'); });
    }

    public function test_settings_and_reward_model_events_survive_post_commit_cache_outages(): void
    {
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache unavailable'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('cache unavailable'));
        $handler = \Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->times(3)->andReturnNull();
        $this->app->instance(ExceptionHandler::class, $handler);
        DB::transaction(static function (): void {
            $settings = Setting::query()->first() ?? new Setting();
            $settings->fill(['site_name_ar' => 'رُكن'])->save();
            RewardRule::query()->updateOrCreate(['event_key' => 'welcome_bonus'], [
                'title_ar' => 'ترحيب', 'title_en' => 'Welcome',
                'coins_amount' => 10, 'interval_count' => 1, 'daily_cap' => 1,
                'rolling_30_day_cap' => 1, 'is_active' => true, 'sort_order' => 1,
            ]);
        });
        self::assertSame('رُكن', Setting::query()->firstOrFail()->site_name_ar);
        self::assertSame(10, RewardRule::query()->where('event_key', 'welcome_bonus')->sole()->coins_amount);
        self::assertSame(0, DB::transactionLevel());
    }
}
