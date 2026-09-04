<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class CourseDurationService
{
    public function __construct(
        private CourseSectionSequenceService $sectionSequence
    ) {}

    /** Verified media durations take precedence over authored estimates. */
    public function minutes(Course $course): int
    {
        $loadedSections = $course->relationLoaded('modules')
            ? $this->sectionSequence->fromModules($course->modules)
            : ($course->relationLoaded('sections') ? $course->sections : null);
        $lessonIds = $loadedSections !== null
            ? $loadedSections
                ->where('sectionable_type', Lesson::class)
                ->pluck('sectionable_id')
            : CourseSection::query()
                ->where('course_id', $course->getKey())
                ->where('sectionable_type', Lesson::class)
                ->pluck('sectionable_id');

        $lessons = $this->lessonDurationQuery()
            ->whereIn('id', $lessonIds->filter()->unique()->values())
            ->get(['id', 'duration_minutes']);

        $lessonSeconds = (int) $lessons->sum($this->lessonSeconds(...));

        return $lessonSeconds > 0 ? (int) ceil($lessonSeconds / 60) : 0;
    }

    public function attach(Course $course): Course
    {
        $course->setAttribute('duration_minutes_computed', $this->minutes($course));

        return $course;
    }

    /**
     * Attach verified durations to a catalogue page without issuing queries per course.
     *
     * @param Collection<int, Course> $courses
     * @return Collection<int, Course>
     */
    public function attachMany(Collection $courses): Collection
    {
        $coursesById = $courses
            ->filter(fn (Course $course): bool => $course->getKey() !== null)
            ->keyBy(fn (Course $course): int => (int) $course->getKey());

        if ($coursesById->isEmpty()) {
            return $courses;
        }

        $lessonIdsByCourse = CourseSection::query()
            ->whereIn('course_id', $coursesById->keys())
            ->where('sectionable_type', Lesson::class)
            ->get(['course_id', 'sectionable_id'])
            ->groupBy('course_id')
            ->map(
                fn (Collection $sections): Collection => $sections
                    ->pluck('sectionable_id')
                    ->filter()
                    ->unique()
                    ->values()
            );

        $lessonSeconds = $this->lessonDurationQuery()
            ->whereIn('id', $lessonIdsByCourse->flatten()->unique()->values())
            ->get(['id', 'duration_minutes'])
            ->mapWithKeys(fn (Lesson $lesson): array => [
                (int) $lesson->getKey() => $this->lessonSeconds($lesson),
            ]);

        foreach ($coursesById as $courseId => $course) {
            $seconds = (int) $lessonIdsByCourse
                ->get($courseId, collect())
                ->sum(fn ($lessonId): int => (int) $lessonSeconds->get((int) $lessonId, 0));
            $course->setAttribute(
                'duration_minutes_computed',
                $seconds > 0 ? (int) ceil($seconds / 60) : 0
            );
        }

        return $courses;
    }

    private function lessonSeconds(Lesson $lesson): int
    {
        $providerSeconds = $lesson->relationLoaded('mediaState')
            ? max(0, (int) ($lesson->mediaState?->duration_seconds ?? 0))
            : 0;
        if ($providerSeconds > 0) {
            return $providerSeconds;
        }

        return max(0, (int) $lesson->duration_minutes) * 60;
    }

    private function lessonDurationQuery(): Builder
    {
        return Lesson::query()->with('mediaState:id,lesson_id,duration_seconds');
    }

}
