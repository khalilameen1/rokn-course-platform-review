<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Services\CertificateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RecoverPendingCertificate implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 900;
    public bool $failOnTimeout = true;
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $certificateId)
    {
        $this->onQueue((string) config('queue.channels.media', 'media'));
    }

    public function uniqueId(): string
    {
        return 'certificate-recovery:' . $this->certificateId;
    }

    public function handle(CertificateService $certificates): void
    {
        $observed = Certificate::query()->find($this->certificateId);
        if (!$observed || !$observed->isActiveCredential()) {
            return;
        }
        if ($observed->hasStoredArtifact()) {
            Certificate::query()->whereKey($this->certificateId)->update([
                'artifact_checked_at' => now(),
            ]);
            return;
        }
        if (!$observed->hasCompleteCredentialSnapshot()) {
            $this->markTerminal('snapshot_invalid');
            return;
        }

        $certificate = DB::transaction(function () use ($observed): ?Certificate {
            $row = Certificate::query()->lockForUpdate()->find($this->certificateId);
            if (
                !$row
                || !$row->isActiveCredential()
                || $row->image_path !== $observed->image_path
            ) {
                return null;
            }

            $generationLeaseId = trim((string) $row->generation_lease_id);
            if ($generationLeaseId !== '') {
                $leaseStaleBefore = now()->subMinutes(max(
                    2,
                    (int) config('operations.certificate_recovery_stale_minutes', 5)
                ));
                if ($row->updated_at?->isAfter($leaseStaleBefore)) {
                    // A live renderer still owns this credential. Recovery must
                    // not steal its work or touch updated_at, because that
                    // timestamp is also the generation lease clock.
                    return null;
                }

                // A worker died after claiming the artifact. Release its stale
                // lease under the same row lock before recovery bookkeeping
                // refreshes updated_at, then CertificateService can claim one
                // new lease and continue the same immutable credential.
                $row->generation_lease_id = null;
            }

            $maxAttempts = max(1, (int) config('operations.certificate_recovery_max_attempts', 3));
            if ((int) $row->recovery_attempts >= $maxAttempts) {
                if (!$row->recovery_next_attempt_at || $row->recovery_next_attempt_at->isFuture()) {
                    return null;
                }
                // Start a new, slow recovery epoch after the provider had time
                // to recover. The durable failure remains visible until an
                // artifact is actually restored.
                $row->recovery_attempts = 0;
            }
            $newRecoveryCycle = $this->attempts() <= 1 || (int) $row->recovery_attempts === 0;
            if ($newRecoveryCycle) {
                if ($row->recovery_next_attempt_at && $row->recovery_next_attempt_at->isFuture()) {
                    return null;
                }
                $row->forceFill([
                    'recovery_attempts' => (int) $row->recovery_attempts + 1,
                    'recovery_next_attempt_at' => now()->addMinutes(15),
                    'recovery_failed_at' => null,
                    'recovery_failure_code' => null,
                ])->save();
            }

            return $row->fresh();
        }, 3);

        if (!$certificate) {
            return;
        }

        // Queue payloads carry only immutable IDs. Deleted or changed models
        // are resolved at execution time instead of being revived from stale
        // serialized snapshots.
        $user = User::query()->find($certificate->user_id);
        // Retirement removes a course from the catalogue, not the achievement
        // already earned from it. Recovery rebuilds from immutable certificate
        // snapshots and therefore must still resolve a soft-deleted course.
        $course = Course::withTrashed()->find($certificate->course_id);
        if (!$user || !$course) {
            $this->markTerminal('subject_missing');
            return;
        }

        $recovered = $certificates->generate($user, $course);
        if (!$recovered || !$recovered->hasStoredArtifact()) {
            throw new RuntimeException('Certificate recovery did not produce an artifact.');
        }
    }

    public function failed(Throwable $exception): void
    {
        $maxAttempts = max(1, (int) config('operations.certificate_recovery_max_attempts', 3));
        $certificate = Certificate::query()->find($this->certificateId);
        if (
            !$certificate
            || !$certificate->isActiveCredential()
            || $certificate->hasStoredArtifact()
        ) {
            return;
        }

        $exhausted = (int) $certificate->recovery_attempts >= $maxAttempts;
        $certificate->forceFill([
            'recovery_next_attempt_at' => $exhausted ? now()->addHours(6) : now()->addMinutes(30),
            'recovery_failed_at' => now(),
            'recovery_failure_code' => hash('sha256', $exception::class . '|' . $exception->getMessage()),
        ])->save();
    }

    private function markTerminal(string $code): void
    {
        Certificate::query()->whereKey($this->certificateId)->update([
            'recovery_attempts' => max(1, (int) config('operations.certificate_recovery_max_attempts', 3)),
            'recovery_next_attempt_at' => null,
            'recovery_failed_at' => now(),
            'recovery_failure_code' => $code,
            'updated_at' => now(),
        ]);
    }
}
