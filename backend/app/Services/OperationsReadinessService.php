<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class OperationsReadinessService
{
    public function __construct(private readonly RecoveryEvidenceService $recoveryEvidence) {}

    /** @return array<string, mixed>|null */
    public function mediaReconcileStatus(): ?array
    {
        try {
            $value = Cache::get((string) config(
                'operations.media_reconcile_status_key',
                'operations:media-reconcile:status:v1'
            ));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    public function backupReadiness(): array
    {
        $runbookPath = base_path('PRODUCTION_RUNBOOK.md');
        $recovery = $this->recoveryEvidence->readiness();
        $checks = [
            'runbook' => is_file($runbookPath),
            'recovery_mode_cleared' => !(bool) ($recovery['recovery_mode'] ?? false),
        ] + (array) ($recovery['checks'] ?? []);

        return [
            'ready' => !in_array(false, $checks, true),
            'checks' => $checks,
            'provider' => $recovery['provider'] ?? null,
            'last_backup_at' => $recovery['last_backup_at'] ?? null,
            'last_restore_drill_at' => $recovery['last_restore_drill_at'] ?? null,
            'rpo_seconds' => $recovery['rpo_seconds'] ?? null,
            'rto_seconds' => $recovery['rto_seconds'] ?? null,
            'recovery_mode' => (bool) ($recovery['recovery_mode'] ?? false),
            'runbook' => is_file($runbookPath) ? 'PRODUCTION_RUNBOOK.md' : null,
            'note' => 'Signed read-only evidence. The dashboard never starts a backup or restore.',
        ];
    }
}
