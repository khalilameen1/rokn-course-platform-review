<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InternalSignal;
use App\Services\InternalSignalHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProcessInternalSignal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 150;
    public bool $failOnTimeout = true;

    public function __construct(public int $signalId, public ?string $signalType = null)
    {
        $this->onQueue(self::queueForType($signalType));
    }

    public static function queueForType(?string $type): string
    {
        return match (trim((string) $type)) {
            'financial_anomaly.opened',
            'financial_anomaly.alert_admin',
            'ai_usage.settled',
            'ai_usage.threshold',
            'ai_usage.threshold_admin' => (string) config('queue.channels.operations', 'operations'),
            'course.attachments.grant' => (string) config('queue.channels.operations', 'operations'),
            default => (string) config('queue.connections.redis.queue', 'default'),
        };
    }

    public function handle(InternalSignalHandler $handler): void
    {
        $leaseId = (string) Str::uuid();
        $signal = $this->claim($leaseId);
        if (!$signal) {
            return;
        }

        try {
            $handler->handle($signal);
            InternalSignal::query()
                ->whereKey($signal->id)
                ->where('status', InternalSignal::STATUS_PROCESSING)
                ->where('lease_id', $leaseId)
                ->update([
                    'status' => InternalSignal::STATUS_HANDLED,
                    'handled_at' => now(),
                    'available_at' => null,
                    'locked_at' => null,
                    'lease_id' => null,
                    'last_error_fingerprint' => null,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $exception) {
            $delay = min(3600, 15 * (2 ** min(8, max(0, (int) $signal->attempts - 1))));
            InternalSignal::query()
                ->whereKey($signal->id)
                ->where('status', InternalSignal::STATUS_PROCESSING)
                ->where('lease_id', $leaseId)
                ->update([
                    'status' => InternalSignal::STATUS_PENDING,
                    'available_at' => now()->addSeconds($delay),
                    'dispatched_at' => null,
                    'locked_at' => null,
                    'lease_id' => null,
                    'last_error_fingerprint' => hash(
                        'sha256',
                        $exception::class . '|' . $exception->getMessage()
                    ),
                    'updated_at' => now(),
                ]);
            Log::warning('Durable internal signal will be retried.', [
                'signal_id' => $signal->id,
                'signal_type' => $signal->type,
                'attempt' => $signal->attempts,
                'exception' => $exception::class,
            ]);
        }
    }

    private function claim(string $leaseId): ?InternalSignal
    {
        return DB::transaction(function () use ($leaseId): ?InternalSignal {
            $signal = InternalSignal::query()->lockForUpdate()->find($this->signalId);
            if (!$signal || $signal->status === InternalSignal::STATUS_HANDLED) {
                return null;
            }
            if ($signal->available_at?->isFuture()) {
                return null;
            }
            $staleBefore = now()->subSeconds(max(180, $this->timeout + 30));
            if (
                $signal->status === InternalSignal::STATUS_PROCESSING
                && $signal->locked_at
                && $signal->locked_at->gt($staleBefore)
            ) {
                return null;
            }

            $signal->forceFill([
                'status' => InternalSignal::STATUS_PROCESSING,
                'attempts' => (int) $signal->attempts + 1,
                'locked_at' => now(),
                'lease_id' => $leaseId,
                'available_at' => null,
            ])->save();

            return $signal->fresh();
        }, 3);
    }
}
