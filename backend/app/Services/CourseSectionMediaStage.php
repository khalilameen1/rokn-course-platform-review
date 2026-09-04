<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

final readonly class CourseSectionMediaStage
{
    public function __construct(
        public ?string $videoGuid,
        public ?string $thumbnailPath,
        public ?Lesson $previousLesson,
        public bool $videoChanged,
        public bool $thumbnailChanged
    ) {
    }

    public function previousVideoGuid(): ?string
    {
        $value = trim((string) $this->previousLesson?->bunny_video_id);

        return $value !== '' ? $value : null;
    }

    public function previousThumbnailPath(): ?string
    {
        $value = trim((string) $this->previousLesson?->thumbnail_path);

        return $value !== '' ? $value : null;
    }
}
