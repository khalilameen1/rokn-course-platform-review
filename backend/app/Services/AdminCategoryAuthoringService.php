<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Support\CategoryEditorVersion;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Legacy public taxonomy and its owned images; not home-row course curation. */
final readonly class AdminCategoryAuthoringService
{
    private const FIELDS = ['name_ar', 'name_en', 'type', 'description_ar', 'description_en'];

    public function __construct(private StoredFileUploadService $uploads, private StoredFileDeletionService $cleanup)
    {
    }

    /** @param array<string,mixed> $validated @param Closure(Category):void $complete */
    public function create(array $validated, string $requestId, ?UploadedFile $image, Closure $complete): Category
    {
        $data = Arr::only($validated, self::FIELDS);
        $existing = Category::query()->where('authoring_request_id', $requestId)->first();
        if ($existing) $this->assertReplay($existing, $data, $image, $requestId);
        $path = $existing?->photo === null ? $this->stage($image, $requestId) : null;
        try {
            return DB::transaction(function () use ($data, $requestId, $path, $image, $complete): Category {
                $category = Category::query()->where('authoring_request_id', $requestId)->lockForUpdate()->first();
                if ($category) {
                    $this->assertReplay($category, $data, $image, $requestId);
                } else {
                    $category = Category::query()->create($data + ['authoring_request_id' => $requestId]);
                }
                if ($path !== null) $category->allPhotos()->firstOrCreate(['type' => 'featured'], ['path' => $path]);
                if ($image !== null && !$category->photo()->exists()) {
                    throw ValidationException::withMessages(['image' => 'تغيّرت صورة القسم أعد تحميل النموذج']);
                }
                $category->unsetRelation('photo');
                $complete($category);

                return $category;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $validated */
    public function update(int $id, array $validated, string $editorVersion, ?UploadedFile $image): void
    {
        $path = $this->stage($image);
        try {
            DB::transaction(function () use ($id, $validated, $editorVersion, $path): void {
                $category = Category::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if (!hash_equals(CategoryEditorVersion::for($category), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => "عدّل شخص آخر هذا القسم\nأعد تحميل الصفحة قبل الحفظ",
                    ]);
                }
                $category->update(Arr::only($validated, self::FIELDS));
                if ($path !== null) {
                    $old = $category->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $category->allPhotos()->create(['path' => $path, 'type' => 'featured']);
                    $old->each->delete();
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id): void {
            Category::query()->whereKey($id)->lockForUpdate()->firstOrFail()->delete();
        }, 3);
    }

    private function stage(?UploadedFile $image, ?string $requestId = null): ?string
    {
        if ($image === null) return null;

        return $this->uploads->storeTrackedUpload($image, 'categories', 'public', 60,
            $requestId === null ? null : $this->imageIdentity($requestId, $image));
    }

    private function imageIdentity(string $requestId, UploadedFile $image): string
    {
        return 'admin-category|'.strtolower($requestId).'|'.hash_file('sha256', $image->getRealPath());
    }

    private function assertReplay(Category $category, array $data, ?UploadedFile $image, string $requestId): void
    {
        $same = true;
        foreach (self::FIELDS as $field) {
            $same = $same && (string) ($category->{$field} ?? '') === (string) ($data[$field] ?? '');
        }
        if ($category->photo) {
            $same = $same && $image !== null
                && str_starts_with((string) $category->photo->path, 'categories/')
                && basename((string) $category->photo->path) === basename($this->uploads->trackedUploadDestination(
                    $image, 'categories', 'public', $this->imageIdentity($requestId, $image)
                ));
        }
        if (!$same) {
            throw ValidationException::withMessages([
                'authoring_request_id' => "تغيّرت بيانات القسم\nأعد فتح النموذج ثم أرسل",
            ]);
        }
    }
}
