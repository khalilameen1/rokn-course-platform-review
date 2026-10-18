<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchQueueHeartbeats extends Command
{
    protected $signature = 'ops:dispatch-queue-heartbeats';

    protected $description = 'Dispatch an independent worker heartbeat to every required production queue';

    public function handle(): int
    {
        $queues = RecordQueueHeartbeat::requiredQueues();
        if ($queues === []) {
            $this->error('No required heartbeat queues are configured.');

            return self::FAILURE;
        }

        try {
            Cache::put(
                (string) config('operations.scheduler_heartbeat_key', 'operations:scheduler-heartbeat:v1'),
                now()->toIso8601String(),
                max(60, (int) config('operations.scheduler_heartbeat_ttl_seconds', 600))
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to record scheduler heartbeat.', [
                'exception' => $exception::class,
            ]);
        }

        $failed = [];
        foreach ($queues as $queue) {
            try {
                RecordQueueHeartbeat::dispatch($queue);
            } catch (Throwable $exception) {
                $failed[] = $queue;
                Log::warning('Unable to dispatch queue heartbeat.', [
                    'queue' => $queue,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($failed !== []) {
            $this->error('Heartbeat dispatch failed for: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
