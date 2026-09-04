<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Support\DatabaseCapabilities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keeps the single public home hero consistent with course authoring revisions. */
final class CourseHeroSelectionService
{
    public function synchronize(
        Course $course,
        int $expectedAuthoringVersion,
        bool $requestedMain
    ): void {
        DB::transaction(function () use ($course, $expectedAuthoringVersion, $requestedMain): void {
            /** @var Collection<int, Course> $rootCourses */
            $rootQuery = Course::query();
            if (DatabaseCapabilities::hasTable('course_authoring_revisions')) {
                // Drafts and retained archives are implementation copies, not
                // public hero candidates. Touching one here would silently
                // overwrite an unrelated moderator's pending selection.
                $rootQuery->whereNotIn(
                    'id',
                    CourseAuthoringRevision::query()->select('revision_course_id')
                );
            }
            $rootCourses = $rootQuery
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'is_main_course',
                    'is_coming_soon',
                    'is_catalog_visible',
                    'authoring_version',
                ]);
            $lockedCourse = $rootCourses->firstWhere('id', $course->getKey());
            if (!$lockedCourse) {
                throw ValidationException::withMessages([
                    'is_main_course' => ['لا يمكن اختيار كورس فرعي للواجهة الرئيسية'],
                ]);
            }
            if ((int) $lockedCourse->authoring_version !== $expectedAuthoringVersion) {
                throw ValidationException::withMessages([
                    'authoring_version' => [
                        'تغيّر اختيار الكورس الرئيسي أثناء الحفظ\nأعد تحميل الصفحة قبل المحاولة',
                    ],
                ])->status(409);
            }

            $eligible = $rootCourses
                ->filter(fn (Course $candidate): bool =>
                    !(bool) $candidate->is_coming_soon
                    && (bool) $candidate->is_catalog_visible
                );
            $otherEligible = $eligible->where('id', '<>', $lockedCourse->id);
            $target = $requestedMain && $eligible->contains('id', $lockedCourse->id)
                ? $lockedCourse
                : ($otherEligible->firstWhere('is_main_course', true)
                    ?? $otherEligible->sortByDesc('id')->first()
                    ?? $eligible->firstWhere('is_main_course', true)
                    ?? $eligible->sortByDesc('id')->first());
            $targetId = $target?->id;

            foreach ($rootCourses as $rootCourse) {
                $shouldBeMain = $targetId !== null && (int) $rootCourse->id === (int) $targetId;
                if ((bool) $rootCourse->is_main_course === $shouldBeMain) {
                    continue;
                }
                $rootCourse->forceFill([
                    'is_main_course' => $shouldBeMain,
                    'authoring_version' => (int) $rootCourse->authoring_version + 1,
                ])->save();
            }
        }, 3);
    }
}
