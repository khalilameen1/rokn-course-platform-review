<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class DispatchPendingAccountFileDeletion extends Command
{
    protected $signature = 'privacy:cleanup-account-files {--limit=500}';

    protected $description = 'Dispatch durable account-file deletion outbox entries';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $maxAttempts = max(10, (int) config('operations.account_file_cleanup_max_attempts', 20));
        $rows = AccountFileDeletion::query()
            ->where(function ($query) use ($maxAttempts): void {
                $query->where('status', AccountFileDeletion::STATUS_PENDING)
                    ->orWhere(function ($failed) use ($maxAttempts): void {
                        $failed->where('status', AccountFileDeletion::STATUS_FAILED)
                            ->where('attempts', '<', $maxAttempts);
                    })->orWhere(function ($processing) use ($maxAttempts): void {
                        $processing->where('status', AccountFileDeletion::STATUS_PROCESSING)
                            ->where('attempts', '<', $maxAttempts)
                            ->where('updated_at', '<=', now()->subMinutes(15));
                    });
            })
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        $dispatched = 0;
        foreach ($rows as $row) {
            try {
                DurableJobDispatch::now(new DeleteAccountFile((int) $row->id));
                $dispatched++;
            } catch (\Throwable $exception) {
                Log::warning('Unable to dispatch account-file cleanup.', [
                    'deletion_id' => $row->id,
                    'exception' => get_class($exception),
                ]);
            }
        }

        $this->info("Queued {$dispatched} account-file cleanup job(s).");

        return self::SUCCESS;
    }
}
