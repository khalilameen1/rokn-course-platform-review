<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PruneRateLimitEvents extends Command
{
    protected $signature = 'operations:prune-rate-limits {--days=30}';

    protected $description = 'Prune aggregated rate-limit evidence after its operational retention window';

    public function handle(): int
    {
        if (! Schema::hasTable('rate_limit_events')) {
            return self::SUCCESS;
        }

        $before = now()->subDays(max(7, min(180, (int) $this->option('days'))));
        $deleted = 0;
        do {
            $ids = DB::table('rate_limit_events')
                ->where('window_started_at', '<', $before)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');
            $batch = $ids->isEmpty()
                ? 0
                : DB::table('rate_limit_events')->whereIn('id', $ids)->delete();
            $deleted += $batch;
        } while ($batch === 1000);

        $this->info("Pruned {$deleted} rate-limit aggregate row(s).");

        return self::SUCCESS;
    }
}
