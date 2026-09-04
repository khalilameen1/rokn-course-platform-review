<?php

namespace App\Http\Resources;

use App\Services\BunnyService;
use App\Support\PublicDiskUrl;
use Illuminate\Http\Resources\Json\JsonResource;

class SavedFolderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => (int)$this->id,
            'name' => (string)$this->name,
            'image' => $this->resolveFolderImage(),
            'lessons_count' => (int) ($this->lessons_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function resolveFolderImage(): string
    {
        $firstLesson = $this->relationLoaded('lessons')
            ? $this->lessons->first()
            : null;

        if ($firstLesson) {
            $thumbnailPath = trim((string) $firstLesson->thumbnail_path);
            if ($thumbnailPath !== '' && $firstLesson->hasReadyMediaState()) {
                $signed = app(BunnyService::class)->generateBunnySignedUrl($thumbnailPath);
                if ($signed) {
                    return $signed;
                }
            }

            // `image` is the legacy public thumbnail column. It is not a Bunny
            // object key, so signing it can manufacture a valid-looking URL
            // on the wrong host when private media delivery is configured.
            $legacyImage = $this->publicLessonImage($firstLesson->image);
            if ($legacyImage !== null) {
                return $legacyImage;
            }

            if ($firstLesson->relationLoaded('course') && $firstLesson->course && $firstLesson->course->image) {
                return (string)$firstLesson->course->image;
            }
        }

        return asset('images/default-folder.png');
    }

    private function publicLessonImage(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (str_starts_with(strtolower($value), 'https://')) return $value;
        if (str_starts_with(ltrim($value, '/'), 'storage/')) {
            return PublicDiskUrl::from($value);
        }

        return asset(ltrim($value, '/'));
    }
}
