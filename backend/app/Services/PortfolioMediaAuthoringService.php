<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortfolioOperationException;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\PortfolioVideoUpload;
use App\Models\User;
use App\Support\DownloadFilename;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class PortfolioMediaAuthoringService
{
    public const VIDEO_MAX_BYTES = PortfolioVideoUploadService::MAX_BYTES;
    public const VIDEO_MIMES = PortfolioVideoUploadService::MIMES;

    public function __construct(
        private PortfolioVideoUploadService $videoUploads,
        private BunnyService $bunny
    ) {
    }

    public function ownedItem(User $user, int $itemId): PortfolioItem
    {
        return $user->portfolioItems()->available()->findOrFail($itemId);
    }

    /** @return array<string,mixed> */
    public function issueVideo(User $user, int $itemId, array $input): array
    {
        $item = $this->ownedItem($user, $itemId);

        try {
            return $this->videoUploads->issue(
                $user,
                $item,
                (string) $input['idempotency_key'],
                (int) $input['size'],
                (string) $input['mime'],
                (string) $input['original_name'],
                (string) $input['sha256']
            );
        } catch (PortfolioOperationException|ValidationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            report($exception);
            throw new PortfolioOperationException(PortfolioOperationException::UPLOAD_FAILED);
        }
    }

    /** @return array<string,mixed> */
    public function renewVideo(User $user, int $itemId, string $claim): array
    {
        $this->ownedItem($user, $itemId);

        try {
            return $this->videoUploads->renew($user, $itemId, $claim);
        } catch (PortfolioOperationException|ValidationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            report($exception);
            throw new PortfolioOperationException(PortfolioOperationException::UPLOAD_FAILED);
        }
    }

    public function claimVideo(User $user, int $itemId, string $claim, ?string $caption): PortfolioMedia
    {
        $this->ownedItem($user, $itemId);

        return $this->videoUploads->attach($user, $itemId, $claim, $caption);
    }

    /** @return array{media:PortfolioMedia,replayed:bool} */
    public function appendImage(
        User $user,
        int $itemId,
        UploadedFile $file,
        string $clientRequestId,
        ?string $caption
    ): array {
        if (!Str::isUuid($clientRequestId)) {
            throw ValidationException::withMessages(['client_request_id' => ['معرّف الرفع غير صالح']]);
        }

        $item = $this->ownedItem($user, $itemId);
        $this->assertImage($file, 'file');
        $fingerprint = $this->fingerprintImage($file);
        $caption = $caption !== null ? trim($caption) : null;

        return Cache::lock(
            'portfolio-media-upload:' . $user->id . ':' . strtolower($clientRequestId),
            3900
        )->block(10, function () use (
            $user,
            $item,
            $file,
            $clientRequestId,
            $fingerprint,
            $caption
        ): array {
            $item = $this->ownedItem($user, (int) $item->id);
            $existing = $item->mediaFiles()
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing) {
                $this->assertReplayMatches($existing, $fingerprint, $caption);

                return ['media' => $existing, 'replayed' => true];
            }

            if ($item->mediaFiles()->where('content_sha256', $fingerprint['sha256'])->exists()) {
                throw ValidationException::withMessages(['file' => ['هذا الملف مضاف بالفعل']]);
            }
            $this->assertCapacityAvailable($item, $fingerprint['sha256']);

            $path = $this->bunny->uploadFileToStorage(
                $file,
                'portfolio',
                $clientRequestId,
                'portfolio_upload_unpublished'
            );
            if (!$path) {
                throw new PortfolioOperationException(PortfolioOperationException::UPLOAD_FAILED);
            }

            $replayed = false;
            $pathConsumed = false;
            try {
                $media = DB::transaction(function () use (
                    $user,
                    $item,
                    $file,
                    $clientRequestId,
                    $fingerprint,
                    $caption,
                    $path,
                    &$replayed,
                    &$pathConsumed
                ): PortfolioMedia {
                    User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $lockedItem = PortfolioItem::query()
                        ->whereKey($item->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if ((int) $lockedItem->user_id !== (int) $user->id) {
                        throw (new ModelNotFoundException())
                            ->setModel(PortfolioItem::class, [$item->id]);
                    }
                    if ($lockedItem->deletion_started_at !== null) {
                        throw new PortfolioOperationException(PortfolioOperationException::ITEM_UNAVAILABLE);
                    }

                    $existing = $lockedItem->mediaFiles()
                        ->where('client_request_id', $clientRequestId)
                        ->first();
                    if ($existing) {
                        $this->assertReplayMatches($existing, $fingerprint, $caption);
                        $replayed = true;
                        if (hash_equals((string) $existing->file_path, (string) $path)) {
                            $this->consumeImage($path);
                            $pathConsumed = true;
                        }

                        return $existing;
                    }

                    if ($lockedItem->mediaFiles()->where('content_sha256', $fingerprint['sha256'])->exists()) {
                        throw ValidationException::withMessages(['file' => ['هذا الملف مضاف بالفعل']]);
                    }
                    $this->assertCapacityAvailable($lockedItem, $fingerprint['sha256']);
                    $media = $lockedItem->mediaFiles()->create([
                        'client_request_id' => $clientRequestId,
                        'file_path' => $path,
                        'file_type' => 'image',
                        'content_sha256' => $fingerprint['sha256'],
                        'mime_type' => $fingerprint['mime'],
                        'size_bytes' => $fingerprint['size'],
                        'original_name' => $this->originalName($file->getClientOriginalName()),
                        'sort_order' => ((int) $lockedItem->mediaFiles()->max('sort_order')) + 1,
                        'caption' => $caption,
                    ]);
                    $this->consumeImage($path);
                    $pathConsumed = true;
                    $lockedItem->forceFill(['is_public' => false])->save();

                    return $media;
                }, 3);
            } catch (Throwable $exception) {
                if (!$pathConsumed) {
                    $this->cleanupImage($path);
                }
                throw $exception;
            }

            if ($replayed && !$pathConsumed) {
                $this->cleanupImage($path);
            }

            return ['media' => $media, 'replayed' => $replayed];
        });
    }

    private function assertImage(UploadedFile $file, string $field): void
    {
        $mimeType = (string) $file->getMimeType();
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                $field => ['The uploaded file does not match its selected media type.'],
            ]);
        }

        $dimensions = @getimagesize($file->getRealPath());
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($dimensions === false
            || $width < 2
            || $height < 2
            || ($height > 0 && $width > intdiv(40000000, $height))) {
            throw ValidationException::withMessages([
                $field => ['The selected image is damaged or has unsafe dimensions.'],
            ]);
        }
    }

    /** @return array{sha256:string,size:int,mime:string,file_type:string} */
    private function fingerprintImage(UploadedFile $file): array
    {
        $path = (string) $file->getRealPath();
        $sha256 = $path !== '' ? hash_file('sha256', $path) : false;
        $size = (int) $file->getSize();
        if (!$sha256 || $size <= 0) {
            throw ValidationException::withMessages([
                'file' => ['The selected media file is empty or could not be read completely.'],
            ]);
        }

        return [
            'sha256' => $sha256,
            'size' => $size,
            'mime' => strtolower((string) $file->getMimeType()),
            'file_type' => 'image',
        ];
    }

    private function originalName(?string $name): string
    {
        return DownloadFilename::safe($name, 'portfolio-file');
    }

    private function cleanupImage(string $path): void
    {
        if ($path !== '') $this->bunny->queueStorageCleanup($path, 'portfolio_rollback', 5);
    }

    private function consumeImage(string $path): void
    {
        if ($path === '') return;
        $this->bunny->consumeStorageCleanupCandidate($path);
    }

    /** @param array{sha256:string,size:int,mime:string,file_type:string} $fingerprint */
    private function assertReplayMatches(
        PortfolioMedia $media,
        array $fingerprint,
        ?string $caption
    ): void {
        if (
            !hash_equals((string) $media->content_sha256, $fingerprint['sha256'])
            || (int) $media->size_bytes !== $fingerprint['size']
            || (string) $media->mime_type !== $fingerprint['mime']
            || (string) $media->file_type !== 'image'
            || trim((string) $media->caption) !== trim((string) $caption)
        ) {
            throw new PortfolioOperationException(PortfolioOperationException::IDENTITY_CONFLICT);
        }
    }

    private function assertCapacityAvailable(PortfolioItem $item, string $sha256): void
    {
        $reservedVideos = PortfolioVideoUpload::query()
            ->where('portfolio_item_id', $item->id)
            ->where('content_sha256', $sha256)
            ->where('expires_at', '>', now())
            ->whereIn('status', ['allocating', 'pending'])
            ->exists();
        if ($reservedVideos) {
            throw ValidationException::withMessages(['file' => ['هذا الملف قيد الرفع بالفعل']]);
        }

        $reservedCount = PortfolioVideoUpload::query()
            ->where('portfolio_item_id', $item->id)
            ->where('expires_at', '>', now())
            ->whereIn('status', ['allocating', 'pending'])
            ->count();
        if ($item->mediaFiles()->count() + $reservedCount >= $this->videoUploads->mediaCapacity()) {
            throw ValidationException::withMessages(['file' => ['اكتمل عدد ملفات هذا المشروع']]);
        }
    }
}
