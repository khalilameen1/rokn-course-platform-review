<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;

final class AiFailurePolicy
{
    /** @return array{category:string,can_retry:bool,retry_after_seconds:int} */
    public function describe(?string $code): array
    {
        $code = trim((string) $code);

        return match ($code) {
            'chat_provider_outcome_unknown',
            'provider_outcome_unknown' => $this->result('unknown_outcome', false, 0),

            'chat_plan_limit_reached',
            'plan_limit_reached',
            'plan_budget_reached' => $this->result('plan_limit', false, 0),

            'chat_entitlement_unavailable',
            'entitlement_unavailable',
            'account_unavailable',
            'reply_not_included',
            'report_not_included' => $this->result('entitlement', false, 0),

            'chat_attachment_unavailable',
            'chat_attachment_unreadable',
            'attachment_unavailable' => $this->result('attachment', false, 0),

            'chat_attachment_claim_failed',
            'chat_usage_identity_mismatch',
            'chat_reservation_unavailable',
            'project_context_missing',
            'submission_not_found',
            'message_not_found' => $this->result('request', false, 0),

            'ai_rate_limited' => $this->result('provider', true, 15),
            'ai_configuration_unavailable',
            'ai_request_rejected' => $this->result('provider', false, 0),
            'ai_temporarily_unavailable',
            'provider_unavailable' => $this->result('provider', true, 30),

            'learner_cancelled',
            'chat_turn_cancelled' => $this->result('cancelled', false, 0),

            default => $this->result('transient', true, 3),
        };
    }

    public function providerCode(AiProviderUnavailableException $exception): string
    {
        $status = $exception->providerStatus;
        if (in_array($exception->providerCode, [
            'not_configured',
            'model_not_allowed',
            'configuration_circuit_open',
        ], true)) {
            return 'ai_configuration_unavailable';
        }
        if ($status === 429) {
            return 'ai_rate_limited';
        }
        if ($status !== null && in_array($status, [400, 401, 402, 403, 404, 422], true)) {
            return in_array($status, [401, 402, 403], true)
                ? 'ai_configuration_unavailable'
                : 'ai_request_rejected';
        }

        return 'ai_temporarily_unavailable';
    }

    /** @return array{category:string,can_retry:bool,retry_after_seconds:int} */
    private function result(string $category, bool $canRetry, int $retryAfter): array
    {
        return [
            'category' => $category,
            'can_retry' => $canRetry,
            'retry_after_seconds' => $retryAfter,
        ];
    }
}
