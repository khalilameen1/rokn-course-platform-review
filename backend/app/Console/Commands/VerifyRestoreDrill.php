<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BunnyService;
use App\Services\RecoveryEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class VerifyRestoreDrill extends Command
{
    protected $signature = 'ops:verify-restore
        {--dump= : Absolute path to the exact .sql or .sql.gz artifact verified by ops:verify-backup}
        {--database= : Disposable MySQL database name, beginning rokn_restore_verify_}
        {--evidence= : Signed evidence path or configured-disk object key}
        {--media-samples=20 : Maximum rows sampled from each stored-object family}
        {--keep : Retain the disposable verification database for investigation}
        {--confirm= : Required exact acknowledgement: RESTORE_<database>}';

    protected $description = 'Restore an exact verified backup into a disposable database and prove schema, key, ledger and media integrity';

    public function handle(RecoveryEvidenceService $evidence, BunnyService $bunny): int
    {
        $startedAt = microtime(true);
        $dump = (string) $this->option('dump');
        $database = (string) $this->option('database');
        $confirmation = (string) $this->option('confirm');
        $sampleLimit = max(1, min(100, (int) $this->option('media-samples')));
        if ($dump === '' || !is_file($dump) || !is_readable($dump)) {
            return $this->failVerification('Provide a readable absolute --dump path.');
        }
        if (!preg_match('/\Arokn_restore_verify_[a-z0-9_]+\z/', $database)) {
            return $this->failVerification('--database must be a disposable name beginning rokn_restore_verify_.');
        }
        if ($confirmation !== 'RESTORE_'.$database) {
            return $this->failVerification('Confirmation must be exactly RESTORE_'.$database.'.');
        }
        if ($database === (string) config('database.connections.mysql.database')) {
            return $this->failVerification('Refusing to restore over the configured primary database.');
        }
        if (!preg_match('/\.(?:sql|sql\.gz)\z/i', $dump)) {
            return $this->failVerification('Backup must end in .sql or .sql.gz.');
        }

        $backupEvidence = $evidence->readSigned((string) config('operations.backup_evidence_path'));
        if (!is_array($backupEvidence)) {
            return $this->failVerification('A valid signed backup artifact record is required before a restore drill.');
        }
        if (!hash_equals((string) ($backupEvidence['artifact_sha256'] ?? ''), (string) hash_file('sha256', $dump))) {
            return $this->failVerification('The restore artifact does not match the signed backup record.');
        }

        $connection = (array) config('database.connections.mysql');
        $binary = trim((string) config('operations.mysql_binary', 'mysql'));
        $evidencePath = (string) ($this->option('evidence') ?: config('operations.recovery_evidence_path'));
        $originalDefault = (string) config('database.default');

        try {
            if ((bool) config('operations.disaster_recovery_mode', false)) {
                // In an actual recovery, a failed new verification must never
                // leave an older green drill for the same generation visible.
                $evidence->write($evidencePath, [
                    'version' => 1,
                    'verified_at' => now()->utc()->toIso8601String(),
                    'snapshot_at' => (string) ($backupEvidence['snapshot_at'] ?? ''),
                    'provider' => mb_substr((string) ($backupEvidence['provider'] ?? 'unknown'), 0, 100),
                    'artifact_sha256' => (string) $backupEvidence['artifact_sha256'],
                    'artifact_bytes' => (int) ($backupEvidence['artifact_bytes'] ?? 0),
                    'marker_generation' => (string) ($backupEvidence['marker_generation'] ?? ''),
                    'encryption_key_id' => (string) ($backupEvidence['encryption_key_id'] ?? ''),
                    'rto_seconds' => 0,
                    'pending_migrations' => -1,
                    'financial_issues' => -1,
                    'orphan_records' => -1,
                    'sampled_objects' => 0,
                    'missing_objects' => -1,
                ]);
            }
            $this->mysql($binary, $connection, ['-e', 'DROP DATABASE IF EXISTS `'.$database.'`; CREATE DATABASE `'.$database.'`']);
            $restore = str_ends_with(strtolower($dump), '.gz')
                ? 'gzip -dc '.escapeshellarg($dump).' | '
                : 'cat '.escapeshellarg($dump).' | ';
            $restore .= escapeshellcmd($binary).' '.$this->mysqlArguments($connection, $database);
            $this->shell($restore, $this->mysqlEnvironment($connection));

            config(['database.connections.restore_verify' => [...$connection, 'database' => $database]]);
            DB::purge('restore_verify');
            $restored = DB::connection('restore_verify');
            $schema = $restored->getSchemaBuilder();
            $tables = collect($restored->select('SHOW TABLES'))
                ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                ->sort()->values();
            if ($tables->isEmpty()) throw new RuntimeException('Restore completed without any tables.');
            if (!$schema->hasTable('migrations')) throw new RuntimeException('Restore has no migration history.');

            $marker = $this->verifyRestoredMarker($restored, $schema, $backupEvidence);

            // Migrations occasionally use the default DB facade. Point the
            // whole process at the disposable database while applying the
            // current forward schema so a drill can never mutate production.
            config(['database.default' => 'restore_verify']);
            DB::purge('restore_verify');
            if (Artisan::call('migrate', [
                '--database' => 'restore_verify',
                '--force' => true,
                '--no-interaction' => true,
            ]) !== self::SUCCESS) {
                throw new RuntimeException('Current migrations did not apply to the disposable restore.');
            }
            $restored = DB::connection('restore_verify');
            $schema = $restored->getSchemaBuilder();
            $tables = collect($restored->select('SHOW TABLES'))
                ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                ->sort()->values();
            $pendingMigrations = $this->pendingMigrationCount($restored);
            $financialIssues = $this->financialIssueCount($restored, $schema);
            $orphanRecords = $this->orphanRecordCount($restored, $schema);
            [$sampledObjects, $missingObjects] = $this->sampleStoredObjects(
                $restored,
                $schema,
                $bunny,
                $sampleLimit
            );

            $snapshotAt = Carbon::parse((string) $backupEvidence['snapshot_at'])->utc();
            $checkpointAt = Carbon::parse((string) $marker['checkpoint_at'])->utc();
            if ($checkpointAt->gt($snapshotAt->copy()->addMinutes(5))) {
                throw new RuntimeException('Restored checkpoint is newer than the recorded snapshot.');
            }

            $payload = [
                'version' => 1,
                'verified_at' => now()->utc()->toIso8601String(),
                'snapshot_at' => $snapshotAt->toIso8601String(),
                'provider' => mb_substr((string) ($backupEvidence['provider'] ?? 'unknown'), 0, 100),
                'artifact_sha256' => (string) $backupEvidence['artifact_sha256'],
                'artifact_bytes' => (int) ($backupEvidence['artifact_bytes'] ?? 0),
                'marker_generation' => (string) $marker['generation'],
                'encryption_key_id' => (string) $marker['encryption_key_id'],
                'checkpoint_at' => $checkpointAt->toIso8601String(),
                'rpo_seconds' => (int) ($backupEvidence['rpo_seconds'] ?? PHP_INT_MAX),
                'rto_seconds' => max(1, (int) ceil(microtime(true) - $startedAt)),
                'table_count' => $tables->count(),
                'migration_count' => $restored->table('migrations')->count(),
                'schema_fingerprint' => hash('sha256', $tables->implode("\n")),
                'pending_migrations' => $pendingMigrations,
                'financial_issues' => $financialIssues,
                'orphan_records' => $orphanRecords,
                'sampled_objects' => $sampledObjects,
                'missing_objects' => $missingObjects,
            ];
            $evidence->write($evidencePath, $payload);

            if ($pendingMigrations + $financialIssues + $orphanRecords + $missingObjects > 0) {
                throw new RuntimeException(sprintf(
                    'Integrity gate failed: migrations=%d financial=%d orphans=%d missing_objects=%d.',
                    $pendingMigrations,
                    $financialIssues,
                    $orphanRecords,
                    $missingObjects
                ));
            }

            $this->info('Restore drill verified. Signed evidence: '.$evidencePath);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->failVerification('Restore drill failed: '.$exception->getMessage());
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('restore_verify');
            DB::purge($originalDefault);
            if (!(bool) $this->option('keep')) {
                try {
                    $this->mysql($binary, $connection, ['-e', 'DROP DATABASE IF EXISTS `'.$database.'`']);
                } catch (Throwable) {
                    $this->warn('Could not remove disposable restore database; remove it manually.');
                }
            }
        }
    }

    /** @return array{generation:string,encryption_key_id:string,checkpoint_at:string} */
    private function verifyRestoredMarker(
        ConnectionInterface $connection,
        Builder $schema,
        array $backupEvidence
    ): array
    {
        if (!$schema->hasTable('recovery_markers')) {
            throw new RuntimeException('Backup predates the recovery marker contract.');
        }
        $marker = $connection->table('recovery_markers')->where('scope', 'production')->first();
        if (!$marker) throw new RuntimeException('Backup has no production recovery marker.');

        $generation = (string) $marker->generation;
        $keyId = (string) $marker->encryption_key_id;
        if ($generation === '' || !hash_equals($generation, (string) ($backupEvidence['marker_generation'] ?? ''))) {
            throw new RuntimeException('Backup marker generation does not match its signed artifact record.');
        }
        if ($keyId === '' || !hash_equals($keyId, trim((string) config('operations.recovery_encryption_key_id')))) {
            throw new RuntimeException('The restored data requires a different encryption-key identity.');
        }
        $probe = Crypt::decryptString((string) $marker->encrypted_probe);
        if (!hash_equals((string) $marker->probe_hash, hash('sha256', $probe))) {
            throw new RuntimeException('APP_KEY cannot decrypt the restored recovery marker.');
        }

        return [
            'generation' => $generation,
            'encryption_key_id' => $keyId,
            'checkpoint_at' => (string) $marker->checkpoint_at,
        ];
    }

    private function pendingMigrationCount(ConnectionInterface $connection): int
    {
        $ran = array_fill_keys($connection->table('migrations')->pluck('migration')->all(), true);
        $files = glob(database_path('migrations/*.php')) ?: [];

        return count(array_filter($files, static fn (string $file): bool => !isset($ran[pathinfo($file, PATHINFO_FILENAME)])));
    }

    private function financialIssueCount(ConnectionInterface $connection, Builder $schema): int
    {
        $issues = 0;
        if ($schema->hasTable('users') && $schema->hasColumns('users', ['wallet_coins', 'wallet_purchased_coins', 'wallet_reward_coins'])) {
            $issues += $connection->table('users')
                ->whereRaw('wallet_coins <> wallet_purchased_coins + wallet_reward_coins')
                ->count();
        }
        if ($schema->hasTable('wallet_transactions') && $schema->hasTable('users')) {
            $latest = $connection->table('wallet_transactions')
                ->selectRaw('user_id, MAX(id) AS last_id')
                ->groupBy('user_id');
            $query = $connection->table('users as u')
                ->joinSub($latest, 'latest', 'latest.user_id', '=', 'u.id')
                ->join('wallet_transactions as wt', 'wt.id', '=', 'latest.last_id');
            $query->where(function ($mismatch) use ($schema): void {
                $mismatch->whereColumn('u.wallet_coins', '<>', 'wt.balance_after');
                if ($schema->hasColumns('wallet_transactions', ['paid_balance_after', 'reward_balance_after'])
                    && $schema->hasColumns('users', ['wallet_purchased_coins', 'wallet_reward_coins'])) {
                    $mismatch->orWhereColumn('u.wallet_purchased_coins', '<>', 'wt.paid_balance_after')
                        ->orWhereColumn('u.wallet_reward_coins', '<>', 'wt.reward_balance_after');
                }
            });
            $issues += $query->count();
        }
        if ($schema->hasTable('orders')) {
            $issues += $connection->table('orders')
                ->whereNull('deleted_at')
                ->where(function ($query): void {
                    $query->where(function ($bothNull): void {
                        $bothNull->whereNull('course_id')->whereNull('package_id');
                    })->orWhere(function ($bothSet): void {
                        $bothSet->whereNotNull('course_id')->whereNotNull('package_id');
                    });
                })->count();
            if ($schema->hasTable('bills')) {
                $issues += $connection->table('orders as o')
                    ->leftJoin('bills as b', function ($join): void {
                        $join->on('b.order_id', '=', 'o.id')->whereNull('b.deleted_at');
                    })
                    ->whereNull('o.deleted_at')
                    ->where('o.status', 'approved')
                    ->where('o.financial_status', 'settled')
                    ->where(function ($query): void {
                        $query->whereNull('b.id')->orWhere('b.payment_status', '<>', 'paid');
                    })->count();
            }
        }
        if ($schema->hasTable('course_enrollments') && $schema->hasTable('orders')) {
            $issues += $connection->table('course_enrollments as ce')
                ->leftJoin('orders as o', 'o.id', '=', 'ce.order_id')
                ->where('ce.is_active', true)
                ->whereNotNull('ce.order_id')
                ->where(function ($query): void {
                    $query->whereNull('o.id')
                        ->orWhere('o.status', '<>', 'approved')
                        ->orWhere('o.financial_status', '<>', 'settled');
                })->count();
        }
        if ($schema->hasTable('store_purchases') && $schema->hasTable('orders')) {
            $issues += $connection->table('store_purchases as sp')
                ->leftJoin('orders as o', 'o.id', '=', 'sp.order_id')
                ->where('sp.status', 'verified')
                ->where(function ($query): void {
                    $query->whereNull('o.id')
                        ->orWhere('o.status', '<>', 'approved')
                        ->orWhere('o.financial_status', '<>', 'settled');
                })->count();
        }

        return $issues;
    }

    private function orphanRecordCount(ConnectionInterface $connection, Builder $schema): int
    {
        $relations = [
            ['course_enrollments', 'user_id', 'users'],
            ['course_enrollments', 'course_id', 'courses'],
            ['wallet_transactions', 'user_id', 'users'],
            ['bills', 'order_id', 'orders'],
            ['certificates', 'user_id', 'users'],
            ['certificates', 'course_id', 'courses'],
            ['project_submissions', 'user_id', 'users'],
            ['project_submissions', 'project_id', 'projects'],
        ];
        $count = 0;
        foreach ($relations as [$child, $column, $parent]) {
            if (!$schema->hasTable($child) || !$schema->hasTable($parent) || !$schema->hasColumn($child, $column)) continue;
            $count += $connection->table("{$child} as child")
                ->leftJoin("{$parent} as parent", 'parent.id', '=', "child.{$column}")
                ->whereNotNull("child.{$column}")
                ->whereNull('parent.id')
                ->count();
        }

        return $count;
    }

    /** @return array{0:int,1:int} */
    private function sampleStoredObjects(
        ConnectionInterface $connection,
        Builder $schema,
        BunnyService $bunny,
        int $limit
    ): array
    {
        $sampled = 0;
        $missing = 0;
        $families = [
            ['course_pdfs', 'file_path', 'storage_disk', null, 'local'],
            ['feedback_attachments', 'path', 'disk', null, 'feedback'],
            ['certificates', 'image_path', null, 'status', (string) config('certificate.disk', 'public')],
            ['photos', 'path', null, null, 'public'],
        ];
        foreach ($families as [$table, $pathColumn, $diskColumn, $statusColumn, $defaultDisk]) {
            if (!$schema->hasTable($table) || !$schema->hasColumn($table, $pathColumn)) continue;
            $query = $connection->table($table)->whereNotNull($pathColumn)->where($pathColumn, '<>', '');
            if ($statusColumn && $schema->hasColumn($table, $statusColumn)) $query->where($statusColumn, 'active');
            if ($schema->hasColumn($table, 'deleted_at')) $query->whereNull('deleted_at');
            foreach ($query->orderBy('id')->limit($limit)->get() as $row) {
                $path = trim((string) $row->{$pathColumn});
                if ($path === 'pending') continue;
                $disk = $diskColumn ? trim((string) ($row->{$diskColumn} ?? '')) : '';
                $disk = $disk !== '' ? $disk : $defaultDisk;
                $sampled++;
                try {
                    if (!is_array(config("filesystems.disks.{$disk}")) || !Storage::disk($disk)->exists($path)) $missing++;
                } catch (Throwable) {
                    $missing++;
                }
            }
        }

        if ($schema->hasTable('project_submissions')) {
            foreach ($connection->table('project_submissions')
                ->whereNotNull('submission_file')->where('submission_file', '<>', '')
                ->orderBy('id')->limit($limit)->get() as $row) {
                $metadata = is_string($row->submission_metadata ?? null)
                    ? json_decode((string) $row->submission_metadata, true)
                    : (array) ($row->submission_metadata ?? []);
                $disk = trim((string) data_get($metadata, 'storage_disk', 'local')) ?: 'local';
                $sampled++;
                try {
                    if (!is_array(config("filesystems.disks.{$disk}")) || !Storage::disk($disk)->exists((string) $row->submission_file)) $missing++;
                } catch (Throwable) {
                    $missing++;
                }
            }
        }

        foreach ([
            ['lessons', 'thumbnail_path'],
            ['portfolio_media', 'file_path'],
            ['portfolio_media', 'thumbnail_path'],
        ] as [$table, $column]) {
            if (!$schema->hasTable($table) || !$schema->hasColumn($table, $column)) continue;
            foreach ($connection->table($table)->whereNotNull($column)->where($column, '<>', '')
                ->orderBy('id')->limit($limit)->pluck($column) as $path) {
                $sampled++;
                if (!$this->bunnyObjectExists($bunny, (string) $path)) $missing++;
            }
        }

        if ($schema->hasTable('lessons') && $schema->hasColumn('lessons', 'bunny_video_id')) {
            foreach ($connection->table('lessons')->whereNotNull('bunny_video_id')
                ->where('bunny_video_id', '<>', '')->orderBy('id')->limit($limit)->pluck('bunny_video_id') as $guid) {
                $sampled++;
                if ($bunny->getRemoteVideoDetails((string) $guid) === null) $missing++;
            }
        }

        return [$sampled, $missing];
    }

    private function bunnyObjectExists(BunnyService $bunny, string $path): bool
    {
        $url = $bunny->generateBunnySignedUrl($path, 300);
        if (!is_string($url) || $url === '') return false;

        try {
            return Http::connectTimeout(5)
                ->timeout(10)
                ->withHeaders(['Range' => 'bytes=0-0'])
                ->get($url)
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function mysql(string $binary, array $connection, array $arguments): void
    {
        $this->shell(escapeshellcmd($binary).' '.$this->mysqlArguments($connection).' '.implode(' ', array_map('escapeshellarg', $arguments)), $this->mysqlEnvironment($connection));
    }

    private function mysqlArguments(array $connection, ?string $database = null): string
    {
        return '--protocol=TCP --host='.escapeshellarg((string) $connection['host']).' --port='.escapeshellarg((string) $connection['port']).' --user='.escapeshellarg((string) $connection['username']).($database ? ' '.escapeshellarg($database) : '');
    }

    private function mysqlEnvironment(array $connection): array
    {
        return ['MYSQL_PWD' => (string) ($connection['password'] ?? '')];
    }

    private function shell(string $command, array $environment): void
    {
        $process = proc_open(['sh', '-lc', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment + $_ENV);
        if (!is_resource($process)) throw new RuntimeException('Could not execute MySQL client.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException(trim($stderr ?: $stdout ?: 'MySQL client failed.'));
    }

    private function failVerification(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
