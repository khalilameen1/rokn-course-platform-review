<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Erases personal AI content without losing evidence of paid provider work. */
final class AccountAiDataErasureService
{
    public function __construct(
        private readonly AiEntitlementBudgetService $aiBudget,
        private readonly AiUsageSettlementService $settlements,
        private readonly PaidAiCallExecutionService $paidAiCalls
    ) {
    }

    /** The account owner holds the learner lock in the deletion transaction. */
    public function eraseWithinDeletion(int $userId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('AI account erasure must share the account-deletion transaction.');
        }

        // Usage totals and costs remain as financial/operational evidence,
        // but accepted AI replies and request context are personal content.
        if (Schema::hasTable('ai_usage_events')) {
            // Preserve whether a paid provider call had started before
            // scrubbing metadata. Started work becomes unknown exposure;
            // work that never left our queue simply releases its reserve.
            AiUsageEvent::query()
                ->where('user_id', $userId)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get()
                ->each(function ($event): void {
                    $landed = $this->paidAiCalls->landedResult($event);
                    if ($landed !== null) {
                        // The provider result and actual usage are known,
                        // but deletion forbids presenting or retaining the
                        // answer. Settle the cost exactly, without charging
                        // learner entitlement, then scrub the landing.
                        $landed['entitlement_delivered'] = false;
                        $landed['request_context'] = ['reason' => 'account_deleted'];
                        $this->settlements->settle($event, $landed);
                        $this->paidAiCalls->markPresented($event->fresh());
                        return;
                    }
                    if ($this->paidAiCalls->providerWasStarted($event)) {
                        $this->settlements->settleUnknown($event, ['reason' => 'account_deleted']);
                    } else {
                        $this->aiBudget->release($event, 'account_deleted');
                    }
                });
            AiUsageEvent::query()
                ->where('user_id', $userId)
                ->get()
                ->each(function ($event): void {
                    $metadata = is_array($event->metadata) ? $event->metadata : [];
                    $context = is_array($metadata['request_context'] ?? null)
                        ? $metadata['request_context'] : [];
                    $operational = array_filter([
                        'entitlement_delivered' => $metadata['entitlement_delivered'] ?? null,
                        'token_usage_source' => $metadata['token_usage_source'] ?? null,
                        'cost_usage_source' => $metadata['cost_usage_source'] ?? null,
                        'usage_source' => $metadata['usage_source'] ?? null,
                        'provider_call_state' => $metadata['provider_call_state'] ?? null,
                        'provider_outcome_reason' => $metadata['provider_outcome_reason'] ?? null,
                        'provider_call_attempt' => $metadata['provider_call_attempt'] ?? null,
                        'provider_outcome_recorded_at' => $metadata['provider_outcome_recorded_at'] ?? null,
                        'reservation_detached' => $metadata['reservation_detached'] ?? null,
                        'entitlement_transition_reason' => $metadata['entitlement_transition_reason'] ?? null,
                        'entitlement_transitioned_at' => $metadata['entitlement_transitioned_at'] ?? null,
                        // A repeated cleanup sees the already flattened
                        // operational fields, not the erased request context.
                        'prompt_version' => $context['prompt_version'] ?? $metadata['prompt_version'] ?? null,
                        'feedback_level' => $context['feedback_level'] ?? $metadata['feedback_level'] ?? null,
                    ], static fn ($value): bool => $value !== null && $value !== '');
                    $event->forceFill([
                        'metadata' => $operational ?: null,
                        'updated_at' => now(),
                    ])->save();
                });
        }
    }
}
