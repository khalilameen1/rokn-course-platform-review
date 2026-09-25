<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessInternalSignal;
use App\Models\InternalSignal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transactional hand-off for important work that stays inside Rokn.
 *
 * The caller records the signal in the same transaction as its authoritative
 * state change. Queue delivery is only an accelerator; the scheduler owns
 * recovery, so a queue outage can never erase the intent.
 */
final class InternalSignalService
{
    public function record(
        string $type,
        string $identity,
        array $payload,
        ?string $aggregateType = null,
        string|int|null $aggregateId = null
    ): InternalSignal {
        $type = trim($type);
        $identity = trim($identity);
        if ($type === '' || $identity === '') {
            throw new \InvalidArgumentException('Internal signals require a type and stable identity.');
        }

        $signalKey = hash('sha256', $type . '|' . $identity);

        $normalizedPayload = $this->normalize($payload);
        $encoded = json_encode(
            $normalizedPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $fingerprint = hash('sha256', $encoded);
        $attributes = [
            'signal_key' => $signalKey,
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId === null ? null : (string) $aggregateId,
            'payload_fingerprint' => $fingerprint,
            'payload' => $normalizedPayload,
            'status' => InternalSignal::STATUS_PENDING,
            'available_at' => now(),
        ];

        try {
            $signal = InternalSignal::query()->firstOrCreate(
                ['signal_key' => $signalKey],
                $attributes
            );
        } catch (QueryException $exception) {
            $signal = InternalSignal::query()->where('signal_key', $signalKey)->first();
            if (!$signal) {
                throw $exception;
            }
        }

        if (
            !hash_equals((string) $signal->payload_fingerprint, $fingerprint)
            || !hash_equals((string) $signal->type, $type)
        ) {
            throw new \UnexpectedValueException('Internal signal identity was reused for a different payload.');
        }

        if ($signal->status !== InternalSignal::STATUS_HANDLED) {
            $dispatch = static function () use ($signal): void {
                try {
                    ProcessInternalSignal::dispatch((int) $signal->id, (string) $signal->type)
                        ->onQueue(ProcessInternalSignal::queueForType((string) $signal->type));
                    InternalSignal::query()
                        ->whereKey($signal->id)
                        ->where('status', InternalSignal::STATUS_PENDING)
                        ->update(['dispatched_at' => now(), 'updated_at' => now()]);
                } catch (\Throwable $exception) {
                    // The row is the hand-off. Queue dispatch is only a fast
                    // path and must not turn the committed user action into a
                    // false failure; the scheduler will pick it up.
                    Log::warning('Immediate internal signal dispatch failed.', [
                        'signal_id' => $signal->id,
                        'signal_type' => $signal->type,
                        'exception' => $exception::class,
                    ]);
                }
            };
            DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
        }

        return $signal;
    }

    private function normalize(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalize($value);
            }
        }

        return $payload;
    }

}
