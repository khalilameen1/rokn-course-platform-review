<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiUsageEvent;
use App\Services\OpenRouterCostReconciliationService;
use Illuminate\Console\Command;

final class ReconcileOpenRouterCosts extends Command
{
    protected $signature = 'ai:reconcile-provider-costs {--limit=10}';
    protected $description = 'Read missing OpenRouter generation billing receipts without generating answers';

    public function handle(OpenRouterCostReconciliationService $service): int
    {
        $events = AiUsageEvent::query()->where('status', 'completed')
            ->where(fn ($query) => $query->whereNull('metadata->cost_usage_source')
                ->orWhere('metadata->cost_usage_source', '!=', 'provider'))
            ->where(fn ($query) => $query->where('provider_request_id', '!=', '')
                ->orWhere('metadata->provider_generation_id', '!=', ''))
            // Unresolvable/conflicting identities stay unknown, but must not
            // occupy every batch slot ahead of resolvable provider receipts.
            ->where(fn ($query) => $query->whereNull('provider_request_id')
                ->orWhere('provider_request_id', '')
                ->orWhereNull('metadata->provider_generation_id')
                ->orWhere('metadata->provider_generation_id', '')
                ->orWhereColumn('metadata->provider_generation_id', 'provider_request_id'))
            ->where(fn ($query) => $query->whereNull('metadata->cost_reconciliation->next_attempt_at')
                ->orWhere('metadata->cost_reconciliation->next_attempt_at', '<=', now()->timestamp))
            ->orderBy('updated_at')->orderBy('id')
            ->limit(max(1, min(25, (int) $this->option('limit'))))
            ->pluck('id');
        $counts = ['confirmed' => 0, 'pending' => 0, 'skipped' => 0];
        foreach ($events as $id) $counts[$service->reconcile((int) $id)]++;
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
