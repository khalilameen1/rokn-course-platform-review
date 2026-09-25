<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Models\StudentSectionProgress;
use App\Support\SectionProgressSummary;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SectionProgressSummaryTest extends TestCase
{
    public function test_projection_uses_only_entitled_sections_and_counts_a_completion_once_without_queries(): void
    {
        $sections = collect([
            (new CourseSection())->forceFill(['id' => 1, 'sectionable_type' => Lesson::class]),
            (new CourseSection())->forceFill(['id' => 2, 'sectionable_type' => Project::class]),
        ]);
        $progress = collect([
            $this->row(1, true, '2026-09-01 12:00:00'),
            $this->row(1, true, '2026-09-01 12:00:00'),
            $this->row(2, false, '2026-09-02 12:00:00'),
            $this->row(99, true, '2026-09-20 12:00:00'),
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $summary = SectionProgressSummary::for($sections, $progress);
            self::assertSame(2, $summary['total_sections']);
            self::assertSame(1, $summary['completed_sections']);
            self::assertSame(50, $summary['progress_percentage']);
            self::assertSame(['lesson' => 1, 'project' => 1], $summary['sections_by_type']);
            self::assertSame(['lesson' => 1, 'project' => 0], $summary['completed_by_type']);
            self::assertSame('2026-09-02 12:00:00', $summary['last_activity']->format('Y-m-d H:i:s'));
            self::assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_empty_sequence_does_not_inherit_unrelated_activity(): void
    {
        self::assertSame([
            'total_sections' => 0, 'completed_sections' => 0, 'progress_percentage' => 0,
            'sections_by_type' => [], 'completed_by_type' => [], 'last_activity' => null,
        ], SectionProgressSummary::for(collect(), collect([$this->row(1, true, '2026-09-01 12:00:00')])));
    }

    private function row(int $sectionId, bool $completed, string $at): StudentSectionProgress
    {
        return (new StudentSectionProgress())->forceFill([
            'course_section_id' => $sectionId, 'is_completed' => $completed,
            'completed_at' => $completed ? $at : null, 'updated_at' => $at,
        ]);
    }
}
