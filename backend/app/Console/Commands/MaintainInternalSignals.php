<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessInternalSignal;
use App\Models\InternalSignal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MaintainInternalSignals extends Command
{
    protected $signature = 'internal-signals:maintain {--limit=500} {--prune=0}';

    protected $description = 'Recover stale internal effects and dispatch due durable signals.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $pruneLimit = max(0, min(20000, (int) $this->option('prune')));
        $staleBefore = now()->subMinutes(5);
        InternalSignal::query()
            ->where('status', InternalSignal::STATUS_PROCESSING)
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
            })
            ->orderBy('id')
            ->limit($limit)
            ->update([
                'status' => InternalSignal::STATUS_PENDING,
                'available_at' => now(),
                'locked_at' => null,
                'lease_id' => null,
                'updated_at' => now(),
            ]);

        $signals = InternalSignal::query()
            ->where('status', InternalSignal::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', $staleBefore);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'type']);

        $dispatched = 0;
        foreach ($signals as $signal) {
            try {
                ProcessInternalSignal::dispatch((int) $signal->id, (string) $signal->type)
                    ->onQueue(ProcessInternalSignal::queueForType((string) $signal->type));
                InternalSignal::query()->whereKey($signal->id)->update([
                    'dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
                $dispatched++;
            } catch (\Throwable $exception) {
                Log::warning('Internal signal dispatch failed.', [
                    'signal_id' => $signal->id,
                    'exception' => $exception::class,
                ]);
                break;
            }
        }

        $pruned = 0;
        if ($pruneLimit > 0) {
            $pruneIds = InternalSignal::query()
                ->where('status', InternalSignal::STATUS_HANDLED)
                ->where('handled_at', '<=', now()->subDays(120))
                ->orderBy('id')
                ->limit($pruneLimit)
                ->pluck('id');
            if ($pruneIds->isNotEmpty()) {
                $pruned = DB::table('internal_signals')->whereIn('id', $pruneIds)->delete();
            }
        }

        $this->info(
            "Dispatched {$dispatched} and pruned {$pruned} durable internal signal(s)."
        );

        return self::SUCCESS;
    }
}
