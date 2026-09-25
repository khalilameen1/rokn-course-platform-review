<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Support\DurableJobDispatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/** One durable cleanup ledger; never uploads or deletes physical bytes. */
final class StoredFileDeletionService
{
    public function __construct(private readonly StoredFileReferenceService $references)
    {
    }

    public function deleteOrQueue(string $disk, string $path): void
    {
        [$disk, $path] = $this->normalize($disk, $path);
        if (!$this->valid($disk, $path) || $this->references->isReferenced($disk, $path)) {
            return;
        }

        $row = $this->record($disk, $path, now());
        $this->dispatchAfterCommit($row);
    }

    /**
     * Admit cleanup while the caller's transaction removes the references.
     * An upfront reference check here would lose files still referenced until
     * anonymization commits. The worker rechecks immediately before deletion.
     *
     * @param list<array{disk:string,path:string}> $files
     * @return list<int>
     */
    public function queueReleasedFiles(array $files, ?int $userId = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Released file cleanup must share the reference-removal transaction.');
        }

        $ids = [];
        $seen = [];
        foreach ($files as $file) {
            [$disk, $path] = $this->normalize($file['disk'], $file['path']);
            if (!$this->valid($disk, $path)) {
                continue;
            }
            $key = $disk . ':' . hash('sha256', $path);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = $this->record($disk, $path, now(), $userId);
            $ids[] = (int) $row->id;
            $this->dispatchAfterCommit($row);
        }

        return $ids;
    }

    /** Commit cleanup before a byte write that will be referenced later. */
    public function trackPotentialOrphan(string $disk, string $path, int $delayMinutes = 60): bool
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Potential-orphan ledger must commit before storage bytes are written.');
        }
        [$disk, $path] = $this->normalize($disk, $path);
        if (!$this->valid($disk, $path)) {
            throw new \InvalidArgumentException('Tracked storage path is invalid.');
        }

        $row = $this->record($disk, $path, now()->addMinutes(max(5, $delayMinutes)));
        $this->dispatchAfterCommit($row);

        // Domain admission, not this ledger, decides whether the same target
        // can be resumed. Fresh byte writes need no remote metadata probe.
        return !$row->wasRecentlyCreated;
    }

    /** @return array{string,string} */
    private function normalize(string $disk, string $path): array
    {
        return [trim($disk), ltrim(trim($path), '/')];
    }

    private function valid(string $disk, string $path): bool
    {
        return $disk !== '' && $path !== '' && !filter_var($path, FILTER_VALIDATE_URL);
    }

    private function record(
        string $disk,
        string $path,
        CarbonInterface $availableAt,
        ?int $userId = null
    ): AccountFileDeletion {
        return AccountFileDeletion::query()->updateOrCreate(
            ['disk' => $disk, 'path_hash' => hash('sha256', $path)],
            [
                'user_id' => $userId,
                'path' => $path,
                'status' => AccountFileDeletion::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $availableAt,
                'completed_at' => null,
                'last_error' => null,
            ]
        );
    }

    private function dispatchAfterCommit(AccountFileDeletion $row): void
    {
        $dispatch = static function () use ($row): void {
            try {
                $job = new DeleteAccountFile((int) $row->id);
                if ($row->available_at?->isFuture()) {
                    $job->delay($row->available_at);
                }
                DurableJobDispatch::now($job);
            } catch (Throwable $exception) {
                // Queue failure cannot undo committed deletion intent. The
                // existing scheduler recovers this same durable row.
                Log::warning('Stored-file cleanup remains pending after dispatch failure.', [
                    'deletion_id' => $row->id,
                    'exception' => $exception::class,
                ]);
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
    }
}
