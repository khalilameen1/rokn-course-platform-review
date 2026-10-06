<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PublicDiskUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use RuntimeException;

/** Keep the authored original; encode a separate bounded catalogue rendition. */
final readonly class CourseCoverStorageService
{
    private const MAX_BYTES = 6 * 1024 * 1024;
    private const MAX_PIXELS = 25_000_000;
    private const MAX_EDGE = 1280;

    public function __construct(
        private StoredFileUploadService $uploads,
        private StoredFileDeletionService $cleanup
    ) {
    }

    /** @return array{path:string,preview_path:string} */
    public function stage(UploadedFile $upload): array
    {
        $bytes = file_get_contents($upload->getRealPath(), false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($bytes)) throw new RuntimeException('Course cover could not be read.');
        // Decode/encode before any storage write. A rejected image leaves no objects.
        $preview = $this->encodePreview($bytes);
        $path = $this->uploads->storeTrackedUpload($upload, 'courses');
        try {
            return [
                'path' => $path,
                'preview_path' => $this->storePreview($preview),
            ];
        } catch (\Throwable $exception) {
            $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    public function stagePreviewForStoredPath(string $storedPath): string
    {
        $path = PublicDiskUrl::pathFrom($storedPath);
        if (!$path) throw new RuntimeException('Course cover is not owned by the public disk.');
        $stream = Storage::disk('public')->readStream($path);
        if (!is_resource($stream)) throw new RuntimeException('Course cover could not be read.');
        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($bytes)) throw new RuntimeException('Course cover could not be read.');

        return $this->storePreview($this->encodePreview($bytes));
    }

    /** @param array{path:string,preview_path:string}|null $cover */
    public function discard(?array $cover): void
    {
        foreach ($cover ?? [] as $path) $this->cleanup->deleteOrQueue('public', $path);
    }

    private function storePreview(string $bytes): string
    {
        return $this->uploads->storeTrackedBytes($bytes, 'courses/previews', 'webp');
    }

    private function encodePreview(string $bytes): string
    {
        $dimensions = @getimagesizefromstring($bytes);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || !$dimensions
            || !in_array($dimensions['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Course cover exceeds the supported image budget.');
        }
        // Intervention's EXIF reader needs the original file, not a newly
        // encoded GD stream. tmpfile owns its temporary file until fclose.
        $stream = tmpfile();
        if (!is_resource($stream)) throw new RuntimeException('Course cover temporary storage failed.');
        $image = null;
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Course cover temporary storage failed.');
            }
            $image = Image::make(stream_get_meta_data($stream)['uri']);
            if (function_exists('exif_read_data')) $image->orientate();
            $image->resize(self::MAX_EDGE, self::MAX_EDGE, static function ($constraint): void {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            $encoded = (string) $image->encode('webp', 82);
            if ($encoded === '') throw new RuntimeException('Course cover encoding failed.');

            return $encoded;
        } finally {
            $image?->destroy();
            fclose($stream);
        }
    }
}
