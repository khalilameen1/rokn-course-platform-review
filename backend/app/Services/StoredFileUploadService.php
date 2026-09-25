<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UploadBudget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

/** Immutable upload attempts and bounded byte writes; cleanup belongs to its outbox owner. */
final class StoredFileUploadService
{
    public function __construct(private readonly StoredFileDeletionService $cleanup)
    {
    }

    /**
     * Stage a fresh physical attempt before the owning domain row commits.
     * A worker death after storage succeeds but before the owning row commits
     * is then recovered by the same reference-aware deletion ledger.
     */
    public function storeTrackedUpload(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        int $orphanDelayMinutes = 60,
        ?string $operationIdentity = null,
        ?UploadBudget $budget = null
    ): string {
        $this->assertUploadBudget($budget, $directory);
        $path = $this->trackedUploadDestination($file, $directory, $disk, $operationIdentity);
        if ($operationIdentity !== null) {
            // Preserve the logical filename for domain replay checks, but
            // never reuse an orphan path a prior cleanup may already own.
            $path = dirname($path) . '/' . Str::uuid() . '/' . basename($path);
        }
        $this->cleanup->trackPotentialOrphan(
            $disk,
            $path,
            $orphanDelayMinutes
        );
        $this->writeTrackedUpload(
            $file,
            $path,
            $disk,
            false,
            $budget
        );
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
        bool $resumeExisting = true,
        ?UploadBudget $budget = null
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
        if ($resumeExisting && $budget === null) {
            try {
                // fileSize is one metadata request. exists()+size() issues two
                // sequential remote calls on S3 before a retry can resume.
                if ((int) $storage->size($path) === $expectedSize) {
                    return;
                }
            } catch (UnableToRetrieveMetadata) {
                // The earlier attempt did not finish writing this object.
                // Reusing the deterministic path makes the following write safe.
            }
        }
        $options = ['disk' => $disk];
        if ($budget !== null) {
            $remainingSeconds = $this->assertUploadBudget($budget, $path);
            $operationTimeout = max(1.0, min(6.0, $remainingSeconds - 2.0));
            $options += [
                // Project files are capped at 25 MB. Keeping them below this
                // threshold makes the bounded upload one HTTP operation rather
                // than an unbounded sequence of multipart requests.
                'mup_threshold' => 64 * 1024 * 1024,
                'before_upload' => static function ($command) use ($operationTimeout): void {
                    $command['@retries'] = 0;
                    $http = is_array($command['@http'] ?? null) ? $command['@http'] : [];
                    $command['@http'] = array_replace($http, [
                        'connect_timeout' => min(2.0, $operationTimeout),
                        'timeout' => $operationTimeout,
                    ]);
                },
            ];
        }
        $stored = $file->storeAs($directory, $filename, $options);
        if (!is_string($stored) || ltrim($stored, '/') !== $path) {
            throw new RuntimeException('Tracked file storage failed.');
        }
    }

    private function assertUploadBudget(?UploadBudget $budget, string $path): float
    {
        if ($budget === null) {
            return INF;
        }
        $remaining = $budget->remainingSeconds();
        if ($remaining <= 2.0) {
            throw UnableToWriteFile::atLocation(
                trim($path, '/'),
                'The shared upload request budget was exhausted.'
            );
        }

        return $remaining;
    }
}
