<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** One durable paid-provider attempt per logical usage event. */
final class PaidAiCallExecutionService
{
    public const START = 'start';
    public const LIVE = 'live';
    public const STALE_STARTED = 'stale_started';
    public const LANDED = 'landed';
    public const TERMINAL = 'terminal';
    public const INACTIVE = 'inactive';
    public const CONSENT_REQUIRED = 'ai_consent_required';

    public function beginForActiveUser(
        AiUsageEvent $event,
        string $executionId,
        int $userId
    ): string {
        return DB::transaction(function () use ($event, $executionId, $userId): string {
            if (!User::query()->whereKey($userId)->where('active', true)
                ->lockForUpdate()->exists()) {
                return self::INACTIVE;
            }
            $locked = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$locked || (int) $locked->user_id !== $userId) {
                return self::TERMINAL;
            }

            return $this->beginLocked($locked, $executionId);
        }, 3);
    }

    public function begin(AiUsageEvent $event, string $executionId): string
    {
        return DB::transaction(function () use ($event, $executionId): string {
            $locked = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$locked || $locked->status !== 'reserved') return self::TERMINAL;

            return $this->beginLocked($locked, $executionId);
        }, 3);
    }

    /**
     * Persist the known provider result before entitlement settlement or any
     * learner-facing write. This is the recovery boundary for a successful
     * external call followed by a database failure.
     */
    public function landSuccessfulResultForActiveUser(
        AiUsageEvent $event,
        string $executionId,
        int $userId,
        array $providerResult
    ): string {
        return retry(5, fn (): string => DB::transaction(function () use (
            $event, $executionId, $userId, $providerResult
        ): string {
            if (!User::query()->whereKey($userId)->where('active', true)
                ->lockForUpdate()->exists()) return self::INACTIVE;
            $locked = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$locked || (int) $locked->user_id !== $userId
                || $locked->status !== 'reserved') return self::TERMINAL;
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $existing = $this->landedResult($locked);
            if ($existing !== null) {
                return hash_equals(
                    (string) ($existing['landing_fingerprint'] ?? ''),
                    $this->landingFingerprint($providerResult)
                ) ? self::LANDED : self::TERMINAL;
            }
            if (!hash_equals(
                (string) ($metadata['worker_execution_id'] ?? ''),
                $executionId
            ) || ($metadata['provider_call_state'] ?? null) !== 'started') {
                return self::TERMINAL;
            }
            $landing = $this->sanitizeLanding($providerResult);
            if (trim((string) ($landing['message'] ?? '')) === '') {
                throw new \UnexpectedValueException('Provider success landing has no answer.');
            }
            $metadata['provider_call_state'] = self::LANDED;
            $metadata['provider_success_landed_at'] = now()->toIso8601String();
            $metadata['provider_success_landing'] = $landing;
            $locked->forceFill(['metadata' => $metadata])->save();
            return self::LANDED;
        }, 3), 200);
    }

    private function beginLocked(AiUsageEvent $locked, string $executionId): string
    {
        if ($locked->status !== 'reserved') return self::TERMINAL;
        $metadata = is_array($locked->metadata) ? $locked->metadata : [];
        $state = trim((string) ($metadata['provider_call_state'] ?? ''));
        if ($state === self::LANDED && is_array($metadata['provider_success_landing'] ?? null)) {
            return self::LANDED;
        }
        if (in_array($state, ['started', 'outcome_unknown'], true)) {
            $startedAt = strtotime((string) ($metadata['provider_call_started_at'] ?? ''));
            $leaseSeconds = max(60, (int) config('openrouter.timeout_seconds', 45) + 30);
            return $state === 'started' && $startedAt !== false && $startedAt > time() - $leaseSeconds
                ? self::LIVE
                : self::STALE_STARTED;
        }
        if (!in_array($state, ['', 'retry_safe'], true)) {
            return self::TERMINAL;
        }
        if (!app(AiConsentService::class)->accepted((int) $locked->user_id)) {
            return self::CONSENT_REQUIRED;
        }
        $metadata['worker_execution_id'] = $executionId;
        $metadata['provider_call_state'] = 'started';
        $metadata['provider_call_started_at'] = now()->toIso8601String();
        $metadata['provider_call_attempt'] = max(0, (int) ($metadata['provider_call_attempt'] ?? 0)) + 1;
        unset($metadata['provider_retry_safe_at']);
        $locked->forceFill([
            'metadata' => $metadata,
            // The reservation is a worker lease, not a wall clock measured
            // from the first queue attempt. A retry can legitimately begin
            // after the original lease, and the minute sweeper must not turn
            // that live paid request into an unknown terminal outcome.
            'reservation_expires_at' => now()->addSeconds($this->reservationLeaseSeconds()),
        ])->save();
        return self::START;
    }

    public function landedResult(?AiUsageEvent $event): ?array
    {
        if (!$event) return null;
        $landing = data_get($event->metadata, 'provider_success_landing');
        if (!is_array($landing) || trim((string) ($landing['message'] ?? '')) === '') {
            return null;
        }
        return $landing;
    }

    public function markPresented(?AiUsageEvent $event): void
    {
        if (!$event) return;
        DB::transaction(function () use ($event): void {
            $locked = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$locked || $locked->status !== 'completed') return;
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['presentation_completed_at'] = now()->toIso8601String();
            unset(
                $metadata['provider_success_landing'],
                $metadata['accepted_response'],
                $metadata['provider_file_annotations']
            );
            $locked->forceFill(['metadata' => $metadata])->save();
        }, 3);
    }

    public function recoverLandedSettlements(
        AiUsageSettlementService $settlements,
        int $limit = 500
    ): int {
        $events = AiUsageEvent::query()
            ->where('status', 'reserved')
            ->where('metadata->provider_call_state', self::LANDED)
            ->orderBy('id')
            ->limit(max(1, min(5000, $limit)))
            ->get();
        $recovered = 0;
        foreach ($events as $event) {
            $result = $this->landedResult($event);
            if ($result === null) continue;
            $outcome = $settlements->settleForActiveUser(
                $event,
                $result,
                (int) $event->user_id
            );
            if (AiUsageSettlementService::settlementAllowsDelivery($outcome)
                || $outcome === AiUsageSettlementService::SETTLEMENT_INACTIVE) {
                $recovered++;
            }
        }
        return $recovered;
    }

    private function sanitizeLanding(array $result): array
    {
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $annotations = is_array($result['file_annotations'] ?? null)
            ? array_slice($result['file_annotations'], 0, 20) : [];
        $annotations = array_values(array_filter($annotations, static function ($annotation): bool {
            return is_array($annotation)
                && strlen((string) json_encode($annotation)) <= 4096;
        }));
        $context = is_array($result['request_context'] ?? null)
            ? $result['request_context'] : [];
        $context = array_intersect_key($context, array_flip([
            'course_id', 'lesson_id', 'project_id', 'submission_id',
            'thread_id', 'prompt_version', 'feedback_level', 'language',
        ]));
        $transport = is_array($result['provider_transport'] ?? null)
            ? $result['provider_transport'] : [];
        return [
            'landing_fingerprint' => $this->landingFingerprint($result),
            'message' => mb_substr(trim((string) ($result['message'] ?? '')), 0, 12000),
            'provider_request_id' => substr((string) ($result['provider_request_id'] ?? ''), 0, 255),
            'usage' => [
                'prompt_tokens' => max(0, (int) ($usage['prompt_tokens'] ?? 0)),
                'completion_tokens' => max(0, (int) ($usage['completion_tokens'] ?? 0)),
                'total_tokens' => max(0, (int) ($usage['total_tokens'] ?? 0)),
                'cost' => is_numeric($usage['cost'] ?? null)
                    ? max(0, (float) $usage['cost']) : 0,
                'cost_reported' => (bool) ($usage['cost_reported'] ?? false),
            ],
            'file_annotations' => $annotations,
            'request_context' => $context,
            'provider_transport' => [
                'generation_id' => substr(
                    (string) ($transport['generation_id'] ?? ''),
                    0,
                    255
                ),
                'response_cache_status' => in_array(
                    strtoupper((string) ($transport['response_cache_status'] ?? '')),
                    ['HIT', 'MISS'],
                    true
                ) ? strtoupper((string) $transport['response_cache_status']) : null,
            ],
        ];
    }

    private function landingFingerprint(array $result): string
    {
        return hash('sha256', json_encode([
            'provider_request_id' => (string) ($result['provider_request_id'] ?? ''),
            'message' => mb_substr(trim((string) ($result['message'] ?? '')), 0, 12000),
            'usage' => is_array($result['usage'] ?? null) ? $result['usage'] : [],
            'file_annotations' => is_array($result['file_annotations'] ?? null)
                ? $result['file_annotations'] : [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function markRetrySafe(AiUsageEvent $event, string $executionId): void
    {
        DB::transaction(function () use ($event, $executionId): void {
            $locked = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$locked || $locked->status !== 'reserved') return;
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            if (!hash_equals((string) ($metadata['worker_execution_id'] ?? ''), $executionId)) return;
            $metadata['provider_call_state'] = 'retry_safe';
            $metadata['provider_retry_safe_at'] = now()->toIso8601String();
            $locked->forceFill([
                'metadata' => $metadata,
                // Cover the queue backoff and the next owned attempt. The
                // same usage event and request id remain reserved, so this
                // extends recovery safety without granting another message.
                'reservation_expires_at' => now()->addSeconds($this->reservationLeaseSeconds()),
            ])->save();
        }, 3);
    }

    private function reservationLeaseSeconds(): int
    {
        return max(
            60,
            (int) config('course_plans.ai_reservation_ttl_seconds', 120),
            (int) config('openrouter.timeout_seconds', 45) + 60
        );
    }

    public function providerWasStarted(?AiUsageEvent $event): bool
    {
        return $event?->status === 'reserved' && in_array(
            data_get($event->metadata, 'provider_call_state'),
            ['started', 'outcome_unknown'],
            true
        );
    }

    public function startedState(?AiUsageEvent $event): string
    {
        if (!$this->providerWasStarted($event)) return self::TERMINAL;
        $startedAt = strtotime((string) data_get($event->metadata, 'provider_call_started_at', ''));
        $leaseSeconds = max(60, (int) config('openrouter.timeout_seconds', 45) + 30);
        return data_get($event->metadata, 'provider_call_state') === 'started'
            && $startedAt !== false
            && $startedAt > time() - $leaseSeconds
            ? self::LIVE
            : self::STALE_STARTED;
    }
}
