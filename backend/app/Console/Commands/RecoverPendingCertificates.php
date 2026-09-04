<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecoverPendingCertificate;
use App\Models\Certificate;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class RecoverPendingCertificates extends Command
{
    protected $signature = 'certificates:recover-pending {--limit=100}';
    protected $description = 'Recover certificate artifacts left pending by interrupted workers';

    public function handle(): int
    {
        if (!Schema::hasColumns('certificates', [
            'recovery_attempts',
            'recovery_next_attempt_at',
            'recovery_failed_at',
            'artifact_checked_at',
        ])) {
            return self::SUCCESS;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $staleBefore = now()->subMinutes(max(
            2,
            (int) config('operations.certificate_recovery_stale_minutes', 5)
        ));

        $candidates = Certificate::query()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(function ($recoverable): void {
                $recoverable->whereNull('recovery_failure_code')
                    ->orWhereNotIn('recovery_failure_code', [
                        'subject_missing',
                        'snapshot_invalid',
                    ]);
            })
            ->where(function ($query): void {
                $query->whereNull('recovery_next_attempt_at')
                    ->orWhere('recovery_next_attempt_at', '<=', now());
            })
            ->where(function ($query) use ($staleBefore): void {
                $query->where(function ($pending) use ($staleBefore): void {
                    $pending->where('image_path', 'pending')
                        ->where('updated_at', '<=', $staleBefore);
                })->orWhereNotNull('recovery_failed_at')
                    ->orWhere(function ($audit): void {
                        $audit->whereNotNull('image_path')
                            ->where('image_path', '!=', 'pending')
                            ->where(function ($due): void {
                                $due->whereNull('artifact_checked_at')
                                    ->orWhere('artifact_checked_at', '<=', now()->subDay());
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        $queued = 0;
        foreach ($candidates as $candidate) {
            try {
                DurableJobDispatch::now(new RecoverPendingCertificate((int) $candidate->id));
                $queued++;
            } catch (Throwable $exception) {
                Log::warning('Pending certificate recovery could not be dispatched.', [
                    'certificate_id' => $candidate->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->info("Queued {$queued} pending certificate recovery job(s).");
        return self::SUCCESS;
    }
}
