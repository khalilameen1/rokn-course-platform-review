<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class RecordQueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 10;

    public string $heartbeatQueue;

    public function __construct(?string $queue = null)
    {
        $queue = trim((string) ($queue ?? self::defaultQueueName()));
        if ($queue === '') {
            throw new \InvalidArgumentException('A queue heartbeat must target a named queue.');
        }

        $this->heartbeatQueue = $queue;
        $this->onQueue($queue);
    }

    public function handle(): void
    {
        $heartbeat = now()->toIso8601String();
        $ttl = max(60, (int) config('operations.queue_heartbeat_ttl_seconds', 600));

        Cache::put(
            self::cacheKey($this->heartbeatQueue),
            $heartbeat,
            $ttl
        );

        // Keep the original key fresh for older operational consumers while
        // readiness itself uses one independent key per required queue.
        if ($this->heartbeatQueue === self::defaultQueueName()) {
            Cache::put(self::legacyCacheKey(), $heartbeat, $ttl);
        }
    }

    /** @return list<string> */
    public static function requiredQueues(): array
    {
        $configured = config('operations.queue_heartbeat_required_queues', [
            'default',
            'notifications',
            'ai-chat',
            'ai-feedback',
            'media',
            'operations',
            'webhooks',
        ]);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $queue): string => trim((string) $queue),
            $configured
        ))));
    }

    public static function cacheKey(string $queue): string
    {
        return self::legacyCacheKey().':queue:'.rawurlencode($queue);
    }

    public static function legacyCacheKey(): string
    {
        return rtrim((string) config(
            'operations.queue_heartbeat_key',
            'operations:queue-heartbeat:v1'
        ), ':');
    }

    public static function defaultQueueName(): string
    {
        $connection = trim((string) config('queue.default'));
        $queue = trim((string) config("queue.connections.{$connection}.queue", 'default'));

        return $queue !== '' ? $queue : 'default';
    }
}
