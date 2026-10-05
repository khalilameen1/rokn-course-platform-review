<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Support\BusinessClock;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

/** Observed learning conversion, not raw video-session ratios or final churn. */
final readonly class LessonCompletionReportService
{
    private const WINDOW_DAYS = 14;
    private const ROW_LIMIT = 100;

    public function __construct(private SequentialProductEventQuery $journeys) {}

    public function report(?int $courseId, ReportPeriod $period): array
    {
        $observedAt = BusinessClock::utcNow()->startOfSecond();
        $journeys = $this->journeys->build($courseId, $period,
            ['lesson_started', 'lesson_completed'], ['course_id', 'lesson_id'],
            self::WINDOW_DAYS, $observedAt);
        // Preserve the original lesson identity across editorial revisions.
        // Correlation is enforced before counting each learner once per lesson.
        $actors = DB::query()->fromSub($journeys, 'journey')
            ->select(['actor_key', 'course_id', 'lesson_id'])
            ->selectRaw('MAX(deadline_at) as latest_deadline_at, MAX(step_1_at) as completed_at')
            ->groupBy('actor_key', 'course_id', 'lesson_id');
        $lessons = DB::query()->fromSub($actors, 'actor')
            ->select(['course_id', 'lesson_id'])
            ->selectRaw('COUNT(*) as starters, COUNT(completed_at) as completers')
            ->selectRaw('COUNT(*) - COUNT(completed_at) as not_completed')
            ->selectRaw('SUM(CASE WHEN completed_at IS NULL AND latest_deadline_at >= ? THEN 1 ELSE 0 END) as pending',
                [$observedAt->toDateTimeString()])
            ->groupBy('course_id', 'lesson_id');
        $totalLessons = DB::query()->fromSub(clone $lessons, 'lesson')->count();
        $rows = $lessons->orderByDesc('not_completed')->orderByDesc('starters')
            ->orderBy('course_id')->orderBy('lesson_id')->limit(self::ROW_LIMIT)->get();
        // Batched names only: no N+1 queries and no filtering out archived facts.
        $courses = Course::withTrashed()->whereIn('id', $rows->pluck('course_id'))
            ->get(['id', 'name_ar', 'name_en', 'deleted_at'])->keyBy('id');
        $catalogue = Lesson::query()->whereIn('id', $rows->pluck('lesson_id'))
            ->get(['id', 'list_id', 'title', 'title_ar', 'title_en'])->keyBy('id');
        $items = $rows->map(function ($row) use ($courses, $catalogue): array {
            $course = $courses->get((int) $row->course_id);
            $lesson = $catalogue->get((int) $row->lesson_id);
            $starts = (int) $row->starters;
            $completions = (int) $row->completers;
            $notCompleted = (int) $row->not_completed;
            $pending = (int) $row->pending;
            return [
                'course_id' => (int) $row->course_id,
                'course_title' => $course?->name_ar ?: ($course?->name_en ?: null),
                'course_archived' => $course === null || $course->trashed(),
                'lesson_id' => (int) $row->lesson_id,
                'lesson_title' => $lesson?->title_ar ?: ($lesson?->title_en ?: $lesson?->title),
                'lesson_archived' => $lesson === null || (int) $lesson->list_id !== (int) $row->course_id,
                'starts' => $starts,
                'completions' => $completions,
                'completion_rate' => $starts > 0 ? round(100 * $completions / $starts, 1) : null,
                'not_completed' => $notCompleted,
                'pending' => $pending,
                'window_elapsed_without_completion' => $notCompleted - $pending,
            ];
        });
        return ['rows' => $items, 'window_days' => self::WINDOW_DAYS,
            'observed_at' => $observedAt, 'total_lessons' => $totalLessons,
            'row_limit' => self::ROW_LIMIT];
    }
}
