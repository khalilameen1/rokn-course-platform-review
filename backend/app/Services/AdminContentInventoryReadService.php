<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;

/** Counts logical courses, not their working copies or retained publication archives. */
final class AdminContentInventoryReadService
{
    /** @return Builder<Course> */
    public function courses(): Builder
    {
        return Course::query()->whereNotIn(
            'courses.id',
            CourseAuthoringRevision::query()->select('revision_course_id')
        );
    }

    /** @return array{courses:int,modules:int,sections:int,lessons:int,published:int} */
    public function summary(): array
    {
        $courseIds = fn () => $this->courses()->select('courses.id');

        return [
            'courses' => $this->courses()->count(),
            'modules' => CourseModule::query()->whereIn('course_id', $courseIds())->count(),
            'sections' => CourseSection::query()->whereIn('course_id', $courseIds())->count(),
            'lessons' => Lesson::query()->whereIn('list_id', $courseIds())->count(),
            'published' => $this->courses()->where('is_coming_soon', false)->count(),
        ];
    }
}
