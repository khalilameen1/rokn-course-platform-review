<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CourseCatalogueRevisionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class CourseCatalogueRevisionOwnershipTest extends TestCase
{
    private const KEY = 'courses:catalog-revision';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['cache.default' => 'array']);
        Cache::forget(self::KEY);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    public function test_reader_seed_is_stable_and_immediate_writers_increment_the_existing_generation(): void
    {
        $revisions = app(CourseCatalogueRevisionService::class);
        $seed = $revisions->current();
        self::assertGreaterThan(1_000_000_000_000, $seed);
        self::assertSame($seed, $revisions->current());
        $revisions->invalidateAfterCommit();
        $revisions->invalidateAfterCommit();
        self::assertSame($seed + 2, $revisions->current());
    }

    public function test_nested_commit_cannot_publish_the_generation_before_the_outer_commit(): void
    {
        Cache::forever(self::KEY, 100);
        $revisions = app(CourseCatalogueRevisionService::class);
        DB::beginTransaction();
        DB::transaction(function () use ($revisions): void {
            $revisions->invalidateAfterCommit();
            self::assertSame(100, $revisions->current());
        });
        self::assertSame(100, $revisions->current());
        DB::commit();
        self::assertSame(101, $revisions->current());
    }

    public function test_outer_rollback_discards_all_pending_invalidations(): void
    {
        Cache::forever(self::KEY, 200);
        $revisions = app(CourseCatalogueRevisionService::class);
        DB::beginTransaction();
        $revisions->invalidateAfterCommit();
        DB::transaction(fn () => $revisions->invalidateAfterCommit());
        DB::rollBack();
        self::assertSame(200, $revisions->current());
        // A later unrelated commit must not publish the discarded callbacks.
        DB::transaction(static fn () => null);
        self::assertSame(200, $revisions->current());
    }

    public function test_inner_rollback_preserves_only_the_outer_mutations_invalidation(): void
    {
        Cache::forever(self::KEY, 300);
        $revisions = app(CourseCatalogueRevisionService::class);
        DB::beginTransaction();
        $revisions->invalidateAfterCommit();
        DB::beginTransaction();
        $revisions->invalidateAfterCommit();
        DB::rollBack();
        self::assertSame(300, $revisions->current());
        DB::commit();
        self::assertSame(301, $revisions->current());
    }

    public function test_unavailable_cache_is_a_metadata_fallback_not_a_failed_durable_mutation(): void
    {
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache unavailable'));
        $revisions = app(CourseCatalogueRevisionService::class);
        self::assertSame(1, $revisions->current());
        $reported = [];
        DB::beginTransaction();
        $revisions->invalidateAfterCommit(static function (Throwable $error) use (&$reported): void {
            $reported[] = $error->getMessage();
        });
        self::assertSame([], $reported);
        DB::commit();
        self::assertSame(['cache unavailable'], $reported);
        // Plain model events do not need an operational reporter.
        $revisions->invalidateAfterCommit();
    }

    public function test_a_reporting_failure_cannot_turn_a_successful_commit_into_a_failure(): void
    {
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache unavailable'));
        $reported = false;
        DB::transaction(function () use (&$reported): void {
            app(CourseCatalogueRevisionService::class)->invalidateAfterCommit(
                static function () use (&$reported): never {
                    $reported = true;
                    throw new RuntimeException('reporting unavailable too');
                }
            );
        });
        self::assertTrue($reported);
        self::assertSame(0, DB::transactionLevel());
    }
}
