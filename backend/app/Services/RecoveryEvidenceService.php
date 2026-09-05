<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\StorageWriteOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class RecoveryEvidenceService
{
    public function __construct(private readonly RecoveryCheckpointService $checkpoints) {}

    /** @return array<string,mixed> */
    public function readiness(): array
    {
        $marker = $this->checkpoints->status();
        $backup = $this->readSigned((string) config('operations.backup_evidence_path'));
        $restore = $this->readSigned((string) config('operations.recovery_evidence_path'));
        $generation = (string) ($marker['generation'] ?? '');
        $keyId = trim((string) config('operations.recovery_encryption_key_id'));
        $backupAge = max(1, (int) config('operations.backup_max_age_hours', 26));
        $drillAge = max(1, (int) config('operations.restore_drill_max_age_days', 90));
        $rpo = max(1, (int) config('operations.recovery_rpo_minutes', 15)) * 60;
        $rto = max(1, (int) config('operations.recovery_rto_minutes', 60)) * 60;
        $recoveryMode = (bool) config('operations.disaster_recovery_mode', false);

        $checks = [
            'marker_decryptable' => (bool) ($marker['ready'] ?? false),
            'backup_evidence_signed' => $backup !== null,
            'restore_evidence_signed' => $restore !== null,
            'backup_recent' => $this->recent($backup['snapshot_at'] ?? null, now()->subHours($backupAge)),
            'restore_drill_recent' => $this->recent($restore['verified_at'] ?? null, now()->subDays($drillAge)),
            'generation_matches' => $generation !== ''
                && hash_equals($generation, (string) ($backup['marker_generation'] ?? ''))
                && hash_equals($generation, (string) ($restore['marker_generation'] ?? '')),
            'encryption_key_matches' => $keyId !== ''
                && hash_equals($keyId, (string) ($backup['encryption_key_id'] ?? ''))
                && hash_equals($keyId, (string) ($restore['encryption_key_id'] ?? '')),
            'rpo_verified' => isset($backup['rpo_seconds'])
                && (int) $backup['rpo_seconds'] >= 0
                && (int) $backup['rpo_seconds'] <= $rpo,
            'rto_verified' => isset($restore['rto_seconds'])
                && (int) $restore['rto_seconds'] > 0
                && (int) $restore['rto_seconds'] <= $rto,
            'schema_current' => isset($restore['pending_migrations']) && (int) $restore['pending_migrations'] === 0,
            'financial_consistent' => isset($restore['financial_issues']) && (int) $restore['financial_issues'] === 0,
            'sampled_objects_present' => isset($restore['missing_objects']) && (int) $restore['missing_objects'] === 0,
            'no_orphan_records' => isset($restore['orphan_records']) && (int) $restore['orphan_records'] === 0,
        ];
        if ($recoveryMode) {
            $checks['recovery_artifact_matches_backup'] = filled($backup['artifact_sha256'] ?? null)
                && hash_equals(
                    (string) $backup['artifact_sha256'],
                    (string) ($restore['artifact_sha256'] ?? '')
                );
        }

        $ready = !in_array(false, $checks, true);

        return [
            'ready' => $ready,
            'recovery_mode' => $recoveryMode,
            'checks' => $checks,
            'provider' => $backup['provider'] ?? null,
            'last_backup_at' => $this->date($backup['snapshot_at'] ?? null),
            'last_restore_drill_at' => $this->date($restore['verified_at'] ?? null),
            'rpo_seconds' => isset($backup['rpo_seconds']) ? (int) $backup['rpo_seconds'] : null,
            'rto_seconds' => isset($restore['rto_seconds']) ? (int) $restore['rto_seconds'] : null,
            'marker' => $marker,
        ];
    }

    /** @return array<string,mixed>|null */
    public function readSigned(string $path): ?array
    {
        try {
            if ($path === '') return null;
            $disk = trim((string) config('operations.recovery_evidence_disk'));
            if ($disk !== '') {
                if (!is_array(config("filesystems.disks.{$disk}"))) return null;
                $storage = Storage::disk($disk);
                if (!$storage->exists($path)) return null;
                $contents = $storage->get($path);
            } else {
                if (!is_file($path) || !is_readable($path)) return null;
                $contents = (string) file_get_contents($path);
            }
            $payload = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) return null;
            $signature = (string) ($payload['signature'] ?? '');
            unset($payload['signature']);
            return $signature !== '' && hash_equals($this->sign($payload), $signature)
                ? $payload
                : null;
        } catch (Throwable $exception) {
            report($exception);
            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    public function sign(array $payload): string
    {
        $key = trim((string) config('operations.recovery_evidence_signing_key'));
        if (strlen($key) < 32) {
            throw new RuntimeException('RECOVERY_EVIDENCE_SIGNING_KEY must contain at least 32 characters.');
        }
        return hash_hmac('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR), $key);
    }

    /** @param array<string,mixed> $payload */
    public function write(string $path, array $payload): void
    {
        $disk = trim((string) config('operations.recovery_evidence_disk'));
        if ($path === '') throw new RuntimeException('Recovery evidence path is required.');
        $payload['signature'] = $this->sign($payload);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        if ($disk !== '') {
            if (!is_array(config("filesystems.disks.{$disk}"))) {
                throw new RuntimeException('Recovery evidence disk is not configured.');
            }
            if (!Storage::disk($disk)->put(
                $path,
                $encoded,
                StorageWriteOptions::forDisk($disk, 'private')
            )) {
                throw new RuntimeException('Could not write recovery evidence.');
            }
            return;
        }
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            throw new RuntimeException('Recovery evidence path must be absolute without a configured disk.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create recovery evidence directory.');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        try {
            if (file_put_contents(
                $temporary,
                $encoded,
                LOCK_EX
            ) === false) {
                throw new RuntimeException('Could not write recovery evidence.');
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Could not publish recovery evidence atomically.');
            }
            @chmod($path, 0600);
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function recent(mixed $value, Carbon $oldest): bool
    {
        $date = $this->date($value);
        return $date !== null
            && $date->gte($oldest)
            && $date->lte(now()->addMinutes(5));
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && trim($value) !== '' ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->canonical($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        return $value;
    }
}
