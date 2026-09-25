<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiPlanLimitReachedException;
use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Support\AiUsageCost;
use App\Support\DatabaseCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AiEntitlementBudgetService
{
    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private FinancialAnomalyService $financialRisk,
        private AiProviderExposureService $providerExposure,
        private AiUsageSettlementService $settlements
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
            $this->providerExposure->refresh($usage);
            $usage->refresh();

            $estimatedTokens = max(1, $estimatedTokens);
            $isChat = $feature === AiEntitlementUsage::FEATURE_COURSE_CHAT;
            $isFollowup = $feature === AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP;
            $tokenBudget = (int) ($isChat
                ? ($terms['chat_token_budget'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_token_budget'] ?? 0)
                    : ($terms['project_feedback_token_budget'] ?? 0)));
            $costBudgetMicros = AiUsageCost::micros($isChat
                ? ($terms['ai_budget_usd'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_budget_usd'] ?? 0)
                    : ($terms['project_feedback_budget_usd'] ?? 0)));
            $reserveCostMicros = max(1, AiUsageCost::micros($isChat
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
                || AiUsageCost::micros($usage->used_cost_usd)
                    + AiUsageCost::micros($usage->reserved_cost_usd)
                    + $reserveCostMicros > $costBudgetMicros
            ) {
                throw new AiPlanLimitReachedException('The selected plan AI budget is exhausted.');
            }

            $usage->forceFill([
                'access_plan_id' => $planId,
                'reserved_requests' => $usage->reserved_requests + 1,
                'reserved_tokens' => $usage->reserved_tokens + $estimatedTokens,
                'reserved_cost_usd' => AiUsageCost::format(
                    AiUsageCost::micros($usage->reserved_cost_usd) + $reserveCostMicros
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
                'reserved_cost_usd' => AiUsageCost::format($reserveCostMicros),
                'reservation_expires_at' => now()->addSeconds($this->reservationTtlSeconds()),
            ]);
        }, 3);
    }

    /** Compulsory relevance review is platform funded, never a report/message entitlement. */
    public function reserveProjectReview(
        CourseEnrollment $enrollment,
        int $estimatedTokens,
        string $model,
        string $requestId
    ): AiUsageEvent {
        return DB::transaction(function () use ($enrollment, $estimatedTokens, $model, $requestId): AiUsageEvent {
            User::query()->whereKey($enrollment->user_id)->where('active', true)
                ->lockForUpdate()->firstOrFail();
            $locked = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            if (!$locked->isActive()) {
                throw new AiPlanLimitReachedException('The course entitlement is not active.');
            }
            $existing = AiUsageEvent::query()->where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->enrollment_id !== (int) $locked->id
                    || $existing->feature !== AiUsageEvent::FEATURE_PROJECT_REVIEW) {
                    throw new \UnexpectedValueException('AI request identity conflict.');
                }
                return $existing;
            }
            $today = AiUsageEvent::query()->where('user_id', $locked->user_id)
                ->where('feature', AiUsageEvent::FEATURE_PROJECT_REVIEW)
                ->where('created_at', '>=', now()->startOfDay())->count();
            if ($today >= max(1, (int) config('projects.evaluation_daily_attempt_limit', 60))) {
                throw new AiPlanLimitReachedException('The platform review daily limit is reached.');
            }
            return AiUsageEvent::query()->create([
                'request_id' => $requestId,
                'enrollment_id' => $locked->id,
                'access_plan_id' => $locked->access_plan_id,
                'user_id' => $locked->user_id,
                'course_id' => $locked->course_id,
                'feature' => AiUsageEvent::FEATURE_PROJECT_REVIEW,
                'model' => $model,
                'status' => 'reserved',
                'reserved_tokens' => max(1, $estimatedTokens),
                'reserved_cost_usd' => AiUsageCost::format(max(1, AiUsageCost::micros(
                    config('projects.evaluation_reserve_usd', '0.050000')
                ))),
                'reservation_expires_at' => now()->addSeconds($this->reservationTtlSeconds()),
                // Existing detached settlement records provider costs without touching
                // mutable entitlement aggregates, including unknown provider outcomes.
                'metadata' => ['funding_source' => 'platform', 'reservation_detached' => true],
            ]);
        }, 3);
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
                    'reserved_cost_usd' => AiUsageCost::format(max(
                        0,
                        AiUsageCost::micros($usage->reserved_cost_usd)
                            - AiUsageCost::micros($lockedEvent->reserved_cost_usd)
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
                ->where('feature', '!=', AiUsageEvent::FEATURE_PROJECT_REVIEW)
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
                    AiUsageCost::micros($event->reserved_cost_usd)
                );
                $usage->forceFill([
                    'reserved_requests' => max(0, $usage->reserved_requests - $events->count()),
                    'reserved_tokens' => max(0, $usage->reserved_tokens - (int) $events->sum('reserved_tokens')),
                    'reserved_cost_usd' => AiUsageCost::format(max(
                        0,
                        AiUsageCost::micros($usage->reserved_cost_usd) - $costMicros
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
                        $metadata['reason'] = ($metadata['funding_source'] ?? null) === 'platform'
                            ? 'platform_reservation_expired'
                            : 'missing_entitlement_aggregate';
                        if ($started) {
                            $event->forceFill(['metadata' => $metadata])->save();
                            $this->settlements->finalizeLockedUnknownOutcome(
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
            AiUsageCost::micros($event->reserved_cost_usd)
        );
        $usageUpdate = [
            'reserved_requests' => max(0, $usage->reserved_requests - $aggregateEvents->count()),
            'reserved_tokens' => max(0, $usage->reserved_tokens - $reservedTokens),
            'reserved_cost_usd' => AiUsageCost::format(max(
                0,
                AiUsageCost::micros($usage->reserved_cost_usd) - $reservedCostMicros
            )),
        ];
        if (DatabaseCapabilities::hasColumn(
            'ai_entitlement_usages',
            'unanswered_provider_requests'
        )) {
            $exposure = $this->providerExposure->nextState(
                $usage,
                $aggregateUnknownOutcomes->count()
            );
            $usageUpdate += $exposure['attributes'];
            if ($exposure['opened']) {
                $enrollmentId = (int) $usage->enrollment_id;
                $count = $exposure['count'];
                $firstEventId = (int) ($aggregateUnknownOutcomes->first()?->id ?? 0);
                $this->providerExposure->recordAlert($enrollmentId, $count, $firstEventId);
            }
        }
        $usage->forceFill($usageUpdate)->save();

        foreach ($expired as $event) {
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            if ($unknownProviderOutcomes->contains('id', $event->id)) {
                $this->settlements->finalizeLockedUnknownOutcome(
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
