<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\InternalSignal;
use App\Models\Setting;
use App\Support\BusinessClock;

/**
 * Observes platform-wide AI spend without turning one incident into an outage
 * for every paid learner. Hard enforcement remains scoped to each immutable
 * enrollment plan in AiEntitlementBudgetService.
 */
final class AiPlatformUsageMonitor
{
    public function __construct(
        private readonly InternalSignalService $internalSignals
    ) {}

    public function record(int $eventId): void
    {
        $event = AiUsageEvent::query()->find($eventId);
        if (! $event || $event->status !== 'completed') {
            return;
        }

        $completedAt = $event->completed_at ?: $event->updated_at ?: now();
        $businessCompletedAt = $completedAt->copy()->utc()->setTimezone(BusinessClock::timezoneName());
        $day = $businessCompletedAt->format('Y-m-d');
        $month = $businessCompletedAt->format('Y-m');
        $metrics = $this->refreshMetrics($day, $month);

        $this->alertOnce(
            'daily_requests',
            $day,
            $metrics['daily_requests'],
            $this->threshold('ai_global_daily_request_limit', 'openrouter.global_daily_request_limit')
        );
        $this->alertOnce(
            'daily_tokens',
            $day,
            $metrics['daily_tokens'],
            $this->threshold('ai_global_daily_token_budget', 'openrouter.global_daily_token_budget')
        );
        $this->alertOnce(
            'monthly_tokens',
            $month,
            $metrics['monthly_tokens'],
            $this->threshold('ai_global_monthly_token_budget', 'openrouter.global_monthly_token_budget')
        );
    }

    /** @return array{daily_requests:int,daily_tokens:int,monthly_tokens:int} */
    private function refreshMetrics(string $day, string $month): array
    {
        [$dayStart, $dayEnd] = BusinessClock::localDayRangeUtc($day);
        $monthStartLocal = BusinessClock::localDate($month.'-01')->startOfMonth();
        $monthStart = $monthStartLocal->utc();
        $monthEnd = $monthStartLocal->addMonth()->utc();
        $daily = AiUsageEvent::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $dayStart)
            ->where('completed_at', '<', $dayEnd)
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS tokens')
            ->first();
        $dailyRequests = (int) ($daily?->requests ?? 0);
        $dailyTokens = (int) ($daily?->tokens ?? 0);
        $monthlyTokens = (int) AiUsageEvent::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $monthStart)
            ->where('completed_at', '<', $monthEnd)
            ->sum('total_tokens');

        return [
            'daily_requests' => $dailyRequests,
            'daily_tokens' => $dailyTokens,
            'monthly_tokens' => $monthlyTokens,
        ];
    }

    private function alertOnce(string $metric, string $period, int $actual, int $threshold): void
    {
        if ($threshold <= 0 || $actual < $threshold) {
            return;
        }
        $identity = "metric:{$metric}:period:{$period}";
        try {
            $this->internalSignals->record(
                'ai_usage.threshold',
                $identity,
                compact('metric', 'period', 'actual', 'threshold'),
                'ai_usage_period',
                "{$metric}:{$period}"
            );
        } catch (\UnexpectedValueException $exception) {
            // Two completed calls can cross the same threshold together. The
            // first durable alert owns the period; a later, higher snapshot is
            // not an identity conflict that should stall usage settlement.
            $exists = InternalSignal::query()
                ->where('signal_key', hash('sha256', 'ai_usage.threshold|'.$identity))
                ->where('type', 'ai_usage.threshold')
                ->exists();
            if (! $exists) {
                throw $exception;
            }
        }
    }

    private function threshold(string $field, string $configKey): int
    {
        $deployment = max(0, (int) config($configKey, 0));
        $dashboard = max(0, (int) (Setting::query()->value($field) ?? 0));

        if ($deployment === 0) {
            return $dashboard;
        }

        return $dashboard > 0 ? min($deployment, $dashboard) : $deployment;
    }
}
