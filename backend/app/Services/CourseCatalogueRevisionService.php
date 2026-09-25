<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use App\Support\AfterCommitCacheInvalidation;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Shared catalogue generation for readers, model events and explicit bulk mutations. */
final class CourseCatalogueRevisionService
{
    private const KEY = 'courses:catalog-revision';

    public function current(): int
    {
        try {
            $this->seed();

            return max(1, (int) Cache::get(self::KEY));
        } catch (Throwable) {
            // Cached pages are unavailable in the same failure mode. This is
            // response metadata only, not permission to resurrect an old page.
            return 1;
        }
    }

    /**
     * Schedule against the outermost transaction, or execute immediately outside one.
     * Cache failures must not make a durable mutation appear to have failed.
     * @param null|Closure(Throwable):void $onFailure Optional operational reporting.
     */
    public function invalidateAfterCommit(?Closure $onFailure = null): void
    {
        AfterCommitCacheInvalidation::run(function (): void {
            $this->seed();
            // Atomic increment prevents concurrent writers overwriting each other.
            Cache::increment(self::KEY);
        }, $onFailure);
    }

    private function seed(): void
    {
        // add() is atomic. A time-ordered seed avoids reusing generation 1 when
        // Redis evicts the revision key while an older page key still survives.
        Cache::add(
            self::KEY,
            max(1, (int) floor(microtime(true) * 1000)),
            now()->addYears(10)
        );
    }
}
