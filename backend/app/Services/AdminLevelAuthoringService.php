<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Level;
use App\Support\LevelEditorVersion;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns badge-level data, image references and their atomic retirement. */
final readonly class AdminLevelAuthoringService
{
    public function __construct(
        private StoredFileUploadService $uploads,
        private StoredFileDeletionService $cleanup
    ) {
    }

    /** @param array<string,mixed> $data @param Closure(Level):void $complete */
    public function create(array $data, string $requestId, ?UploadedFile $image, Closure $complete): Level
    {
        $path = $this->stageImage($image, $image === null ? null
            : 'admin-level|'.strtolower($requestId).'|'.hash_file('sha256', $image->getRealPath()));
        try {
            return DB::transaction(function () use ($data, $requestId, $path, $complete): Level {
                $level = Level::query()->where('authoring_request_id', $requestId)->lockForUpdate()->first();
                $level ??= Level::query()->create($this->fields($data) + ['authoring_request_id' => $requestId]);
                if ($path !== null) {
                    // HTTP create receipts bind the request payload. Resuming a
                    // committed identity must not append a second featured image.
                    $level->allPhotos()->firstOrCreate(['type' => 'featured'], ['path' => $path]);
                }
                $complete($level);

                return $level;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, string $expected, ?UploadedFile $image): void
    {
        $path = $this->stageImage($image);
        try {
            DB::transaction(function () use ($id, $data, $expected, $path): void {
                $level = Level::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if (!hash_equals(LevelEditorVersion::for($level), $expected)) {
                    throw ValidationException::withMessages([
                        'editor_version' => "عدّل شخص آخر هذا المستوى\nأعد تحميل الصفحة قبل الحفظ",
                    ]);
                }
                $level->update($this->fields($data));
                if ($path !== null) {
                    $legacy = (string) ($level->badge_image ?? '');
                    $level->forceFill(['badge_image' => null])->save();
                    $oldPhotos = $level->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $level->allPhotos()->create(['path' => $path, 'type' => 'featured']);
                    $oldPhotos->each->delete();
                    $this->retireLegacyImage($legacy);
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    /** Returns false while courses or earned student badges still reference the level. */
    public function deleteIfUnused(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $level = Level::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($level->courses()->exists() || $level->users()->exists()) return false;
            $legacy = (string) ($level->badge_image ?? '');
            $level->delete();
            $this->retireLegacyImage($legacy);

            return true;
        }, 3);
    }

    private function stageImage(?UploadedFile $image, ?string $identity = null): ?string
    {
        if ($image === null) return null;
        $path = $this->uploads->storeTrackedUpload($image, 'levels', 'public', 60, $identity);
        if ($path === '') throw new \RuntimeException('Level badge storage failed');

        return $path;
    }

    private function retireLegacyImage(string $path): void
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || filter_var($path, FILTER_VALIDATE_URL) || str_starts_with($path, 'assets/')) return;
        $this->cleanup->deleteOrQueue('public', $path);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function fields(array $data): array
    {
        return Arr::only($data, ['name_ar', 'name_en', 'description_ar', 'description_en', 'order']);
    }
}
