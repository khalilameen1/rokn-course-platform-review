<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use App\Services\CourseSectionMediaStage;
use Tests\TestCase;

final class CourseSectionMediaStageTest extends TestCase
{
    public function test_old_media_paths_are_snapshots_even_when_the_lesson_object_changes(): void
    {
        $lesson = new Lesson(['bunny_video_id' => 'old-video', 'thumbnail_path' => 'old-image.webp']);
        $stage = new CourseSectionMediaStage('new-video', 'new-image.webp', $lesson, true, true);
        $lesson->forceFill(['bunny_video_id' => 'new-video', 'thumbnail_path' => 'new-image.webp']);

        self::assertSame('old-video', $stage->previousVideoGuid());
        self::assertSame('old-image.webp', $stage->previousThumbnailPath());
        self::assertSame($lesson, $stage->previousLesson, 'The cleanup FK still identifies the same lesson.');
    }

    public function test_absent_or_blank_previous_media_is_normalized_to_null(): void
    {
        foreach ([null, new Lesson(['bunny_video_id' => '  ', 'thumbnail_path' => ''])] as $lesson) {
            $stage = new CourseSectionMediaStage(null, null, $lesson, false, false);
            self::assertNull($stage->previousVideoGuid());
            self::assertNull($stage->previousThumbnailPath());
        }
    }
}
