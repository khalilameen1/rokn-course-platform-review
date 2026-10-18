<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Dispatches a durable job without stranding Laravel's pre-push unique lock. */
final class DurableJobDispatch
{
    public static function now(object $job): void
    {
        self::dispatch($job);
    }

    public static function afterCommit(object $job): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(static function () use ($job): void {
                try {
                    self::now($job);
                } catch (Throwable $exception) {
                    // The caller's database fact is already committed. Its
                    // recovery loop owns redelivery when the broker returns.
                    report($exception);
                }
            });

            return;
        }

        self::now($job);
    }

    private static function dispatch(object $job): void
    {
        $lock = null;
        $acquired = false;
        $contextAdded = false;
        try {
            if ($job instanceof ShouldBeUnique) {
                $lock = new UniqueLock(app(CacheRepository::class));
                $acquired = $lock->acquire($job);
                if (! $acquired) {
                    return;
                }
                Context::addHidden([
                    'laravel_unique_job_cache_store' => method_exists($job, 'uniqueVia')
                        ? $job->uniqueVia()->getName()
                        : config('cache.default'),
                    'laravel_unique_job_key' => UniqueLock::getKey($job),
                ]);
                $contextAdded = true;
            }

            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $exception) {
            if ($acquired && $lock) {
                try {
                    $lock->release($job);
                } catch (Throwable $releaseException) {
                    report($releaseException);
                }
            }

            throw $exception;
        } finally {
            if ($contextAdded) {
                Context::forgetHidden([
                    'laravel_unique_job_cache_store',
                    'laravel_unique_job_key',
                ]);
            }
        }
    }
}
