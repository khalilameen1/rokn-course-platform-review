<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\KashierReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReconcileKashierPayments extends Command
{
    protected $signature = 'payments:reconcile-kashier
        {--limit=100 : Maximum orders to inspect in this batch}
        {--restart : Restart the provider scan from the start of the reconciliation window}';

    protected $description = 'Reconcile local Kashier orders with the provider and queue mismatches for review';

    public function handle(KashierReconciliationService $reconciler): int
    {
        if (! $this->schemaIsReady()) {
            $this->error('Payment reconciliation tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $lock = Cache::lock(
            (string) config('operations.kashier_reconcile_lock_key'),
            max(300, (int) config('operations.kashier_reconcile_lock_seconds', 1800))
        );
        $acquired = $this->acquire($lock);
        if ($acquired === null) {
            $this->error('The distributed cache lock is unavailable; reconciliation did not start.');

            return self::FAILURE;
        }
        if (! $acquired) {
            $this->warn('Another Kashier reconciliation is already running.');

            return self::SUCCESS;
        }

        try {
            $stats = $reconciler->reconcile(
                max(1, min(1000, (int) $this->option('limit'))),
                (bool) $this->option('restart')
            );
            $this->table(
                ['Checked', 'Consistent', 'Fulfilled', 'Reversed', 'Findings', 'Unavailable', 'Cursor'],
                [[
                    $stats['checked'],
                    $stats['consistent'],
                    $stats['fulfilled'],
                    $stats['reversed'],
                    $stats['findings'],
                    $stats['unavailable'],
                    $stats['cursor'],
                ]]
            );

            if ($stats['findings'] > 0) {
                Log::warning('Kashier reconciliation queued findings for review', [
                    'findings' => $stats['findings'],
                    'checked' => $stats['checked'],
                    'cursor' => $stats['cursor'],
                ]);
                $this->warn('Review the payment reconciliation queue before resolving affected orders.');
            }
            if ($stats['checked'] > 0 && $stats['unavailable'] === $stats['checked']) {
                $this->error('Kashier was unavailable for the entire batch. No financial state was trusted.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Kashier reconciliation failed. Check the application log for the recorded exception.');

            return self::FAILURE;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function schemaIsReady(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasTable('payment_reconciliation_checkpoints')
            && Schema::hasTable('payment_reconciliation_findings');
    }

    private function acquire(Lock $lock): ?bool
    {
        try {
            return $lock->get();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
