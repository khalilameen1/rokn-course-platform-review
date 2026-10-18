<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DurableJobDispatch;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class DurableJobDispatchTest extends TestCase
{
    public function test_enqueue_failure_releases_only_the_lock_acquired_by_this_dispatch(): void
    {
        config(['cache.default' => 'array']);
        $cache = Cache::store('array');
        $cache->flush();
        $this->app->instance(CacheRepository::class, $cache);
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('broker down'));
        $job = new DurableUniqueDispatchFixture('failed-push');

        try {
            DurableJobDispatch::now($job);
            self::fail('The queue failure should be visible to the durable owner.');
        } catch (RuntimeException $exception) {
            self::assertSame('broker down', $exception->getMessage());
        }

        $lock = new UniqueLock($cache);
        self::assertTrue($lock->acquire($job));
        $lock->release($job);
    }

    public function test_existing_unique_owner_is_not_dispatched_or_released(): void
    {
        config(['cache.default' => 'array']);
        $cache = Cache::store('array');
        $cache->flush();
        $this->app->instance(CacheRepository::class, $cache);
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');
        $job = new DurableUniqueDispatchFixture('already-owned');
        $lock = new UniqueLock($cache);
        self::assertTrue($lock->acquire($job));

        DurableJobDispatch::now($job);

        self::assertFalse($lock->acquire($job));
        $lock->release($job);
    }
}

final class DurableUniqueDispatchFixture implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(private readonly string $key) {}

    public function uniqueId(): string
    {
        return $this->key;
    }
}
