<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortfolioOperationException;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PortfolioMediaMutationService
{
    public function __construct(
        private BunnyService $bunny,
        private PortfolioMediaReadinessService $readiness
    ) {
    }

    public function deleteItem(User $user, int $itemId): bool
    {
        return DB::transaction(function () use ($user, $itemId): bool {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser) return false;
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($itemId);
            if (!$item) return false;

            $item->forceFill(['is_public' => false, 'deletion_started_at' => now()])->save();
            foreach ($item->mediaFiles()->lockForUpdate()->get() as $media) {
                $media->forceFill([
                    'deletion_lease_id' => (string) Str::uuid(),
                    'deletion_started_at' => now(),
                ])->save();
                $this->queueCleanup($media, 'portfolio_item_deleted');
                $media->delete();
            }
            $item->delete();

            return true;
        }, 3);
    }

    public function deleteMedia(User $user, int $itemId, int $mediaId): bool
    {
        return DB::transaction(function () use ($user, $itemId, $mediaId): bool {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser) return false;
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($itemId);
            if (!$item || $item->deletion_started_at) return false;
            $media = $item->mediaFiles()->lockForUpdate()->find($mediaId);
            if (!$media) return false;

            $media->forceFill([
                'deletion_lease_id' => (string) Str::uuid(),
                'deletion_started_at' => now(),
            ])->save();
            $this->queueCleanup($media, 'portfolio_media_deleted');
            $media->delete();
            if (!$item->mediaFiles()->exists()) {
                $item->forceFill(['is_public' => false])->save();
            }

            return true;
        }, 3);
    }

    public function finalize(User $user, int $itemId): PortfolioItem
    {
        $item = $user->portfolioItems()
            ->available()
            ->with(['mediaFiles' => fn ($media) => $media->orderBy('id')])
            ->find($itemId);
        if (!$item) {
            throw (new ModelNotFoundException())->setModel(PortfolioItem::class, [$itemId]);
        }
        if ($item->mediaFiles->isEmpty()) {
            throw new PortfolioOperationException(PortfolioOperationException::INCOMPLETE_ITEM);
        }
        foreach ($item->mediaFiles as $media) {
            if ($this->readiness->presentation($media, true)['status'] !== 'ready') {
                throw new PortfolioOperationException(PortfolioOperationException::MEDIA_NOT_READY);
            }
        }
        $mediaFingerprint = $this->mediaFingerprint($item->mediaFiles);

        return DB::transaction(function () use ($user, $itemId, $mediaFingerprint): PortfolioItem {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser) {
                throw (new ModelNotFoundException())->setModel(User::class, [$user->id]);
            }
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($itemId);
            if (!$item || $item->deletion_started_at) {
                throw (new ModelNotFoundException())->setModel(PortfolioItem::class, [$itemId]);
            }
            $media = $item->mediaFiles()->orderBy('id')->lockForUpdate()->get();
            $uploadedCount = $media->count();
            if ($uploadedCount < 1) {
                throw new PortfolioOperationException(PortfolioOperationException::INCOMPLETE_ITEM);
            }
            if (!hash_equals($mediaFingerprint, $this->mediaFingerprint($media))) {
                throw new PortfolioOperationException(PortfolioOperationException::MEDIA_NOT_READY);
            }
            $item->forceFill(['expected_media_count' => $uploadedCount, 'is_public' => true])->save();

            return $item->fresh(['mediaFiles', 'course'])->loadCount('mediaFiles');
        }, 3);
    }

    private function mediaFingerprint(iterable $media): string
    {
        $rows = [];
        foreach ($media as $file) {
            $rows[] = [
                (int) $file->id,
                (string) $file->file_type,
                (string) $file->file_path,
                (string) $file->content_sha256,
            ];
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function queueCleanup($media, string $reason): void
    {
        if ($media->file_type === 'video' && $media->file_path) {
            if (!$this->bunny->queueVideoCleanup($media->file_path, null, $reason, 1, false)) {
                throw new \RuntimeException('Unable to persist portfolio video cleanup.');
            }
            return;
        }
        if ($media->file_type === 'image' && $media->file_path
            && !$this->bunny->queueStorageCleanup($media->file_path, $reason)) {
            throw new \RuntimeException('Unable to persist portfolio image cleanup.');
        }
    }
}
