<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Services\BunnyService;
use App\Services\StoredFileReferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class CleanupDeletedAccountPortfolioMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    public int $timeout = 60;

    // Covers the complete retry/backoff horizon. A short uniqueness TTL let
    // the periodic recovery command enqueue a second remote-deletion chain
    // while the first one was still backing off.
    public int $uniqueFor = 21600;

    public bool $failOnTimeout = true;

    /** @var array<int> */
    public array $backoff = [30, 60, 120, 300, 600, 1200, 1800, 3600];

    public function __construct(private int $userId)
    {
        $this->onQueue((string) config('queue.channels.media', 'media'));
    }

    public function uniqueId(): string
    {
        return 'deleted-account-portfolio:'.$this->userId;
    }

    public function handle(BunnyService $bunny, StoredFileReferenceService $references): void
    {
        $itemIds = PortfolioItem::query()
            ->where('user_id', $this->userId)
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            return;
        }

        PortfolioMedia::query()
            ->whereIn('portfolio_item_id', $itemIds)
            ->orderBy('id')
            ->get()
            ->each(function (PortfolioMedia $media) use ($bunny, $references): void {
                if (! $this->deleteRemoteMedia($bunny, $references, $media)) {
                    throw new RuntimeException('Bunny portfolio media cleanup is temporarily unavailable.');
                }

                // The remote delete completed first. Only now may its database
                // reference disappear. A changed row is retained for the next pass.
                DB::transaction(function () use ($media): void {
                    $current = PortfolioMedia::query()->lockForUpdate()->find($media->id);
                    if (! $current) {
                        return;
                    }

                    if (
                        $current->file_path !== $media->file_path
                        || $current->file_type !== $media->file_type
                        || $current->thumbnail_path !== $media->thumbnail_path
                    ) {
                        throw new RuntimeException('Portfolio media changed during cleanup.');
                    }

                    $current->delete();
                });
            });

        // Items contain no remote references at this point. Empty records can
        // now be removed without losing the ability to retry a Bunny deletion.
        $remainingMedia = PortfolioMedia::query()
            ->whereIn('portfolio_item_id', $itemIds)
            ->exists();
        if (! $remainingMedia) {
            PortfolioItem::query()
                ->where('user_id', $this->userId)
                ->whereIn('id', $itemIds)
                ->delete();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Deleted account portfolio cleanup exhausted its automatic retries.', [
            'deleted_user_id' => $this->userId,
            'exception' => get_class($exception),
        ]);
    }

    private function deleteRemoteMedia(
        BunnyService $bunny,
        StoredFileReferenceService $references,
        PortfolioMedia $media
    ): bool {
        $path = trim((string) $media->file_path);
        if ($path === '') {
            return true;
        }

        if ($media->file_type === 'video') {
            return $references->isBunnyStreamVideoReferencedElsewhere($path, (int) $media->id)
                || $bunny->deleteVideo($path);
        }

        if ($media->file_type !== 'image') {
            return false;
        }

        if (
            ! $references->isBunnyStoragePathReferencedElsewhere($path, (int) $media->id)
            && ! $bunny->deleteFileFromStorage($path)
        ) {
            return false;
        }

        $thumbnail = trim((string) $media->thumbnail_path);

        return $thumbnail === ''
            || $thumbnail === $path
            || $references->isBunnyStoragePathReferencedElsewhere($thumbnail, (int) $media->id)
            || $bunny->deleteFileFromStorage($thumbnail);
    }
}
