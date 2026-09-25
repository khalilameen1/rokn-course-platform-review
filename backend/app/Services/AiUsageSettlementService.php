<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\AiUsageCost;
use App\Support\DatabaseCapabilities;
use Illuminate\Support\Facades\DB;

final readonly class AiUsageSettlementService
{
    public const SETTLEMENT_ACCEPTED = 'accepted';
    public const SETTLEMENT_ALREADY_ACCEPTED = 'already_accepted';
    public const SETTLEMENT_TERMINAL_CONFLICT = 'terminal_conflict';
    public const SETTLEMENT_INACTIVE = 'inactive';

    public function __construct(
        private AiProviderExposureService $providerExposure,
        private InternalSignalService $internalSignals
    ) {
    }

    public static function settlementAllowsDelivery(string $outcome): bool
    {
        return in_array($outcome, [
            self::SETTLEMENT_ACCEPTED,
            self::SETTLEMENT_ALREADY_ACCEPTED,
        ], true);
    }

    public function settle(?AiUsageEvent $event, array $providerResult): bool
    {
        if (!$event) {
            return false;
        }
        $didSettle = false;
        $openedExposureCircuit = false;
        $exposureCount = 0;
        $exposureEnrollmentId = 0;
        DB::transaction(function () use (
            $event,
            $providerResult,
            &$didSettle,
            &$openedExposureCircuit,
            &$exposureCount,
            &$exposureEnrollmentId
        ): void {
            $lockedEvent = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$lockedEvent) {
                return;
            }
            if ($lockedEvent->status !== 'reserved') {
                return;
            }
            $eventMetadata = is_array($lockedEvent->metadata)
                ? $lockedEvent->metadata : [];
            $detachedReservation = (bool) ($eventMetadata['reservation_detached'] ?? false);
            $usage = $detachedReservation
                ? null
                : AiEntitlementUsage::query()
                    ->lockForUpdate()
                    ->where('enrollment_id', $lockedEvent->enrollment_id)
                    ->where('feature', $lockedEvent->feature)
                    ->first();
            if (!$usage && !$detachedReservation) {
                $lockedEvent->forceFill([
                    'status' => 'failed',
                    'metadata' => ['reason' => 'missing_entitlement_aggregate'],
                    'completed_at' => now(),
                ])->save();
                return;
            }
            $providerTotal = max(0, (int) data_get($providerResult, 'usage.total_tokens', 0));
            $providerCostMicros = max(
                0,
                AiUsageCost::micros(data_get($providerResult, 'usage.cost', 0))
            );
            $usageFacts = data_get($providerResult, 'usage', []);
            $providerCostWasReported = data_get($providerResult, 'usage.cost_reported');
            if (!is_bool($providerCostWasReported)) {
                $providerCostWasReported = is_array($usageFacts)
                    && array_key_exists('cost', $usageFacts)
                    && is_numeric($usageFacts['cost']);
            }
            // Missing provider usage settles against the reservation.
            $total = $providerTotal > 0 ? $providerTotal : (int) $lockedEvent->reserved_tokens;
            $costMicros = $providerCostWasReported
                ? $providerCostMicros
                : AiUsageCost::micros($lockedEvent->reserved_cost_usd);
            $entitlementDelivered = data_get(
                $providerResult,
                'entitlement_delivered',
                true
            ) !== false;
            $acceptedResponse = trim((string) data_get($providerResult, 'message', ''));

            $usageUpdate = $usage ? [
                'reserved_requests' => max(0, $usage->reserved_requests - 1),
                'reserved_tokens' => max(0, $usage->reserved_tokens - $lockedEvent->reserved_tokens),
                'reserved_cost_usd' => AiUsageCost::format(max(
                    0,
                    AiUsageCost::micros($usage->reserved_cost_usd)
                        - AiUsageCost::micros($lockedEvent->reserved_cost_usd)
                )),
            ] : [];
            if ($usage && $entitlementDelivered) {
                $usageUpdate += [
                    'used_requests' => $usage->used_requests + 1,
                    'used_tokens' => $usage->used_tokens + $total,
                    'used_cost_usd' => AiUsageCost::format(
                        AiUsageCost::micros($usage->used_cost_usd) + $costMicros
                    ),
                ];
                if (
                    $acceptedResponse !== ''
                    && DatabaseCapabilities::hasColumn(
                        'ai_entitlement_usages',
                        'unanswered_provider_requests'
                    )
                ) {
                    $usageUpdate += [
                        'unanswered_provider_requests' => 0,
                        'unanswered_provider_last_at' => null,
                        'provider_exposure_paused_until' => null,
                    ];
                }
            } elseif ($usage && DatabaseCapabilities::hasColumn(
                'ai_entitlement_usages',
                'unanswered_provider_requests'
            )) {
                $exposure = $this->providerExposure->nextState($usage, 1);
                $usageUpdate += $exposure['attributes'];
                $openedExposureCircuit = $exposure['opened'];
                $exposureCount = $exposure['count'];
                $exposureEnrollmentId = (int) $usage->enrollment_id;
            }
            if ($usage) $usage->forceFill($usageUpdate)->save();
            $metadata = $eventMetadata;
            // The pre-settlement landing is only a crash-recovery envelope.
            // Keep the bounded replay fields below until presentation, never
            // two parallel copies of the learner answer.
            unset($metadata['provider_success_landing']);
            $metadata['provider_call_state'] = 'settled';
            $metadata['entitlement_delivered'] = $entitlementDelivered;
            $metadata['token_usage_source'] = $providerTotal > 0
                ? 'provider'
                : 'reservation_fallback';
            $metadata['cost_usage_source'] = $providerCostWasReported
                ? 'provider'
                : 'reservation_fallback';
            $metadata['usage_source'] = $providerTotal > 0 && $providerCostWasReported
                ? 'provider'
                : 'reservation_fallback';
            if ($acceptedResponse !== '') {
                // The accepted text enables a safe idempotent replay. The
                // provider envelope and failed output are never persisted.
                $metadata['accepted_response'] = mb_substr($acceptedResponse, 0, 12000);
            }
            $fileAnnotations = $this->boundedFileAnnotations(
                data_get($providerResult, 'file_annotations', [])
            );
            if ($fileAnnotations !== []) {
                $metadata['provider_file_annotations'] = $fileAnnotations;
            }
            $transport = data_get($providerResult, 'provider_transport');
            if (is_array($transport)) {
                $generationId = substr(
                    trim((string) ($transport['generation_id'] ?? '')),
                    0,
                    255
                );
                $cacheStatus = strtoupper(trim((string) (
                    $transport['response_cache_status'] ?? ''
                )));
                if ($generationId !== '') {
                    $metadata['provider_generation_id'] = $generationId;
                }
                if (in_array($cacheStatus, ['HIT', 'MISS'], true)) {
                    $metadata['provider_response_cache_status'] = $cacheStatus;
                }
            }
            $requestContext = data_get($providerResult, 'request_context');
            if (is_array($requestContext)) {
                $metadata['request_context'] = array_filter([
                    'question_hash' => isset($requestContext['question_hash'])
                        ? substr((string) $requestContext['question_hash'], 0, 64)
                        : null,
                    'lesson_id' => isset($requestContext['lesson_id'])
                        ? max(0, (int) $requestContext['lesson_id'])
                        : null,
                    'language' => isset($requestContext['language'])
                        ? substr((string) $requestContext['language'], 0, 12)
                        : null,
                    'prompt_version' => isset($requestContext['prompt_version'])
                        ? substr((string) $requestContext['prompt_version'], 0, 64)
                        : null,
                    'project_id' => isset($requestContext['project_id'])
                        ? max(0, (int) $requestContext['project_id']) : null,
                    'submission_id' => isset($requestContext['submission_id'])
                        ? substr((string) $requestContext['submission_id'], 0, 64) : null,
                    'thread_id' => isset($requestContext['thread_id'])
                        ? substr((string) $requestContext['thread_id'], 0, 64) : null,
                    'feedback_level' => isset($requestContext['feedback_level'])
                        ? substr((string) $requestContext['feedback_level'], 0, 24) : null,
                ], static fn ($value): bool => $value !== null && $value !== '');
            }
            $egpFacts = $this->egpCostSnapshot($costMicros);
            $lockedEvent->forceFill([
                'status' => 'completed',
                'prompt_tokens' => max(0, (int) data_get($providerResult, 'usage.prompt_tokens', 0)),
                'completion_tokens' => max(0, (int) data_get($providerResult, 'usage.completion_tokens', 0)),
                'total_tokens' => $total,
                'cost_usd' => AiUsageCost::format($costMicros),
                'provider_request_id' => data_get($providerResult, 'provider_request_id'),
                'metadata' => $metadata,
                'completed_at' => now(),
            ] + $egpFacts)->save();
            $this->recordSettledUsageSignal($lockedEvent);
            if ($openedExposureCircuit) {
                $this->providerExposure->recordAlert(
                    $exposureEnrollmentId,
                    $exposureCount,
                    $lockedEvent->id
                );
            }
            $didSettle = true;
        }, 3);

        return $didSettle;
    }

    /** Hold the active-account lock through settlement and discard private results after deletion. */
    public function settleForActiveUser(
        ?AiUsageEvent $event,
        array $providerResult,
        int $userId
    ): string {
        $outcome = self::SETTLEMENT_TERMINAL_CONFLICT;
        DB::transaction(function () use (
            $event, $providerResult, $userId, &$outcome
        ): void {
            $active = User::query()
                ->whereKey($userId)
                ->where('active', true)
                ->lockForUpdate()
                ->exists();
            $lockedEvent = $event
                ? AiUsageEvent::query()->lockForUpdate()->find($event->id)
                : null;
            if (!$lockedEvent) {
                $outcome = self::SETTLEMENT_TERMINAL_CONFLICT;
                return;
            }
            // The active-account lock and the metered event must describe the
            // same learner. Otherwise a stale/misrouted job could use another
            // active account to start or retain this learner's paid result.
            if ((int) $lockedEvent->user_id !== $userId) {
                $outcome = self::SETTLEMENT_TERMINAL_CONFLICT;
                return;
            }
            if ($lockedEvent->status === 'completed') {
                $stored = trim((string) data_get($lockedEvent->metadata, 'accepted_response', ''));
                $received = trim((string) data_get($providerResult, 'message', ''));
                $sameProviderRequest = !$lockedEvent->provider_request_id
                    || !data_get($providerResult, 'provider_request_id')
                    || hash_equals(
                        (string) $lockedEvent->provider_request_id,
                        (string) data_get($providerResult, 'provider_request_id')
                    );
                $outcome = $active && $stored !== '' && $received !== ''
                    && hash_equals($stored, mb_substr($received, 0, 12000))
                    && $sameProviderRequest
                    ? self::SETTLEMENT_ALREADY_ACCEPTED
                    : self::SETTLEMENT_TERMINAL_CONFLICT;
                return;
            }
            if ($lockedEvent->status !== 'reserved') {
                $outcome = self::SETTLEMENT_TERMINAL_CONFLICT;
                return;
            }
            $transitioned = data_get(
                $lockedEvent->metadata,
                'entitlement_transitioned_at'
            ) !== null;
            if (!$active || $transitioned) {
                $providerResult = [
                    'usage' => is_array($providerResult['usage'] ?? null)
                        ? $providerResult['usage'] : [],
                    'provider_request_id' => $providerResult['provider_request_id'] ?? null,
                    'message' => '',
                    'entitlement_delivered' => false,
                ];
            }
            $settled = $this->settle($lockedEvent, $providerResult);
            $outcome = !$active
                ? self::SETTLEMENT_INACTIVE
                : ($transitioned
                    ? self::SETTLEMENT_TERMINAL_CONFLICT
                    : ($settled
                        ? self::SETTLEMENT_ACCEPTED
                        : self::SETTLEMENT_TERMINAL_CONFLICT));
        }, 3);

        return $outcome;
    }

    public function settleUnknown(
        AiUsageEvent $event,
        array $requestContext,
        string $reason = 'provider_outcome_unknown'
    ): void {
        DB::transaction(function () use ($event, $reason): void {
            $locked = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$locked || $locked->status !== 'reserved') return;
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['provider_call_state'] = 'outcome_unknown';
            $metadata['provider_outcome_reason'] = $reason;
            $metadata['provider_outcome_recorded_at'] = now()->toIso8601String();
            $locked->forceFill(['metadata' => $metadata])->save();
        }, 3);
        $this->settle($event, [
            'entitlement_delivered' => false,
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cost' => 0,
                'cost_reported' => false,
            ],
            'request_context' => $requestContext,
        ]);
    }

    /**
     * Close an unknown provider result after the caller has released its aggregate.
     * Caller owns the surrounding transaction and the usage-event row lock.
     * This records platform cost without debiting learner entitlement again.
     */
    public function finalizeLockedUnknownOutcome(
        AiUsageEvent $event,
        string $reason
    ): void {
        if ($event->status !== 'reserved') return;
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $metadata['provider_call_state'] = 'outcome_unknown';
        $metadata['provider_outcome_reason'] = substr($reason, 0, 120);
        $metadata['provider_outcome_recorded_at'] = now()->toIso8601String();
        $metadata['token_usage_source'] = 'reservation_fallback';
        $metadata['cost_usage_source'] = 'reservation_fallback';
        $metadata['usage_source'] = 'reservation_fallback';
        $metadata['entitlement_delivered'] = false;
        $costMicros = AiUsageCost::micros($event->reserved_cost_usd);
        $egpFacts = $this->egpCostSnapshot($costMicros);
        $event->forceFill([
            'status' => 'completed',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => (int) $event->reserved_tokens,
            'cost_usd' => AiUsageCost::format($costMicros),
            'metadata' => $metadata,
            'completed_at' => now(),
        ] + $egpFacts)->save();
        $this->recordSettledUsageSignal($event);
    }

    private function boundedFileAnnotations(mixed $value): array
    {
        if (!is_array($value)) return [];
        $kept = [];
        $bytes = 0;
        foreach (array_slice($value, 0, 12) as $annotation) {
            if (!is_array($annotation)) continue;
            $encoded = json_encode($annotation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || strlen($encoded) > 16384 || $bytes + strlen($encoded) > 65536) {
                continue;
            }
            $kept[] = $annotation;
            $bytes += strlen($encoded);
        }
        return $kept;
    }

    private function egpCostSnapshot(int $costMicros): array
    {
        if (!DatabaseCapabilities::hasColumn('ai_usage_events', 'cost_egp')) return [];
        $fxRate = max(0, (float) (Setting::query()->value('openrouter_usd_to_egp_rate') ?? 0));
        if ($fxRate <= 0) return [];
        return [
            'fx_rate_to_egp' => number_format($fxRate, 4, '.', ''),
            'cost_egp' => number_format(($costMicros / 1_000_000) * $fxRate, 6, '.', ''),
        ];
    }

    private function recordSettledUsageSignal(AiUsageEvent $event): void
    {
        $this->internalSignals->record(
            'ai_usage.settled',
            'event:' . $event->id,
            ['event_id' => (int) $event->id],
            AiUsageEvent::class,
            (int) $event->id
        );
    }
}
