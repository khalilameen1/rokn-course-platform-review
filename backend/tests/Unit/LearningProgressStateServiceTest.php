<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Services\LearningProgressStateService;
use PHPUnit\Framework\TestCase;

final class LearningProgressStateServiceTest extends TestCase
{
    public function test_progress_and_next_project_come_from_the_same_state(): void
    {
        $lesson = $this->section(10, Lesson::class, 100, 1);
        $project = $this->section(20, Project::class, 200, 2);

        $state = (new LearningProgressStateService())->summarize(
            collect([$lesson, $project]),
            collect([10])
        );

        self::assertSame(50.0, $state['progress_percentage']);
        self::assertSame('project', $state['next_section']['type']);
        self::assertSame(200, $state['next_section']['project_id']);
        self::assertNull($state['next_section']['lesson_id']);
    }

    public function test_completed_course_has_no_next_section(): void
    {
        $lesson = $this->section(10, Lesson::class, 100, 1);

        $state = (new LearningProgressStateService())->summarize(
            collect([$lesson]),
            collect([10])
        );

        self::assertTrue($state['is_completed']);
        self::assertNull($state['next_section']);
    }

    private function section(
        int $id,
        string $type,
        int $contentId,
        int $order
    ): CourseSection {
        $section = new CourseSection();
        $section->forceFill([
            'id' => $id,
            'sectionable_type' => $type,
            'sectionable_id' => $contentId,
            'title' => "Section {$id}",
            'order' => $order,
        ]);
        $section->exists = true;

        return $section;
    }
}
