<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CleanupDeletedAccountPortfolioMedia;
use App\Models\User;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class DispatchPendingPortfolioCleanup extends Command
{
    protected $signature = 'privacy:cleanup-portfolio-media {--limit=500}';

    protected $description = 'Retry Bunny cleanup for portfolio media belonging to deleted accounts';

    public function handle(): int
    {
        $remaining = max(1, min(5000, (int) $this->option('limit')));
        $dispatched = 0;

        User::onlyTrashed()
            ->whereHas('portfolioItems.mediaFiles')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$remaining, &$dispatched): bool {
                foreach ($users as $user) {
                    if ($remaining-- <= 0) {
                        return false;
                    }

                    try {
                        DurableJobDispatch::now(
                            new CleanupDeletedAccountPortfolioMedia((int) $user->id)
                        );
                        $dispatched++;
                    } catch (\Throwable $exception) {
                        // A sync queue or unavailable broker must not lose the
                        // durable media references; the next schedule retries.
                        Log::warning('Unable to dispatch deleted portfolio cleanup.', [
                            'deleted_user_id' => $user->id,
                            'exception' => get_class($exception),
                        ]);
                    }
                }

                return $remaining > 0;
            });

        $this->info("Queued {$dispatched} deleted-account portfolio cleanup job(s).");

        return self::SUCCESS;
    }
}
