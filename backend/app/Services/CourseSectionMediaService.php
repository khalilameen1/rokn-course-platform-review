<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\CourseSectionEdit;
use App\Jobs\ProbeLessonMedia;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Support\DurableJobDispatch;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final readonly class CourseSectionMediaService
{
    public function __construct(
        private BunnyService $bunny,
        private BunnyMediaRegistry $mediaRegistry,
        private BunnyDirectUploadService $directUploads
    ) {
    }

    public function stage(
        CourseSectionEdit $edit,
        ?User $actor,
        Course $course,
        ?CourseSection $section,
        ?Lesson $previousLesson
    ): CourseSectionMediaStage {
        if ($edit->type !== 'lesson') {
            return new CourseSectionMediaStage(null, null, $previousLesson, false, false);
        }

        $videoGuid = null;
        if ($edit->videoClaim !== null) {
            if ($actor === null) {
                throw new RuntimeException('تعذر تحديد حساب المودريتور');
            }
            $claim = $this->directUploads->verifyForAttach(
                $course,
                $actor,
                $edit->videoClaim,
                $section
            );
            $videoGuid = (string) $claim['video_id'];
        }

        $thumbnailPath = null;
        if ($edit->thumbnail !== null) {
            $thumbnailPath = $this->bunny->uploadFileToStorage(
                $edit->thumbnail,
                'lessons/thumbnails',
                $edit->requestId,
                'section_thumbnail_unpublished'
            );
            if (!$thumbnailPath) {
                throw new RuntimeException('تعذر رفع صورة المقطع والصورة السابقة لم تتغير');
            }
        }

        return new CourseSectionMediaStage(
            $videoGuid,
            $thumbnailPath,
            $previousLesson,
            $videoGuid !== null,
            $thumbnailPath !== null
        );
    }

    /** Consume staged cleanup leases inside the section transaction. */
    public function attach(CourseSectionMediaStage $stage): void
    {
        if ($stage->videoGuid) {
            $this->directUploads->consume($stage->videoGuid);
        }
        if ($stage->thumbnailPath) {
            $this->mediaRegistry->consumeStorageCleanupCandidate($stage->thumbnailPath);
        }
    }

    public function retireReplaced(CourseSectionMediaStage $stage, string $newType): void
    {
        $oldVideo = $stage->previousVideoGuid();
        if ($oldVideo && ($newType !== 'lesson' || $stage->videoChanged)) {
            $reason = $newType !== 'lesson' ? 'section_type_changed' : 'superseded_video';
            // A type change has already deleted the old lesson inside the
            // section transaction. Do not attach a cleanup row to a vanished
            // FK; the GUID remains the durable cleanup identity.
            $cleanupLesson = $newType === 'lesson' ? $stage->previousLesson : null;
            if (!$this->mediaRegistry->queueVideoCleanup($oldVideo, $cleanupLesson, $reason, 168, true)) {
                throw new RuntimeException('تعذر تسجيل تقاعد الفيديو السابق بأمان');
            }
        }

        $oldThumbnail = $stage->previousThumbnailPath();
        if ($oldThumbnail && ($newType !== 'lesson' || $stage->thumbnailChanged)) {
            if (!$this->mediaRegistry->queueStorageCleanup($oldThumbnail, 'superseded_lesson_thumbnail')) {
                throw new RuntimeException('تعذر تأمين تقاعد صورة المقطع السابقة');
            }
        }
    }

    public function retireDeleted(?Lesson $lesson): void
    {
        if (!$lesson) {
            return;
        }

        $videoGuid = trim((string) $lesson->bunny_video_id);
        if ($videoGuid !== '' && !$this->mediaRegistry->queueVideoCleanup(
            $videoGuid,
            // Deletion removes the lesson in the same transaction. Cleanup is
            // keyed by the provider GUID and must not require that row to live.
            null,
            'section_deleted',
            168,
            true
        )) {
            throw new RuntimeException('تعذر تسجيل تقاعد الفيديو بأمان');
        }

        $thumbnailPath = trim((string) $lesson->thumbnail_path);
        if ($thumbnailPath !== '' && !$this->mediaRegistry->queueStorageCleanup($thumbnailPath, 'section_deleted')) {
            throw new RuntimeException('تعذر تأمين حذف صورة المقطع');
        }
    }

    public function rollback(CourseSectionMediaStage $stage, string $reason): void
    {
        if ($stage->videoGuid) {
            $this->mediaRegistry->queueVideoCleanup(
                $stage->videoGuid,
                $stage->previousLesson,
                $reason,
                24,
                true
            );
        }
        if ($stage->thumbnailPath) {
            $this->mediaRegistry->queueStorageCleanup($stage->thumbnailPath, $reason);
        }
    }

    public function probe(Lesson $lesson): void
    {
        try {
            DurableJobDispatch::afterCommit(new ProbeLessonMedia(
                (int) $lesson->id,
                (string) $lesson->bunny_video_id
            ));
        } catch (Throwable $exception) {
            Log::warning('Lesson media probe remains pending after dispatch failure.', [
                'lesson_id' => $lesson->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
