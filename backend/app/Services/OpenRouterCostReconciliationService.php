<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Reconciles accounting only; never regenerates an answer or changes learner quotas. */
final class OpenRouterCostReconciliationService
{
    private const BACKOFF_KEY = 'openrouter:cost-reconciliation:retry-at';

    /** @return 'confirmed'|'pending'|'skipped' */
    public function reconcile(int $eventId): string
    {
        $apiKey = trim((string) config('openrouter.api_key'));
        if ($apiKey === '' || (int) Cache::get(self::BACKOFF_KEY, 0) > now()->timestamp) {
            return 'skipped';
        }
        $lock = Cache::lock('openrouter:cost-reconciliation:event:'.$eventId, 60);
        if (!$lock->get()) return 'skipped';

        try {
            $event = AiUsageEvent::query()->find($eventId);
            $generationId = $event ? $this->eligibleGeneration($event) : null;
            if ($generationId === null || (int) data_get(
                $event->metadata, 'cost_reconciliation.next_attempt_at', 0
            ) > now()->timestamp) return 'skipped';

            $cost = null;
            $reason = 'unavailable';
            $retryAt = now()->addHour()->timestamp;
            try {
                // Official billing metadata endpoint. Never follow a redirect
                // carrying the account credential or request stored content.
                // https://openrouter.ai/docs/api/api-reference/generations/get-generation
                $response = Http::withToken($apiKey)->acceptJson()
                    ->connectTimeout(3)->timeout(8)
                    ->withOptions(['allow_redirects' => false])
                    ->get('https://openrouter.ai/api/v1/generation', ['id' => $generationId]);
                $data = $response->json('data');
                if ($response->successful() && is_array($data)
                    && ($data['id'] ?? null) === $generationId
                    && is_numeric($data['total_cost'] ?? null)
                    && is_finite((float) $data['total_cost'])
                    && (float) $data['total_cost'] >= 0
                    && (float) $data['total_cost'] <= 999999.999999) {
                    $cost = (float) $data['total_cost'];
                    $reason = 'confirmed';
                } elseif ($response->status() === 429) {
                    $retryAfter = trim((string) $response->header('Retry-After'));
                    $seconds = ctype_digit($retryAfter)
                        ? (int) $retryAfter
                        : ((strtotime($retryAfter) ?: now()->addHour()->timestamp) - now()->timestamp);
                    $seconds = max(60, min(86400, $seconds));
                    $retryAt = now()->addSeconds($seconds)->timestamp;
                    Cache::put(self::BACKOFF_KEY, $retryAt, $seconds);
                    $reason = 'rate_limited';
                } elseif ($response->status() === 404) {
                    $reason = 'not_ready';
                } elseif ($response->successful()) {
                    $reason = 'invalid_receipt';
                }
            } catch (ConnectionException) {
                $reason = 'connection_unavailable';
            }

            return DB::transaction(function () use ($eventId, $generationId, $cost, $reason, $retryAt): string {
                $event = AiUsageEvent::query()->lockForUpdate()->find($eventId);
                // Another settlement/receipt may have won while the GET waited.
                if (!$event || $this->eligibleGeneration($event) !== $generationId) return 'skipped';
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $metadata['cost_reconciliation'] = [
                    'checked_at' => now()->toISOString(),
                    'status' => $reason,
                    'next_attempt_at' => $cost === null ? $retryAt : null,
                ];
                $fields = [];
                if ($cost !== null) {
                    $metadata['cost_usage_source'] = 'provider';
                    $metadata['provider_cost_source'] = 'openrouter_generation';
                    $metadata['provider_cost_currency'] = 'USD';
                    // Retain the exact provider fact as well as the existing
                    // decimal-six ledger representation. Zero is a real fact.
                    $metadata['provider_cost_usd'] = $cost;
                    $fields['cost_usd'] = number_format($cost, 6, '.', '');
                    $historicalFx = (float) $event->fx_rate_to_egp;
                    $fields['cost_egp'] = $historicalFx > 0
                        ? number_format($cost * $historicalFx, 6, '.', '') : null;
                }
                $event->forceFill($fields + ['metadata' => $metadata])->save();
                // No changes to reserved_cost_usd, entitlement aggregates,
                // delivery state or completed_at (the original usage window).
                return $cost === null ? 'pending' : 'confirmed';
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function eligibleGeneration(AiUsageEvent $event): ?string
    {
        if ($event->status !== 'completed'
            || data_get($event->metadata, 'cost_usage_source') === 'provider') return null;
        $headerId = trim((string) data_get($event->metadata, 'provider_generation_id', ''));
        $responseId = trim((string) $event->provider_request_id);
        if ($headerId !== '' && $responseId !== '' && $headerId !== $responseId) return null;
        $id = $headerId !== '' ? $headerId : $responseId;
        return $id !== '' && strlen($id) <= 255 ? $id : null;
    }
}
