<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProbeLessonMedia;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Support\DurableJobDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final readonly class CourseSectionMediaService
{
    public function __construct(
        private BunnyService $bunny,
        private BunnyDirectUploadService $directUploads
    ) {
    }

    public function stage(
        Request $request,
        Course $course,
        ?CourseSection $section,
        ?Lesson $previousLesson
    ): CourseSectionMediaStage {
        if ((string) $request->input('section_type') !== 'lesson') {
            return new CourseSectionMediaStage(null, null, $previousLesson, false, false);
        }

        $videoGuid = null;
        if ($request->filled('bunny_video_claim')) {
            $admin = $request->user();
            if (!$admin instanceof User) {
                throw new RuntimeException('تعذر تحديد حساب المودريتور');
            }
            $claim = $this->directUploads->verifyForAttach(
                $course,
                $admin,
                (string) $request->input('bunny_video_claim'),
                $section
            );
            $videoGuid = (string) $claim['video_id'];
        }

        $thumbnailPath = null;
        if ($request->hasFile('lesson_thumbnail')) {
            $thumbnailPath = $this->bunny->uploadFileToStorage(
                $request->file('lesson_thumbnail'),
                'lessons/thumbnails',
                $request->string('authoring_request_id')->toString() ?: null,
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
            $this->bunny->consumeStorageCleanupCandidate($stage->thumbnailPath);
        }
    }

    public function retireReplaced(CourseSectionMediaStage $stage, string $newType): void
    {
        $oldVideo = $stage->previousVideoGuid();
        if ($oldVideo && ($newType !== 'lesson' || $stage->videoChanged)) {
            $reason = $newType !== 'lesson' ? 'section_type_changed' : 'superseded_video';
            if (!$this->bunny->queueVideoCleanup($oldVideo, $stage->previousLesson, $reason, 168, true)) {
                throw new RuntimeException('تعذر تسجيل تقاعد الفيديو السابق بأمان');
            }
        }

        $oldThumbnail = $stage->previousThumbnailPath();
        if ($oldThumbnail && ($newType !== 'lesson' || $stage->thumbnailChanged)) {
            if (!$this->bunny->queueStorageCleanup($oldThumbnail, 'superseded_lesson_thumbnail')) {
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
        if ($videoGuid !== '' && !$this->bunny->queueVideoCleanup(
            $videoGuid,
            $lesson,
            'section_deleted',
            168,
            true
        )) {
            throw new RuntimeException('تعذر تسجيل تقاعد الفيديو بأمان');
        }

        $thumbnailPath = trim((string) $lesson->thumbnail_path);
        if ($thumbnailPath !== '' && !$this->bunny->queueStorageCleanup($thumbnailPath, 'section_deleted')) {
            throw new RuntimeException('تعذر تأمين حذف صورة المقطع');
        }
    }

    public function rollback(CourseSectionMediaStage $stage, string $reason): void
    {
        if ($stage->videoGuid) {
            $this->bunny->queueVideoCleanup(
                $stage->videoGuid,
                $stage->previousLesson,
                $reason,
                24,
                true
            );
        }
        if ($stage->thumbnailPath) {
            $this->bunny->queueStorageCleanup($stage->thumbnailPath, $reason);
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
