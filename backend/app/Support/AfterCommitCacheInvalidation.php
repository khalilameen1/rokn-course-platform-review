<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Best-effort derived cache updates, never authoritative domain writes. */
final class AfterCommitCacheInvalidation
{
    /** @param Closure():mixed $invalidate
     *  @param null|Closure(Throwable):mixed $onFailure Optional operational reporting.
     */
    public static function run(Closure $invalidate, ?Closure $onFailure = null): void
    {
        $run = static function () use ($invalidate, $onFailure): void {
            try {
                $invalidate();
            } catch (Throwable $exception) {
                if ($onFailure !== null) {
                    try {
                        $onFailure($exception);
                    } catch (Throwable) {
                        // A diagnostic outage cannot change a successful commit.
                    }
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);
        } else {
            $run();
        }
    }
}
