<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiPlanLimitReachedException;
use App\Exceptions\AiProviderExposureLimitReachedException;
use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\CourseEnrollment;
use App\Models\Setting;
use App\Models\User;
use App\Support\DatabaseCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AiEntitlementBudgetService
{
    public const SETTLEMENT_ACCEPTED = 'accepted';
    public const SETTLEMENT_ALREADY_ACCEPTED = 'already_accepted';
    public const SETTLEMENT_TERMINAL_CONFLICT = 'terminal_conflict';
    public const SETTLEMENT_INACTIVE = 'inactive';

    public static function settlementAllowsDelivery(string $outcome): bool
    {
        return in_array($outcome, [
            self::SETTLEMENT_ACCEPTED,
            self::SETTLEMENT_ALREADY_ACCEPTED,
        ], true);
    }

    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private FinancialAnomalyService $financialRisk,
        private InternalSignalService $internalSignals
    ) {
    }

    public function reserve(
        CourseEnrollment $enrollment,
        string $feature,
        int $estimatedTokens,
        string $model,
        ?string $requestId = null
    ): ?AiUsageEvent {
        if (!in_array($feature, AiEntitlementUsage::FEATURES, true)) {
            throw new \InvalidArgumentException('Unknown metered AI feature.');
        }

        return DB::transaction(function () use (
            $enrollment,
            $feature,
            $estimatedTokens,
            $model,
            $requestId
        ): ?AiUsageEvent {
            $lockedEnrollment = CourseEnrollment::query()
                ->lockForUpdate()
                ->findOrFail($enrollment->id);
            if (!$lockedEnrollment->isActive()) {
                throw new AiPlanLimitReachedException('The course entitlement is not active.');
            }
            if (!$this->financialRisk->allowsVariableCostFeatures($lockedEnrollment)) {
                throw new AiPlanLimitReachedException('This enrollment is under financial review.');
            }

            if ($requestId) {
                $existing = AiUsageEvent::query()
                    ->where('request_id', $requestId)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (
                        (int) $existing->enrollment_id !== (int) $lockedEnrollment->id
                        || (string) $existing->feature !== $feature
                    ) {
                        throw new \UnexpectedValueException('AI request identity conflict.');
                    }

                    return $existing;
                }
            }

            $terms = $this->accessPlans->termsForEnrollment($lockedEnrollment);
            if (!$terms) {
                return null;
            }
            $planId = $lockedEnrollment->access_plan_id
                ? (int) $lockedEnrollment->access_plan_id
                : null;

            // The unique key selects one aggregate; lock it before reserving.
            $now = now();
            DB::table('ai_entitlement_usages')->insertOrIgnore([
                'enrollment_id' => $lockedEnrollment->id,
                'access_plan_id' => $planId,
                'feature' => $feature,
                'used_requests' => 0,
                'reserved_requests' => 0,
                'used_tokens' => 0,
                'reserved_tokens' => 0,
                'used_cost_usd' => '0.000000',
                'reserved_cost_usd' => '0.000000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $usage = AiEntitlementUsage::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('feature', $feature)
                ->lockForUpdate()
                ->firstOrFail();
            $this->reclaimExpiredReservations($usage);
            $usage->refresh();
            $this->refreshProviderExposureCircuit($usage);
            $usage->refresh();

            $estimatedTokens = max(1, $estimatedTokens);
            $isChat = $feature === AiEntitlementUsage::FEATURE_COURSE_CHAT;
            $isFollowup = $feature === AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP;
            $tokenBudget = (int) ($isChat
                ? ($terms['chat_token_budget'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_token_budget'] ?? 0)
                    : ($terms['project_feedback_token_budget'] ?? 0)));
            $costBudgetMicros = $this->toUsdMicros($isChat
                ? ($terms['ai_budget_usd'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_budget_usd'] ?? 0)
                    : ($terms['project_feedback_budget_usd'] ?? 0)));
            $reserveCostMicros = max(1, $this->toUsdMicros($isChat
                ? ($terms['request_reserve_usd'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_reserve_usd'] ?? 0)
                    : ($terms['project_feedback_reserve_usd'] ?? 0))));
            $featureAllowed = $isChat
                ? (bool) ($terms['chat_enabled'] ?? false)
                : ($isFollowup
                    ? ($terms['project_feedback_level'] ?? null) === 'enhanced'
                        && (int) ($terms['project_followup_message_limit'] ?? 0) > 0
                    : in_array($terms['project_feedback_level'] ?? null, ['report', 'enhanced'], true));
            $requestLimit = $isChat
                ? (int) ($terms['chat_message_limit'] ?? 0)
                : ($isFollowup ? (int) ($terms['project_followup_message_limit'] ?? 0) : null);

            if (
                !$featureAllowed
                || (
                    $requestLimit !== null
                    && $usage->used_requests + $usage->reserved_requests + 1
                        > $requestLimit
                )
                || $usage->used_tokens + $usage->reserved_tokens + $estimatedTokens > $tokenBudget
                || $this->toUsdMicros($usage->used_cost_usd)
                    + $this->toUsdMicros($usage->reserved_cost_usd)
                    + $reserveCostMicros > $costBudgetMicros
            ) {
                throw new AiPlanLimitReachedException('The selected plan AI budget is exhausted.');
            }

            $usage->forceFill([
                'access_plan_id' => $planId,
                'reserved_requests' => $usage->reserved_requests + 1,
                'reserved_tokens' => $usage->reserved_tokens + $estimatedTokens,
                'reserved_cost_usd' => $this->formatUsdMicros(
                    $this->toUsdMicros($usage->reserved_cost_usd) + $reserveCostMicros
                ),
            ])->save();

            return AiUsageEvent::create([
                'request_id' => $requestId ?: (string) Str::uuid(),
                'enrollment_id' => $lockedEnrollment->id,
                'access_plan_id' => $planId,
                'user_id' => $lockedEnrollment->user_id,
                'course_id' => $lockedEnrollment->course_id,
                'feature' => $feature,
                'model' => $model,
                'status' => 'reserved',
                'reserved_tokens' => $estimatedTokens,
                'reserved_cost_usd' => $this->formatUsdMicros($reserveCostMicros),
                'reservation_expires_at' => now()->addSeconds($this->reservationTtlSeconds()),
            ]);
        }, 3);
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
                $this->toUsdMicros(data_get($providerResult, 'usage.cost', 0))
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
                : $this->toUsdMicros($lockedEvent->reserved_cost_usd);
            $entitlementDelivered = data_get(
                $providerResult,
                'entitlement_delivered',
                true
            ) !== false;
            $acceptedResponse = trim((string) data_get($providerResult, 'message', ''));

            $usageUpdate = $usage ? [
                'reserved_requests' => max(0, $usage->reserved_requests - 1),
                'reserved_tokens' => max(0, $usage->reserved_tokens - $lockedEvent->reserved_tokens),
                'reserved_cost_usd' => $this->formatUsdMicros(max(
                    0,
                    $this->toUsdMicros($usage->reserved_cost_usd)
                        - $this->toUsdMicros($lockedEvent->reserved_cost_usd)
                )),
            ] : [];
            if ($usage && $entitlementDelivered) {
                $usageUpdate += [
                    'used_requests' => $usage->used_requests + 1,
                    'used_tokens' => $usage->used_tokens + $total,
                    'used_cost_usd' => $this->formatUsdMicros(
                        $this->toUsdMicros($usage->used_cost_usd) + $costMicros
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
                $exposure = $this->nextProviderExposureState($usage, 1);
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
            $egpFacts = [];
            if (DatabaseCapabilities::hasColumn('ai_usage_events', 'cost_egp')) {
                $fxRate = max(0, (float) (Setting::query()->value('openrouter_usd_to_egp_rate') ?? 0));
                if ($fxRate > 0) {
                    $egpFacts = [
                        'fx_rate_to_egp' => number_format($fxRate, 4, '.', ''),
                        'cost_egp' => number_format(($costMicros / 1_000_000) * $fxRate, 6, '.', ''),
                    ];
                }
            }
            $lockedEvent->forceFill([
                'status' => 'completed',
                'prompt_tokens' => max(0, (int) data_get($providerResult, 'usage.prompt_tokens', 0)),
                'completion_tokens' => max(0, (int) data_get($providerResult, 'usage.completion_tokens', 0)),
                'total_tokens' => $total,
                'cost_usd' => $this->formatUsdMicros($costMicros),
                'provider_request_id' => data_get($providerResult, 'provider_request_id'),
                'metadata' => $metadata,
                'completed_at' => now(),
            ] + $egpFacts)->save();
            $this->recordSettledUsageSignal($lockedEvent);
            if ($openedExposureCircuit) {
                $this->recordProviderExposureAlert(
                    $exposureEnrollmentId,
                    $exposureCount,
                    $lockedEvent->id
                );
            }
            $didSettle = true;
        }, 3);

        return $didSettle;
    }

    /**
     * Serialize the durable provider result with account deletion. A worker
     * may have started while the account existed and return after deletion;
     * in that case only provider usage is retained, never the answer, file
     * annotations, or request context that deletion has already erased.
     */
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

    public function release(?AiUsageEvent $event, ?string $reason = null): void
    {
        if (!$event) {
            return;
        }
        DB::transaction(function () use ($event, $reason): void {
            $lockedEvent = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$lockedEvent) {
                return;
            }
            if ($lockedEvent->status !== 'reserved') {
                return;
            }
            $detachedReservation = (bool) data_get(
                $lockedEvent->metadata,
                'reservation_detached',
                false
            );
            $usage = $detachedReservation
                ? null
                : AiEntitlementUsage::query()
                    ->lockForUpdate()
                    ->where('enrollment_id', $lockedEvent->enrollment_id)
                    ->where('feature', $lockedEvent->feature)
                    ->first();
            if ($usage) {
                $usage->forceFill([
                    'reserved_requests' => max(0, $usage->reserved_requests - 1),
                    'reserved_tokens' => max(0, $usage->reserved_tokens - $lockedEvent->reserved_tokens),
                    'reserved_cost_usd' => $this->formatUsdMicros(max(
                        0,
                        $this->toUsdMicros($usage->reserved_cost_usd)
                            - $this->toUsdMicros($lockedEvent->reserved_cost_usd)
                    )),
                ])->save();
            }
            $metadata = is_array($lockedEvent->metadata) ? $lockedEvent->metadata : [];
            if ($reason) {
                $metadata['reason'] = preg_match('/^[a-z0-9_:-]{1,64}$/i', $reason)
                    ? strtolower($reason)
                    : 'request_failed';
            }
            $lockedEvent->forceFill([
                'status' => 'failed',
                'metadata' => $metadata ?: null,
                'completed_at' => now(),
            ])->save();
        }, 3);
    }

    /** A repurchase resets aggregates but retains immutable usage events. */
    public function resetForNewPurchase(CourseEnrollment $enrollment): void
    {
        $this->cancelOutstandingReservations($enrollment, 'entitlement_replaced');

        DB::transaction(function () use ($enrollment): void {
            CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            AiEntitlementUsage::query()
                ->where('enrollment_id', $enrollment->id)
                ->delete();
        }, 3);
    }

    public function cancelOutstandingReservations(
        CourseEnrollment $enrollment,
        string $reason
    ): int {
        return DB::transaction(function () use ($enrollment, $reason): int {
            CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $usages = AiEntitlementUsage::query()
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('feature');
            $reserved = AiUsageEvent::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            $safeToCancel = $reserved->filter(function (AiUsageEvent $event): bool {
                $state = (string) data_get($event->metadata, 'provider_call_state', '');
                return !in_array($state, ['started', 'outcome_unknown'], true);
            });
            $providerStarted = $reserved->filter(function (AiUsageEvent $event): bool {
                $state = (string) data_get($event->metadata, 'provider_call_state', '');
                return in_array($state, ['started', 'outcome_unknown'], true);
            });

            // Every old reservation leaves the mutable entitlement aggregate.
            // Safe calls become cancelled; provider-started calls remain an
            // immutable detached event that can record platform cost later.
            foreach ($reserved->groupBy('feature') as $feature => $events) {
                $usage = $usages->get($feature);
                if (!$usage) {
                    continue;
                }
                $costMicros = $events->sum(fn (AiUsageEvent $event): int =>
                    $this->toUsdMicros($event->reserved_cost_usd)
                );
                $usage->forceFill([
                    'reserved_requests' => max(0, $usage->reserved_requests - $events->count()),
                    'reserved_tokens' => max(0, $usage->reserved_tokens - (int) $events->sum('reserved_tokens')),
                    'reserved_cost_usd' => $this->formatUsdMicros(max(
                        0,
                        $this->toUsdMicros($usage->reserved_cost_usd) - $costMicros
                    )),
                ])->save();
            }

            foreach ($safeToCancel as $event) {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $metadata['reason'] = substr($reason, 0, 180);
                $event->forceFill([
                    'status' => 'cancelled',
                    'metadata' => $metadata,
                    'completed_at' => now(),
                ])->save();
            }

            foreach ($providerStarted as $event) {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $metadata['entitlement_transition_reason'] = substr($reason, 0, 180);
                $metadata['entitlement_transitioned_at'] = now()->toIso8601String();
                $metadata['reservation_detached'] = true;
                $event->forceFill(['metadata' => $metadata])->save();
            }

            return $reserved->count();
        }, 3);
    }

    public function releaseExpiredReservations(int $limit = 500): int
    {
        $leaseStartedBefore = now()->subSeconds($this->reservationTtlSeconds());
        $pairs = DB::table('ai_usage_events')
            ->select(['enrollment_id', 'feature'])
            ->where('status', 'reserved')
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->where('created_at', '<=', $leaseStartedBefore)
            ->distinct()
            ->orderBy('enrollment_id')
            ->limit(max(1, min(5000, $limit)))
            ->get();
        $released = 0;

        foreach ($pairs as $pair) {
            $released += DB::transaction(function () use ($pair): int {
                $usage = AiEntitlementUsage::query()
                    ->where('enrollment_id', $pair->enrollment_id)
                    ->where('feature', $pair->feature)
                    ->lockForUpdate()
                    ->first();
                if (!$usage) {
                    $orphaned = AiUsageEvent::query()
                        ->where('enrollment_id', $pair->enrollment_id)
                        ->where('feature', $pair->feature)
                        ->where('status', 'reserved')
                        ->where('reservation_expires_at', '<=', now())
                        ->lockForUpdate()
                        ->get();
                    foreach ($orphaned as $event) {
                        $metadata = is_array($event->metadata) ? $event->metadata : [];
                        if (data_get($metadata, 'provider_call_state') === PaidAiCallExecutionService::LANDED) {
                            continue;
                        }
                        $started = in_array(
                            data_get($metadata, 'provider_call_state'),
                            ['started', 'outcome_unknown'], true
                        );
                        $metadata['reason'] = 'missing_entitlement_aggregate';
                        if ($started) {
                            $event->forceFill(['metadata' => $metadata])->save();
                            $this->finalizeUnknownProviderOutcome(
                                $event,
                                'reservation_expired_after_provider_start'
                            );
                            continue;
                        }
                        $event->forceFill([
                            'status' => 'expired',
                            'metadata' => $metadata,
                            'completed_at' => now(),
                        ])->save();
                    }
                    return $orphaned->count();
                }

                return $this->reclaimExpiredReservations($usage);
            }, 3);
        }

        return $released;
    }

    private function reclaimExpiredReservations(AiEntitlementUsage $usage): int
    {
        $leaseStartedBefore = now()->subSeconds($this->reservationTtlSeconds());
        $expired = AiUsageEvent::query()
            ->where('enrollment_id', $usage->enrollment_id)
            ->where('feature', $usage->feature)
            ->where('status', 'reserved')
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->where('created_at', '<=', $leaseStartedBefore)
            ->lockForUpdate()
            ->get();
        if ($expired->isEmpty()) {
            return 0;
        }
        $expired = $expired->reject(
            static fn (AiUsageEvent $event): bool =>
                data_get($event->metadata, 'provider_call_state') === PaidAiCallExecutionService::LANDED
        );
        if ($expired->isEmpty()) return 0;

        $unknownProviderOutcomes = $expired->filter(
            static fn (AiUsageEvent $event): bool =>
                in_array($event->feature, [
                    AiEntitlementUsage::FEATURE_COURSE_CHAT,
                    AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK,
                    AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP,
                ], true) && in_array(
                    data_get($event->metadata, 'provider_call_state'),
                    ['started', 'outcome_unknown'],
                    true
                )
        );
        $aggregateEvents = $expired->reject(
            static fn (AiUsageEvent $event): bool =>
                (bool) data_get($event->metadata, 'reservation_detached', false)
        );
        $aggregateUnknownOutcomes = $unknownProviderOutcomes->filter(
            fn (AiUsageEvent $event): bool => $aggregateEvents->contains('id', $event->id)
        );
        $reservedTokens = (int) $aggregateEvents->sum('reserved_tokens');
        $reservedCostMicros = $aggregateEvents->sum(fn (AiUsageEvent $event): int =>
            $this->toUsdMicros($event->reserved_cost_usd)
        );
        $usageUpdate = [
            'reserved_requests' => max(0, $usage->reserved_requests - $aggregateEvents->count()),
            'reserved_tokens' => max(0, $usage->reserved_tokens - $reservedTokens),
            'reserved_cost_usd' => $this->formatUsdMicros(max(
                0,
                $this->toUsdMicros($usage->reserved_cost_usd) - $reservedCostMicros
            )),
        ];
        if (DatabaseCapabilities::hasColumn(
            'ai_entitlement_usages',
            'unanswered_provider_requests'
        )) {
            $exposure = $this->nextProviderExposureState(
                $usage,
                $aggregateUnknownOutcomes->count()
            );
            $usageUpdate += $exposure['attributes'];
            if ($exposure['opened']) {
                $enrollmentId = (int) $usage->enrollment_id;
                $count = $exposure['count'];
                $firstEventId = (int) ($aggregateUnknownOutcomes->first()?->id ?? 0);
                $this->recordProviderExposureAlert($enrollmentId, $count, $firstEventId);
            }
        }
        $usage->forceFill($usageUpdate)->save();

        foreach ($expired as $event) {
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            if ($unknownProviderOutcomes->contains('id', $event->id)) {
                $this->finalizeUnknownProviderOutcome(
                    $event,
                    'reservation_expired_after_provider_start'
                );
                continue;
            }
            $metadata['reason'] = 'reservation_expired';
            $event->forceFill([
                'status' => 'expired',
                'metadata' => $metadata,
                'completed_at' => now(),
            ])->save();
        }

        return $expired->count();
    }

    /**
     * Close a provider-started request whose answer is unknowable. The event
     * remains the immutable platform-cost record, while learner entitlement
     * is never debited or fulfilled. Caller owns the surrounding row locks.
     */
    private function finalizeUnknownProviderOutcome(
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
        $costMicros = $this->toUsdMicros($event->reserved_cost_usd);
        $egpFacts = [];
        if (DatabaseCapabilities::hasColumn('ai_usage_events', 'cost_egp')) {
            $fxRate = max(
                0,
                (float) (Setting::query()->value('openrouter_usd_to_egp_rate') ?? 0)
            );
            if ($fxRate > 0) {
                $egpFacts = [
                    'fx_rate_to_egp' => number_format($fxRate, 4, '.', ''),
                    'cost_egp' => number_format(
                        ($costMicros / 1_000_000) * $fxRate,
                        6,
                        '.',
                        ''
                    ),
                ];
            }
        }
        $event->forceFill([
            'status' => 'completed',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => (int) $event->reserved_tokens,
            'cost_usd' => $this->formatUsdMicros($costMicros),
            'metadata' => $metadata,
            'completed_at' => now(),
        ] + $egpFacts)->save();
        $this->recordSettledUsageSignal($event);
    }

    /** Reset an elapsed circuit/window, or reject only this enrollment briefly. */
    private function refreshProviderExposureCircuit(AiEntitlementUsage $usage): void
    {
        if (!DatabaseCapabilities::hasColumn(
            'ai_entitlement_usages',
            'provider_exposure_paused_until'
        )) {
            return;
        }

        $now = now();
        if ($usage->provider_exposure_paused_until?->isFuture()) {
            throw new AiProviderExposureLimitReachedException(
                'This enrollment is temporarily paused after unknown provider outcomes.'
            );
        }
        $windowElapsed = $usage->unanswered_provider_last_at
            && $usage->unanswered_provider_last_at->lte(
                $now->copy()->subSeconds($this->providerExposureWindowSeconds())
            );
        if ($usage->provider_exposure_paused_until || $windowElapsed) {
            $usage->forceFill([
                'unanswered_provider_requests' => 0,
                'unanswered_provider_last_at' => null,
                'provider_exposure_paused_until' => null,
            ])->save();
        }
    }

    /** @return array{attributes:array<string,mixed>,count:int,opened:bool} */
    private function nextProviderExposureState(
        AiEntitlementUsage $usage,
        int $additional
    ): array {
        $now = now();
        $insideWindow = $usage->unanswered_provider_last_at
            && $usage->unanswered_provider_last_at->gt(
                $now->copy()->subSeconds($this->providerExposureWindowSeconds())
            );
        $count = ($insideWindow ? (int) $usage->unanswered_provider_requests : 0)
            + max(0, $additional);
        $limit = max(
            1,
            (int) config('course_plans.ai_unanswered_provider_request_limit', 4)
        );
        $wasPaused = $usage->provider_exposure_paused_until?->isFuture() ?? false;
        $pausedUntil = $count >= $limit
            ? $now->copy()->addSeconds($this->providerExposureCooldownSeconds())
            : null;

        return [
            'attributes' => [
                'unanswered_provider_requests' => $count,
                'unanswered_provider_last_at' => $now,
                'provider_exposure_paused_until' => $pausedUntil,
            ],
            'count' => $count,
            'opened' => !$wasPaused && $pausedUntil !== null,
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

    private function recordProviderExposureAlert(
        int $enrollmentId,
        int $actual,
        int $eventId
    ): void {
        $metric = 'unanswered_provider_requests';
        $period = 'enrollment-' . $enrollmentId;
        $threshold = max(
            1,
            (int) config('course_plans.ai_unanswered_provider_request_limit', 4)
        );
        $this->internalSignals->record(
            'ai_usage.threshold',
            "provider-exposure:{$enrollmentId}:event:{$eventId}",
            compact('metric', 'period', 'actual', 'threshold'),
            'course_enrollment',
            $enrollmentId
        );
    }

    private function providerExposureWindowSeconds(): int
    {
        return max(
            60,
            (int) config('course_plans.ai_unanswered_provider_window_seconds', 600)
        );
    }

    private function providerExposureCooldownSeconds(): int
    {
        return max(
            60,
            (int) config('course_plans.ai_provider_exposure_cooldown_seconds', 300)
        );
    }

    /** @param int|float|string|null $value */
    private function toUsdMicros($value): int
    {
        return max(0, (int) round(((float) $value) * 1_000_000));
    }

    private function formatUsdMicros(int $micros): string
    {
        return number_format(max(0, $micros) / 1_000_000, 6, '.', '');
    }

    private function reservationTtlSeconds(): int
    {
        return max(
            1200,
            (int) config('course_plans.ai_reservation_ttl_seconds', 1200),
            (int) config('openrouter.queue_stale_seconds', 900)
                + (int) config('openrouter.timeout_seconds', 45)
                + 60
        );
    }
}
