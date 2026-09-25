<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

final readonly class CourseSectionMediaStage
{
    private ?string $previousVideo;
    private ?string $previousThumbnail;

    public function __construct(
        public ?string $videoGuid,
        public ?string $thumbnailPath,
        public ?Lesson $previousLesson,
        public bool $videoChanged,
        public bool $thumbnailChanged
    ) {
        $video = trim((string) $previousLesson?->bunny_video_id);
        $thumbnail = trim((string) $previousLesson?->thumbnail_path);
        $this->previousVideo = $video !== '' ? $video : null;
        $this->previousThumbnail = $thumbnail !== '' ? $thumbnail : null;
    }

    public function previousVideoGuid(): ?string
    {
        return $this->previousVideo;
    }

    public function previousThumbnailPath(): ?string
    {
        return $this->previousThumbnail;
    }
}
