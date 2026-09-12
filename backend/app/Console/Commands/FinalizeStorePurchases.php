<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StorePurchaseFinalizationService;
use Illuminate\Console\Command;

final class FinalizeStorePurchases extends Command
{
    protected $signature = 'payments:finalize-store-purchases {--limit=25 : Maximum committed receipts to retry}';

    protected $description = 'Consume verified, credited Google Play purchases without repeating wallet credit';

    public function handle(StorePurchaseFinalizationService $finalization): int
    {
        $ids = $finalization->due()->orderBy('finalization_retry_at')->orderBy('id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        $completed = 0;
        foreach ($ids as $id) {
            if ($finalization->attempt((int) $id)) $completed++;
        }
        $this->info("Google Play finalization: {$completed}/{$ids->count()} completed; remaining receipts retain durable retries.");

        return $completed === $ids->count() ? self::SUCCESS : self::FAILURE;
    }
}
