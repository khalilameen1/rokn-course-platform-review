<?php

declare(strict_types=1);

namespace App\Support;

final class ProjectReportRetryPolicy
{
    public static function allows(
        string $failureReason,
        int $retryCount,
        ?string $eventStatus,
        string $providerState = '',
        bool $hasAcceptedResponse = false,
        bool $inputsPurged = false,
        bool $hasLandedResponse = false
    ): bool {
        if (
            !in_array($failureReason, [
                'provider_unavailable',
                'ai_rate_limited',
                'worker_failed',
            ], true)
            || $retryCount >= 2
        ) {
            return false;
        }
        if ($eventStatus === 'completed') {
            return $hasAcceptedResponse;
        }
        if ($eventStatus === 'reserved') {
            return $hasLandedResponse;
        }
        // Durable results replay their existing paid event without its input.
        // A deliberately retired submission cannot support a new provider call.
        if ($inputsPurged) {
            return false;
        }
        if ($eventStatus === null) {
            return true;
        }

        return $eventStatus === 'failed'
            && in_array($providerState, ['', 'retry_safe'], true);
    }

    private function __construct()
    {
    }
}
