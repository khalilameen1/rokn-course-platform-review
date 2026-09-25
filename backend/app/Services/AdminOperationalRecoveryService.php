<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverOutboxEvent;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Manual recovery commands; never replays an arbitrary failed job payload. */
final class AdminOperationalRecoveryService
{
    public function retryOutbox(int $eventId, int|string|null $actorId, string $reason): void
    {
        DB::transaction(function () use ($eventId): void {
            $event = $this->failedEventForUpdate($eventId);
            WebhookDelivery::query()
                ->where('outbox_event_id', $event->id)
                ->where('status', WebhookDelivery::STATUS_FAILED)
                ->update([
                    'status' => WebhookDelivery::STATUS_PENDING,
                    'available_at' => now(),
                    'response_code' => null,
                    'error_fingerprint' => null,
                    'updated_at' => now(),
                ]);
            $event->forceFill([
                'status' => OutboxEvent::STATUS_PENDING,
                'available_at' => now(),
                'dispatched_at' => null,
                'locked_at' => null,
                'delivered_at' => null,
                'last_error_fingerprint' => null,
            ])->save();
            DB::afterCommit(static function () use ($eventId): void {
                try {
                    DeliverOutboxEvent::dispatch($eventId)
                        ->onQueue((string) config('webhooks.queue', 'webhooks'));
                    OutboxEvent::query()->whereKey($eventId)->update([
                        'dispatched_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    Log::warning('Manual outbox replay remains pending after dispatch failure.', [
                        'outbox_event_id' => $eventId,
                        'exception' => $exception::class,
                    ]);
                }
            });
        }, 3);

        Log::warning('Administrator replayed a failed outbox event.', [
            'outbox_event_id' => $eventId,
            'actor_id' => $actorId,
            'reason' => trim($reason),
            'replayed' => true,
        ]);
    }

    public function skipOutbox(int $eventId, int|string|null $actorId, string $reason): void
    {
        $event = DB::transaction(function () use ($eventId): OutboxEvent {
            $event = $this->failedEventForUpdate($eventId);
            $event->forceFill([
                'status' => OutboxEvent::STATUS_SKIPPED,
                'available_at' => null,
                'dispatched_at' => null,
                'locked_at' => null,
                'delivered_at' => null,
            ])->save();

            return $event;
        }, 3);

        Log::critical('Administrator skipped a poison outbox event.', [
            'outbox_event_id' => $eventId,
            'topic' => $event->topic,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'actor_id' => $actorId,
            'reason' => trim($reason),
        ]);
    }

    public function acknowledgeFailedJob(int $failedJobId, int|string|null $actorId, string $reason): void
    {
        abort_unless(Schema::hasTable('failed_jobs'), 404);
        $job = DB::table('failed_jobs')->where('id', $failedJobId)
            ->first(['id', 'queue', 'failed_at']);
        abort_unless($job, 404);

        $deleted = DB::table('failed_jobs')->where('id', $failedJobId)->delete();
        abort_unless($deleted === 1, 409);
        Log::warning('Administrator acknowledged a dead-letter job without replay.', [
            'failed_job_id' => (int) $job->id,
            'queue' => (string) $job->queue,
            'failed_at' => $job->failed_at,
            'actor_id' => $actorId,
            'reason' => trim($reason),
        ]);
    }

    private function failedEventForUpdate(int $eventId): OutboxEvent
    {
        $event = OutboxEvent::query()->lockForUpdate()->findOrFail($eventId);
        abort_unless($event->status === OutboxEvent::STATUS_FAILED, 409);

        return $event;
    }
}
