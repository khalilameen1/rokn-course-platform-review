<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Support\DurableJobDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StoredFileDeletionService
{
    public function __construct(private readonly StoredFileReferenceService $references)
    {
    }

    public function deleteOrQueue(string $disk, string $path): void
    {
        $disk = trim($disk);
        $path = ltrim(trim($path), '/');
        if ($disk === '' || $path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }
        if ($this->references->isReferenced($disk, $path)) {
            return;
        }

        // Remote deletion never belongs to an authoring HTTP request. The
        // durable row is the source of truth and the media worker performs
        // the reference check again immediately before deleting the bytes.
        $row = AccountFileDeletion::query()->updateOrCreate(
            ['disk' => $disk, 'path_hash' => hash('sha256', $path)],
            [
                'user_id' => null,
                'path' => $path,
                'status' => AccountFileDeletion::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'completed_at' => null,
                'last_error' => null,
            ]
        );
        $dispatch = static function () use ($row): void {
            try {
                DurableJobDispatch::now(new DeleteAccountFile((int) $row->id));
            } catch (Throwable $exception) {
                // The row is the durable outbox. The scheduler will dispatch
                // it once the queue connection is healthy again.
                Log::warning('Stored-file cleanup remains pending after dispatch failure.', [
                    'deletion_id' => $row->id,
                    'exception' => $exception::class,
                ]);
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
    }

    /**
     * Register a deterministic destination before writing uploaded bytes.
     * A worker death after storage succeeds but before the owning row commits
     * is then recovered by the same reference-aware deletion ledger.
     */
    public function storeTrackedUpload(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        int $orphanDelayMinutes = 60,
        ?string $operationIdentity = null
    ): string {
        $path = $this->trackedUploadDestination($file, $directory, $disk, $operationIdentity);
        $this->trackPotentialOrphan($disk, $path, $orphanDelayMinutes);
        $this->writeTrackedUpload($file, $path, $disk, $operationIdentity !== null);
        return $path;
    }

    /** Resolve the stable destination before a caller reserves ownership in its database. */
    public function trackedUploadDestination(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        ?string $operationIdentity = null
    ): string {
        $directory = trim($directory, '/');
        $disk = trim($disk);
        if ($directory === '' || $disk === '') {
            throw new \InvalidArgumentException('Tracked upload destination is invalid.');
        }
        $extension = strtolower((string) ($file->guessExtension() ?: $file->extension()));
        if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            $extension = 'bin';
        }
        // A stable operation identity turns a lost HTTP response into a resume,
        // rather than a second object. The content hash belongs in the caller's
        // identity so two files in one request can never share a destination.
        $filename = $operationIdentity === null
            ? (string) Str::uuid() . '.' . $extension
            : hash('sha256', $operationIdentity) . '.' . $extension;
        return $directory . '/' . $filename;
    }

    /** Write bytes only after the orphan ledger and any domain reservation exist. */
    public function writeTrackedUpload(
        UploadedFile $file,
        string $path,
        string $disk = 'public',
        bool $resumeExisting = true
    ): void
    {
        $path = ltrim(trim($path), '/');
        $disk = trim($disk);
        $directory = trim((string) dirname($path), './\\');
        $filename = basename($path);
        if ($path === '' || $disk === '' || $directory === '' || $filename === '') {
            throw new \InvalidArgumentException('Tracked upload destination is invalid.');
        }
        $storage = Storage::disk($disk);
        $expectedSize = (int) $file->getSize();
        if ($resumeExisting && $storage->exists($path) && (int) $storage->size($path) === $expectedSize) {
            return;
        }
        $stored = $file->storeAs($directory, $filename, $disk);
        if (!is_string($stored) || ltrim($stored, '/') !== $path) {
            throw new RuntimeException('Tracked file storage failed.');
        }
    }

    /** Persist cleanup before a byte write that will be referenced later. */
    public function trackPotentialOrphan(
        string $disk,
        string $path,
        int $delayMinutes = 60
    ): void {
        if (DB::transactionLevel() > 0) {
            throw new \LogicException('Potential-orphan ledger must commit before storage bytes are written.');
        }
        $disk = trim($disk);
        $path = ltrim(trim($path), '/');
        if ($disk === '' || $path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Tracked storage path is invalid.');
        }
        $row = AccountFileDeletion::query()->updateOrCreate(
            ['disk' => $disk, 'path_hash' => hash('sha256', $path)],
            [
                'user_id' => null,
                'path' => $path,
                'status' => AccountFileDeletion::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now()->addMinutes(max(5, $delayMinutes)),
                'completed_at' => null,
                'last_error' => null,
            ]
        );
        try {
            DurableJobDispatch::now(
                (new DeleteAccountFile((int) $row->id))->delay($row->available_at)
            );
        } catch (Throwable $exception) {
            Log::warning('Potential orphan remains in the durable cleanup ledger.', [
                'deletion_id' => $row->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
