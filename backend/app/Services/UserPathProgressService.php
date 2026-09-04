<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Level;
use App\Models\User;

final readonly class UserPathProgressService
{
    public function __construct(
        private FinancialProvenanceService $financialProvenance,
        private CourseSectionSequenceService $sectionSequence,
        private CourseRevisionLearnerReadService $revisionReads,
        private CourseCatalogueQueryService $catalogue
    )
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $enrollments = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereHas('course', function ($courses): void {
                // Catalogue visibility controls discovery, not an existing
                // learner's access. An administrator may unlist a live course
                // while its students continue it; their path and percentage
                // must not disappear with the public card.
                $courses->whereNotNull('path_id')
                    ->where('is_coming_soon', false)
                    ->whereHas('sections');
            })
            ->with([
                'course.coursePath',
                'course.level',
                'course.sections',
            ])
            ->get()
            ->reject(fn (CourseEnrollment $enrollment): bool =>
                $this->financialProvenance->enrollmentHasActiveHold($enrollment, ['course'])
            )
            ->values();

        $grouped = $enrollments->groupBy(function (CourseEnrollment $enrollment): mixed {
            return $enrollment->course->path_id;
        });
        $pathIds = $grouped->keys()->filter()->values()->all();
        $levelsByPath = collect();
        if ($pathIds !== []) {
            $publicCourses = Course::query()
                ->whereIn('path_id', $pathIds)
                ->whereNotNull('level_id')
                ->with('level');
            $this->catalogue->constrainPublic($publicCourses);
            $publicCourses
                ->where('is_coming_soon', false)
                ->whereHas('sections');
            $levelsByPath = $publicCourses
                ->get(['id', 'path_id', 'level_id'])
                ->groupBy('path_id')
                ->map(fn ($pathCourses) => $pathCourses
                    ->pluck('level')
                    ->filter()
                    ->unique('id')
                    ->sortBy(fn (Level $level): string => sprintf(
                        '%010d:%020d',
                        (int) $level->order,
                        (int) $level->id
                    ))
                    ->values());
        }

        $data = [];
        foreach ($grouped as $pathId => $groupEnrollments) {
            $courses = $groupEnrollments->map->course;
            $path = $courses->first()?->coursePath;
            if ($path === null) {
                continue;
            }

            $coursesWithLevels = $courses
                ->filter(function (Course $course): bool {
                    return $course->level_id !== null
                        && $course->relationLoaded('level')
                        && $course->level !== null;
                })
                ->sortBy(fn (Course $course): string => sprintf(
                    '%010d:%020d',
                    (int) ($course->level->order ?? 0),
                    (int) $course->id
                ))
                ->values();

            $sectionIds = $courses
                ->flatMap(fn (Course $course) => $this->sectionSequence
                    ->learning($course->sections)
                    ->pluck('id'))
                ->unique()
                ->values();
            $totalSections = $sectionIds->count();
            $completedSectionIds = $totalSections > 0
                ? $this->revisionReads->completedSectionIds(
                    (int) $user->id,
                    $sectionIds
                )
                : collect();
            $completedSections = $completedSectionIds->count();
            $progressPercentage = $totalSections > 0
                ? round(($completedSections / $totalSections) * 100, 2)
                : 0.0;

            $courseProgress = $coursesWithLevels->mapWithKeys(function (Course $course) use (
                $completedSectionIds
            ): array {
                $ids = $this->sectionSequence
                    ->learning($course->sections)
                    ->pluck('id')
                    ->unique();
                $total = $ids->count();
                $completed = $ids->intersect($completedSectionIds)->count();

                return [(int) $course->id => [
                    'total' => $total,
                    'completed' => $completed,
                    'percentage' => $total > 0 ? ($completed / $total) * 100 : 0.0,
                ]];
            });
            $currentCourse = $coursesWithLevels->first(function (Course $course) use (
                $courseProgress
            ): bool {
                return (float) ($courseProgress->get((int) $course->id)['percentage'] ?? 0) < 100;
            }) ?? $coursesWithLevels->last();
            $currentLevel = $currentCourse?->level;

            $levelsForPath = $levelsByPath->get($pathId, collect());
            $nextLevel = $levelsForPath
                ->first(fn (Level $level): bool => $currentLevel === null
                    || (int) $level->order > (int) $currentLevel->order);
            $currentLevelCourseIds = $currentLevel
                ? $coursesWithLevels
                    ->where('level_id', $currentLevel->id)
                    ->pluck('id')
                : collect();
            $currentLevelTotal = (int) $currentLevelCourseIds->sum(
                fn ($courseId): int => (int) ($courseProgress->get((int) $courseId)['total'] ?? 0)
            );
            $currentLevelCompleted = (int) $currentLevelCourseIds->sum(
                fn ($courseId): int => (int) ($courseProgress->get((int) $courseId)['completed'] ?? 0)
            );
            $currentLevelProgress = $currentLevelTotal > 0
                ? round(($currentLevelCompleted / $currentLevelTotal) * 100, 2)
                : 0.0;
            $levels = $levelsForPath
                // The mobile contract calls these upcoming levels. Returning
                // already-completed levels made a valid response look as if
                // the learner had several next targets and hid the real
                // progression order. Keep the current level in its dedicated
                // field and return only what can still be reached.
                ->filter(fn (Level $level): bool => $currentLevel === null
                    || (int) $level->order > (int) $currentLevel->order)
                ->map(fn (Level $level): array => [
                    'id' => $level->id,
                    'name_ar' => $level->name_ar,
                    'name_en' => $level->name_en,
                    'badge_image_url' => $level->badge_image_url,
                    'order' => (int) $level->order,
                ])
                ->values()
                ->all();

            $data[] = [
                'path' => [
                    'id' => $path->id,
                    'title' => $path->title,
                    'title_ar' => $path->title_ar,
                    'title_en' => $path->title_en,
                ],
                'levels' => $levels,
                'current_level' => $currentLevel ? [
                    'id' => $currentLevel->id,
                    'name_ar' => $currentLevel->name_ar,
                    'name_en' => $currentLevel->name_en,
                    'badge_image_url' => $currentLevel->badge_image_url,
                    'order' => (int) $currentLevel->order,
                ] : null,
                'next_level' => $nextLevel ? [
                    'id' => $nextLevel->id,
                    'name_ar' => $nextLevel->name_ar,
                    'name_en' => $nextLevel->name_en,
                    'badge_image_url' => $nextLevel->badge_image_url,
                    'order' => (int) $nextLevel->order,
                ] : null,
                'required_progress_percentage' => $nextLevel
                    ? round(max(0, 100 - $currentLevelProgress), 2)
                    : 0.0,
                'current_level_progress_percentage' => $currentLevelProgress,
                'enrolled_courses_count' => $courses->count(),
                'total_sections' => $totalSections,
                'completed_sections' => $completedSections,
                'progress_percentage' => $progressPercentage,
            ];
        }

        usort($data, static fn (array $left, array $right): int =>
            ((int) ($left['path']['id'] ?? 0)) <=> ((int) ($right['path']['id'] ?? 0))
        );

        return array_values($data);
    }
}
