<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;

final class CourseLearningHealthService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CourseRevisionLearnerReadService $revisionReads
    ) {}

    /**
     * Real learner progress for one course, aggregated without exposing student
     * identities to the course-authoring role.
     *
     * @return array{
     *   enrolled_students:int,
     *   started_students:int,
     *   completed_students:int,
     *   not_started_students:int,
     *   average_progress_percentage:int
     * }
     */
    public function forCourse(Course $course): array
    {
        $learningSectionIds = $this->sectionSequence
            ->learning($course->sections()->get([
                'id', 'course_id', 'module_id', 'section_type',
                'sectionable_type', 'order',
            ]))
            ->pluck('id')
            ->values();
        $sectionCount = $learningSectionIds->count();
        $activeStudentIds = DB::table('course_enrollments')
            ->join('users', 'users.id', '=', 'course_enrollments.user_id')
            ->where('course_enrollments.course_id', $course->id)
            ->where('course_enrollments.is_active', true)
            ->whereNull('users.deleted_at')
            ->whereRaw('LOWER(users.role) = ?', ['client'])
            ->where(static function ($active): void {
                $active->whereNull('course_enrollments.expires_at')
                    ->orWhere('course_enrollments.expires_at', '>', now());
            })
            ->distinct()
            ->pluck('course_enrollments.user_id')
            ->map(fn ($id): int => (int) $id);
        $completedCounts = $this->revisionReads
            ->sectionProgressRowsForUsers($activeStudentIds, $learningSectionIds)
            ->where('is_completed', true)
            ->groupBy('user_id')
            ->map->count();

        $enrolled = $activeStudentIds->count();
        $started = $completedCounts->filter(fn (int $count): bool => $count > 0)->count();
        $completed = $sectionCount > 0
            ? $completedCounts->filter(fn (int $count): bool => $count >= $sectionCount)->count()
            : 0;
        $averageCompleted = $enrolled > 0 ? $completedCounts->sum() / $enrolled : 0;

        return [
            'enrolled_students' => $enrolled,
            'started_students' => min($enrolled, $started),
            'completed_students' => min($enrolled, $completed),
            'not_started_students' => max(0, $enrolled - $started),
            'average_progress_percentage' => $sectionCount > 0
                ? min(100, max(0, (int) round(($averageCompleted / $sectionCount) * 100)))
                : 0,
        ];
    }
}
