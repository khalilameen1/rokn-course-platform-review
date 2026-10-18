<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecoveryCheckpointService;
use App\Services\RecoveryEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class VerifyBackupArtifact extends Command
{
    protected $signature = 'ops:verify-backup
        {--artifact= : Absolute .sql or .sql.gz backup path}
        {--snapshot-at= : Provider snapshot completion time in ISO-8601}
        {--provider= : Backup provider name}
        {--evidence= : Signed evidence output path or configured-disk object key}';

    protected $description = 'Verify a backup artifact, checkpoint lag and signed operational evidence';

    public function handle(RecoveryCheckpointService $checkpoints, RecoveryEvidenceService $evidence): int
    {
        try {
            $artifact = (string) $this->option('artifact');
            $provider = trim((string) $this->option('provider'));
            $snapshotValue = trim((string) $this->option('snapshot-at'));
            if ($snapshotValue === '') {
                throw new RuntimeException('Provide the provider snapshot completion time with --snapshot-at.');
            }
            $snapshotAt = Carbon::parse($snapshotValue)->utc();
            if ($snapshotAt->gt(now()->utc()->addMinutes(5))) {
                throw new RuntimeException('Snapshot completion time cannot be in the future.');
            }
            $path = (string) ($this->option('evidence') ?: config('operations.backup_evidence_path'));
            if ($artifact === '' || ! is_file($artifact) || ! is_readable($artifact) || filesize($artifact) < 1) {
                throw new RuntimeException('Provide a readable non-empty backup artifact.');
            }
            if (! preg_match('/\.(?:sql|sql\.gz)$/i', $artifact) || $provider === '') {
                throw new RuntimeException('Only named-provider .sql or .sql.gz artifacts are supported.');
            }
            $this->assertReadableStream($artifact);
            $marker = $checkpoints->status();
            if (! ($marker['ready'] ?? false)) {
                throw new RuntimeException('Recovery marker is not decryptable.');
            }
            $checkpointAt = Carbon::parse((string) $marker['checkpoint_at'])->utc();
            if ($checkpointAt->gt($snapshotAt->copy()->addMinutes(5))) {
                throw new RuntimeException('Snapshot time predates the database checkpoint unexpectedly.');
            }
            $rpoSeconds = $checkpointAt->lt($snapshotAt)
                ? $checkpointAt->diffInSeconds($snapshotAt)
                : 0;

            $payload = [
                'version' => 1,
                'verified_at' => now()->utc()->toIso8601String(),
                'snapshot_at' => $snapshotAt->toIso8601String(),
                'provider' => mb_substr($provider, 0, 100),
                'artifact_sha256' => hash_file('sha256', $artifact),
                'artifact_bytes' => filesize($artifact),
                'marker_generation' => $marker['generation'],
                'encryption_key_id' => (string) config('operations.recovery_encryption_key_id'),
                'checkpoint_at' => $checkpointAt->toIso8601String(),
                'rpo_seconds' => $rpoSeconds,
            ];
            $evidence->write($path, $payload);
            $this->info('Backup artifact verified. Evidence: '.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Backup verification failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function assertReadableStream(string $artifact): void
    {
        if (! str_ends_with(strtolower($artifact), '.gz')) {
            $handle = fopen($artifact, 'rb');
            $sample = is_resource($handle) ? fread($handle, 4096) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (! is_string($sample) || trim($sample) === '') {
                throw new RuntimeException('SQL artifact is unreadable.');
            }

            return;
        }
        $handle = gzopen($artifact, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('Compressed artifact cannot be opened.');
        }
        $bytes = 0;
        while (! gzeof($handle)) {
            $chunk = gzread($handle, 1024 * 1024);
            if ($chunk === false) {
                gzclose($handle);
                throw new RuntimeException('Compressed artifact failed integrity reading.');
            }
            $bytes += strlen($chunk);
        }
        gzclose($handle);
        if ($bytes < 1) {
            throw new RuntimeException('Compressed artifact expands to no data.');
        }
    }
}
