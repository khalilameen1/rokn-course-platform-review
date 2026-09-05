<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminCourseLifecycleService
{
    /** @return array{course:Course, unlisted:bool, discardedDraft:bool} */
    public function archive(Course $course, int $expectedVersion): array
    {
        $unlisted = false;
        $discardedDraft = false;

        $course = DB::transaction(function () use (
            $course,
            $expectedVersion,
            &$unlisted,
            &$discardedDraft
        ): Course {
            $course = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            if ((int) $course->authoring_version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'authoring_version' => ["تغيّر الكورس منذ فتح الصفحة\nأعد تحميلها قبل الأرشفة"],
                ])->status(409);
            }

            $hasPublishedContract = $course->published_at !== null
                || (int) ($course->last_published_authoring_version ?? 0) > 0
                || !(bool) $course->is_coming_soon
                || $course->enrollments()->exists()
                || $course->orders()->exists();
            if (!$hasPublishedContract) {
                $course->delete();
                return $course;
            }

            $course->forceFill([
                'is_catalog_visible' => false,
                'is_main_course' => false,
                'authoring_version' => (int) $course->authoring_version + 1,
            ])->save();
            $unlisted = true;

            $draft = CourseAuthoringRevision::query()
                ->where('canonical_course_id', $course->id)
                ->where('status', CourseAuthoringRevision::DRAFT)
                ->lockForUpdate()
                ->first();
            if ($draft) {
                $draft->forceFill([
                    'status' => CourseAuthoringRevision::ARCHIVED,
                    'active_slot' => null,
                    'retain_until' => now()->addDays(
                        max(7, (int) config('playback.revision_grace_days', 7))
                    ),
                ])->save();
                $discardedDraft = true;
            }

            return $course;
        }, 3);

        return compact('course', 'unlisted', 'discardedDraft');
    }

    /** @return array{course:Course, preserved_learner_access:bool} */
    public function restore(int $courseId): array
    {
        $preservedLearnerAccess = false;
        $course = DB::transaction(function () use ($courseId, &$preservedLearnerAccess): Course {
            $course = Course::onlyTrashed()->whereKey($courseId)->lockForUpdate()->firstOrFail();
            $preservedLearnerAccess = CourseEnrollment::query()
                ->where('course_id', $course->id)
                ->active()
                ->exists();
            $course->restore();
            $course->forceFill([
                'is_catalog_visible' => false,
                'is_coming_soon' => !$preservedLearnerAccess,
                'is_main_course' => false,
                'authoring_version' => max(1, (int) $course->authoring_version + 1),
            ])->save();

            return $course;
        }, 3);

        return ['course' => $course, 'preserved_learner_access' => $preservedLearnerAccess];
    }
}
