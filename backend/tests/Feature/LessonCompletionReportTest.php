<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Services\LessonCompletionReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LessonCompletionReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
    }

    public function test_repeats_are_unique_students_and_can_never_create_a_rate_above_one_hundred(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('lesson_started', 'a', 1, 10, $at);
        $this->event('lesson_started', 'a', 1, 10, $at->addSecond());
        for ($i = 1; $i <= 5; $i++) $this->event('lesson_completed', 'a', 1, 10, $at->addMinutes($i));
        $this->event('lesson_started', 'b', 1, 10, $at);
        $row = $this->report()['rows']->sole();
        self::assertSame(2, $row['starts']);
        self::assertSame(1, $row['completions']);
        self::assertSame(50.0, $row['completion_rate']);
        self::assertSame(1, $row['pending']);
    }

    public function test_other_students_courses_lessons_and_earlier_completions_are_not_stitched_together(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('lesson_started', 'a', 1, 10, $at);
        $this->event('lesson_completed', 'b', 1, 10, $at->addMinute());
        $this->event('lesson_completed', 'a', 2, 10, $at->addMinute());
        $this->event('lesson_completed', 'a', 1, 11, $at->addMinute());
        $this->event('lesson_completed', 'a', 1, 10, $at->subMinute());
        self::assertSame(0, $this->report()['rows']->sole()['completions']);
        $this->event('lesson_completed', 'a', 1, 10, $at->addMinute());
        self::assertSame(1, $this->report()['rows']->sole()['completions']);
        self::assertTrue($this->report(2)['rows']->isEmpty());
    }

    public function test_entry_period_is_half_open_and_completion_may_follow_its_end(): void
    {
        $period = ReportPeriod::fromKey('7d', CarbonImmutable::now('UTC')->subDays(10));
        $this->event('lesson_started', 'before', 1, 10, $period->start->subSecond());
        $this->event('lesson_started', 'start', 1, 10, $period->start);
        $this->event('lesson_started', 'last', 1, 10, $period->end->subSecond());
        $this->event('lesson_completed', 'last', 1, 10, $period->end->addMinute());
        $this->event('lesson_started', 'after', 1, 10, $period->end);
        $row = $this->report(null, $period)['rows']->sole();
        self::assertSame(2, $row['starts']);
        self::assertSame(1, $row['completions']);
    }

    public function test_window_limit_is_inclusive_and_recent_incomplete_students_are_not_final_drop_off(): void
    {
        $old = CarbonImmutable::now('UTC')->subDays(20);
        $recent = CarbonImmutable::now('UTC')->subHour();
        $this->event('lesson_started', 'exact', 1, 10, $old);
        $this->event('lesson_completed', 'exact', 1, 10, $old->addDays(14));
        $this->event('lesson_started', 'late', 1, 10, $old);
        $this->event('lesson_completed', 'late', 1, 10, $old->addDays(14)->addSecond());
        $this->event('lesson_started', 'pending', 1, 10, $recent);
        $row = $this->report()['rows']->sole();
        self::assertSame(3, $row['starts']);
        self::assertSame(1, $row['completions']);
        self::assertSame(2, $row['not_completed']);
        self::assertSame(1, $row['pending']);
        self::assertSame(1, $row['window_elapsed_without_completion']);
    }

    public function test_a_later_attempt_is_not_hidden_by_an_early_failed_entry_and_same_second_is_supported(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('lesson_started', 'a', 1, 10, $at->subDays(20));
        $this->event('lesson_completed', 'a', 1, 10, $at);
        $this->event('lesson_started', 'a', 1, 10, $at);
        $row = $this->report()['rows']->sole();
        self::assertSame(1, $row['starts']);
        self::assertSame(1, $row['completions']);
        self::assertSame(0, $row['pending']);
    }

    public function test_client_events_missing_dimensions_and_future_entries_are_not_verified_learning(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('lesson_started', 'a', 1, 10, $at, 'app');
        $this->event('lesson_completed', 'a', 1, 10, $at->addMinute(), 'app');
        $this->event('lesson_started', null, 1, 10, $at);
        $this->event('lesson_started', 'a', null, 10, $at);
        $this->event('lesson_started', 'a', 1, null, $at);
        $this->event('lesson_started', 'future', 1, 10, CarbonImmutable::now('UTC'));
        $report = $this->report();
        self::assertTrue($report['rows']->isEmpty());
        self::assertSame(0, $report['total_lessons']);
    }

    public function test_original_lesson_history_is_kept_with_names_or_explicit_missing_fallback(): void
    {
        $course = Course::forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس أصلي', 'price' => 10, 'is_coming_soon' => true]);
        $archive = Course::forceCreate(['tenant_id' => 1, 'name_ar' => 'نسخة سابقة', 'price' => 10, 'is_coming_soon' => true]);
        $lesson = Lesson::forceCreate(['list_id' => $archive->id, 'title' => 'Original media',
            'title_ar' => 'درس سابق', 'video_link' => 'https://example.test/video.mp4']);
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('lesson_started', 'a', $course->id, $lesson->id, $at);
        $this->event('lesson_completed', 'a', $course->id, $lesson->id, $at->addMinute());
        $row = $this->report($course->id)['rows']->sole();
        self::assertSame('كورس أصلي', $row['course_title']);
        self::assertSame('درس سابق', $row['lesson_title']);
        self::assertTrue($row['lesson_archived']);
        $course->delete();
        DB::table('lessons')->where('id', $lesson->id)->delete();
        $row = $this->report($course->id)['rows']->sole();
        self::assertTrue($row['course_archived']);
        self::assertTrue($row['lesson_archived']);
        self::assertNull($row['lesson_title']);
        self::assertSame(1, $row['completions']);
    }

    public function test_rows_rank_unfinished_students_and_disclose_truncation_not_silent_loss(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        for ($i = 1; $i <= 101; $i++) $this->event('lesson_started', 'a', 1, $i, $at);
        $this->event('lesson_started', 'b', 1, 101, $at);
        $report = $this->report();
        self::assertSame(101, $report['total_lessons']);
        self::assertCount(100, $report['rows']);
        self::assertSame(101, $report['rows']->first()['lesson_id']);
        self::assertSame(2, $report['rows']->first()['not_completed']);
    }

    private function report(?int $course = null, ?ReportPeriod $period = null): array
    {
        return app(LessonCompletionReportService::class)->report($course, $period ?? ReportPeriod::fromKey('30d'));
    }

    private function event(string $name, ?string $actor, ?int $course, ?int $lesson,
        CarbonImmutable $at, string $source = 'server'): void
    {
        ProductEvent::create(['event_id' => (string) Str::uuid(), 'event_name' => $name,
            'actor_key' => $actor === null ? null : hash('sha256', $actor),
            'course_id' => $course, 'lesson_id' => $lesson, 'source' => $source,
            'occurred_at' => $at, 'received_at' => CarbonImmutable::now('UTC')]);
    }
}
