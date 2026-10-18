<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BunnyStorageCleanupCandidate;
use App\Services\BunnyService;
use App\Services\StoredFileReferenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CleanupBunnyStorage extends Command
{
    protected $signature = 'bunny:cleanup-storage {--limit=100}';

    protected $description = 'Retry safe deletion of unreferenced Bunny Storage course objects';

    public function handle(BunnyService $bunny, StoredFileReferenceService $references): int
    {
        $supportsQuarantine = Schema::hasColumn('bunny_storage_cleanup_candidates', 'quarantined_at');
        $ids = BunnyStorageCleanupCandidate::query()
            ->whereNull('completed_at')
            ->when($supportsQuarantine, fn ($query) => $query->whereNull('quarantined_at'))
            ->where('eligible_after', '<=', now())
            ->orderBy('eligible_after')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->pluck('id');

        foreach ($ids as $id) {
            $candidate = DB::transaction(function () use ($id): ?BunnyStorageCleanupCandidate {
                $row = BunnyStorageCleanupCandidate::query()->lockForUpdate()->find($id);
                if (! $row || $row->completed_at || $row->eligible_after->isFuture()) {
                    return null;
                }
                $row->forceFill([
                    'attempts' => (int) $row->attempts + 1,
                    'last_attempt_at' => now(),
                    'eligible_after' => now()->addMinutes(15),
                ])->save();

                return $row->fresh();
            });
            if (! $candidate) {
                continue;
            }

            $path = (string) $candidate->path;
            if ($references->isBunnyStoragePathReferenced($path)) {
                $candidate->forceFill([
                    // A path reused after it was queued belongs to the new DB
                    // reference. Close this generation instead of retrying it
                    // forever; a later replacement will reopen the candidate.
                    'completed_at' => now(),
                    'path' => null,
                    'last_error' => 'Cleanup skipped because the path is referenced.',
                ])->save();

                continue;
            }
            try {
                if (! $bunny->deleteFileFromStorage($path)) {
                    throw new \RuntimeException('Bunny Storage did not confirm deletion.');
                }
                $candidate->forceFill([
                    'completed_at' => now(),
                    'path' => null,
                    'last_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                $quarantined = (int) $candidate->attempts >= max(
                    3,
                    (int) config('operations.bunny_cleanup_max_attempts', 8)
                );
                $failure = [
                    'eligible_after' => now()->addMinutes(min(1440, 2 ** min(10, $candidate->attempts))),
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ];
                if ($supportsQuarantine) {
                    $failure['quarantined_at'] = $quarantined ? now() : null;
                }
                $candidate->forceFill($failure)->save();
            }
        }

        return self::SUCCESS;
    }
}
