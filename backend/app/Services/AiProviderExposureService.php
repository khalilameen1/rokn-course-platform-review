<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderExposureLimitReachedException;
use App\Models\AiEntitlementUsage;
use App\Support\DatabaseCapabilities;

final readonly class AiProviderExposureService
{
    public function __construct(private InternalSignalService $internalSignals)
    {
    }

    /** Caller holds the aggregate lock inside the reservation transaction. */
    public function refresh(AiEntitlementUsage $usage): void
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
    public function nextState(
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

    public function recordAlert(
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
}
