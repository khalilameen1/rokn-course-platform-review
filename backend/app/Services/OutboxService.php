<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OutboxService
{
    public function record(
        string $topic,
        array $payload,
        ?string $aggregateType = null,
        string|int|null $aggregateId = null,
        ?string $eventKey = null
    ): OutboxEvent {
        $normalizedAggregateId = $aggregateId === null ? null : (string) $aggregateId;
        $event = OutboxEvent::query()->firstOrCreate(
            ['event_key' => $eventKey ?: (string) Str::uuid()],
            [
                'topic' => $topic,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $normalizedAggregateId,
                'payload' => $payload,
                'status' => OutboxEvent::STATUS_PENDING,
                'available_at' => now(),
            ]
        );

        if (! $event->wasRecentlyCreated && (
            ! hash_equals((string) $event->topic, $topic)
            || ($event->aggregate_type === null ? null : (string) $event->aggregate_type) !== $aggregateType
            || ($event->aggregate_id === null ? null : (string) $event->aggregate_id) !== $normalizedAggregateId
            || $this->canonical($event->payload ?? []) !== $this->canonical($payload)
        )) {
            throw new \UnexpectedValueException('Outbox event key was reused for another payload.');
        }

        if ($event->wasRecentlyCreated) {
            DB::afterCommit(static function () use ($event): void {
                try {
                    DeliverOutboxEvent::dispatch($event->id)
                        ->onQueue((string) config('webhooks.queue', 'webhooks'));
                    OutboxEvent::query()->whereKey($event->id)->update([
                        'dispatched_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    // The database row is the durable source of truth. The
                    // scheduled recovery command will redispatch it when the
                    // broker is healthy again.
                    Log::warning('Outbox event dispatch failed after commit.', [
                        'outbox_event_id' => $event->id,
                        'exception' => get_class($exception),
                    ]);
                }
            });
        }

        return $event;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
