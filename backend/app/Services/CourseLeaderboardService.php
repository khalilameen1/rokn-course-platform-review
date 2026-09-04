<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Carbon\Carbon;
use App\Support\BusinessClock;

final readonly class CourseLeaderboardService
{
    public function __construct(
        private CourseSectionSequenceService $sectionSequence,
        private CourseRevisionLearnerReadService $revisionReads
    ) {}

    /**
     * @return array{message: string, data: array<string, mixed>}
     */
    public function forCourse(int $courseId): array
    {
        $course = Course::with('sections')
            ->where('is_coming_soon', false)
            ->whereHas('sections')
            ->findOrFail($courseId);

        $lastFriday = BusinessClock::now();
        while ($lastFriday->dayOfWeek !== Carbon::FRIDAY) {
            $lastFriday->subDay();
        }
        $lastFridayDate = $lastFriday->addDay()->startOfDay()->utc();
        $learningSections = $this->sectionSequence->learning($course->sections);

        $students = User::query()
            ->whereHas('enrollments', function ($enrollments) use ($courseId): void {
                $enrollments->where('course_id', $courseId)->active();
            })
            ->get();
        $progressByStudent = $this->revisionReads->sectionProgressRowsForUsers(
            $students->pluck('id'),
            $learningSections->pluck('id'),
            $lastFridayDate
        )->groupBy('user_id');
        $students->each(fn (User $student) => $student->setRelation(
            'sectionProgress',
            $progressByStudent->get((int) $student->id, collect())
        ));

        $totalSections = $learningSections->count();
        $coursePayload = [
            'id' => $course->id,
            'title' => $course->name_ar ?? $course->name_en,
            'title_en' => $course->name_en,
            'description' => $course->description,
            'image' => $course->image,
            'total_sections' => $totalSections,
        ];

        if ($students->isEmpty()) {
            unset(
                $coursePayload['title_en'],
                $coursePayload['description'],
                $coursePayload['image']
            );

            return [
                'message' => 'لا يوجد طلاب في هذا الكورس بعد',
                'data' => [
                    'course' => $coursePayload,
                    'best_students' => [],
                ],
            ];
        }

        $isVeryShortCourse = $totalSections <= 3;
        $studentsData = $students
            ->map(fn (User $student): array => $this->studentPayload($student, $totalSections))
            ->all();

        usort(
            $studentsData,
            $isVeryShortCourse
                ? $this->shortCourseComparator(...)
                : $this->standardComparator(...)
        );

        $bestStudents = array_slice($studentsData, 0, 10);
        foreach ($bestStudents as $index => &$student) {
            $student['rank'] = $index + 1;
        }
        unset($student);

        return [
            'message' => 'تم تحميل قائمة الطلاب',
            'data' => [
                'course' => $coursePayload + [
                    'is_very_short_course' => $isVeryShortCourse,
                    'total_enrolled_students' => $students->count(),
                ],
                'ranking_criteria' => $isVeryShortCourse
                    ? 'الأسبق في إكمال الكورس'
                    : 'نسبة التقدم ثم وقت الإكمال',
                'best_students' => $bestStudents,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function studentPayload(User $user, int $totalSections): array
    {
        $completedSections = $user->sectionProgress;
        $completedCount = $completedSections->count();
        $progressPercentage = $totalSections > 0
            ? ($completedCount / $totalSections) * 100
            : 0;
        $isFullyCompleted = $completedCount === $totalSections && $totalSections > 0;
        $firstCompletionDate = $isFullyCompleted
            ? $completedSections->max('completed_at')
            : null;

        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'progress' => [
                'completed_sections' => $completedCount,
                'total_sections' => $totalSections,
                'progress_percentage' => round($progressPercentage, 2),
                'is_fully_completed' => $isFullyCompleted,
                'first_completion_date' => $this->formatCompletionDate($firstCompletionDate),
            ],
        ];
    }

    private function formatCompletionDate(mixed $date): ?string
    {
        if (!$date) {
            return null;
        }

        return $date instanceof Carbon
            ? $date->format('Y-m-d H:i:s')
            : Carbon::parse($date)->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function shortCourseComparator(array $left, array $right): int
    {
        $leftCompleted = $left['progress']['is_fully_completed'];
        $rightCompleted = $right['progress']['is_fully_completed'];

        if ($leftCompleted && $rightCompleted) {
            return ($left['progress']['first_completion_date']
                <=> $right['progress']['first_completion_date'])
                ?: ($left['user_id'] <=> $right['user_id']);
        }
        if ($leftCompleted) {
            return -1;
        }
        if ($rightCompleted) {
            return 1;
        }

        return ($right['progress']['progress_percentage']
            <=> $left['progress']['progress_percentage'])
            ?: ($left['user_id'] <=> $right['user_id']);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function standardComparator(array $left, array $right): int
    {
        if ($left['progress']['is_fully_completed'] !== $right['progress']['is_fully_completed']) {
            return $right['progress']['is_fully_completed']
                <=> $left['progress']['is_fully_completed'];
        }

        if (abs($left['progress']['progress_percentage'] - $right['progress']['progress_percentage']) > 0.01) {
            return $right['progress']['progress_percentage'] <=> $left['progress']['progress_percentage'];
        }

        return ($left['progress']['first_completion_date'] <=> $right['progress']['first_completion_date'])
            ?: ($left['user_id'] <=> $right['user_id']);
    }
}
