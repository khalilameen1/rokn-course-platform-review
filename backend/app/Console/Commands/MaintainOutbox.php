<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MaintainOutbox extends Command
{
    protected $signature = 'outbox:maintain {--dispatch=500} {--prune=0}';

    protected $description = 'Recover stale outbox claims, dispatch due events and prune terminal rows.';

    public function handle(): int
    {
        $dispatchLimit = max(0, min(5000, (int) $this->option('dispatch')));
        $pruneLimit = max(0, min(10000, (int) $this->option('prune')));
        $staleBefore = now()->subSeconds(max(30, (int) config('webhooks.claim_stale_seconds', 180)));

        $staleIds = OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_PROCESSING)
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
            })
            ->orderBy('id')
            ->limit($dispatchLimit)
            ->pluck('id');
        if ($staleIds->isNotEmpty()) {
            OutboxEvent::query()->whereIn('id', $staleIds)->update([
                'status' => OutboxEvent::STATUS_PENDING,
                'locked_at' => null,
                'dispatched_at' => null,
                'available_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Aggregate events are emitted in database order. A later event waits
        // quietly behind an unfinished predecessor instead of burning queue
        // retries; once the predecessor is delivered it becomes due again.
        $unblocked = 0;
        $blocked = OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_BLOCKED)
            ->orderBy('id')
            ->limit($dispatchLimit)
            ->get(['id', 'aggregate_type', 'aggregate_id']);
        foreach ($blocked as $event) {
            $hasPredecessor = OutboxEvent::query()
                ->where('aggregate_type', $event->aggregate_type)
                ->where('aggregate_id', $event->aggregate_id)
                ->where('id', '<', $event->id)
                ->whereNotIn('status', [
                    OutboxEvent::STATUS_DELIVERED,
                    OutboxEvent::STATUS_SKIPPED,
                ])
                ->exists();
            if ($hasPredecessor) {
                continue;
            }
            $unblocked += OutboxEvent::query()
                ->whereKey($event->id)
                ->where('status', OutboxEvent::STATUS_BLOCKED)
                ->update([
                    'status' => OutboxEvent::STATUS_PENDING,
                    'available_at' => now(),
                    'dispatched_at' => null,
                    'last_error_fingerprint' => null,
                    'updated_at' => now(),
                ]);
        }

        $leaseBefore = now()->subSeconds(max(60, (int) config('webhooks.claim_stale_seconds', 180)));
        $dueIds = OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->where(function ($query) use ($leaseBefore): void {
                $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', $leaseBefore);
            })
            ->orderBy('id')
            ->limit($dispatchLimit)
            ->pluck('id');

        $dispatched = 0;
        foreach ($dueIds as $id) {
            try {
                DeliverOutboxEvent::dispatch((int) $id)
                    ->onQueue((string) config('webhooks.queue', 'webhooks'));
                OutboxEvent::query()->whereKey($id)->update([
                    'dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
                $dispatched++;
            } catch (\Throwable $exception) {
                Log::warning('Scheduled outbox dispatch failed.', [
                    'outbox_event_id' => $id,
                    'exception' => get_class($exception),
                ]);
                break;
            }
        }

        $pruned = $pruneLimit > 0 ? $this->prune($pruneLimit) : 0;
        $this->info(sprintf(
            'Recovered %d stale claim(s), unblocked %d ordered event(s), dispatched %d event(s), pruned %d terminal event(s).',
            $staleIds->count(),
            $unblocked,
            $dispatched,
            $pruned
        ));

        return self::SUCCESS;
    }

    private function prune(int $limit): int
    {
        $deliveredBefore = now()->subDays(max(1, (int) config('retention.outbox_delivered_days', 30)));
        $failedBefore = now()->subDays(max(1, (int) config('retention.outbox_failed_days', 180)));
        $ids = OutboxEvent::query()
            ->where(function ($query) use ($deliveredBefore, $failedBefore): void {
                $query->where(function ($delivered) use ($deliveredBefore): void {
                    $delivered->where('status', OutboxEvent::STATUS_DELIVERED)
                        ->where('delivered_at', '<=', $deliveredBefore);
                })->orWhere(function ($failed) use ($failedBefore): void {
                    $failed->whereIn('status', [
                        OutboxEvent::STATUS_FAILED,
                        OutboxEvent::STATUS_SKIPPED,
                    ])
                        ->where('updated_at', '<=', $failedBefore);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($ids): void {
            DB::table('webhook_deliveries')->whereIn('outbox_event_id', $ids)->delete();
            DB::table('outbox_events')->whereIn('id', $ids)->delete();
        });

        return $ids->count();
    }
}
