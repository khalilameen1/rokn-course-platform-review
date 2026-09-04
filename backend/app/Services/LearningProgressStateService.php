<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseSection;
use Illuminate\Support\Collection;

final class LearningProgressStateService
{
    /** @return array<string,mixed> */
    public function summarize(
        Collection $learningSections,
        Collection $completedSectionIds
    ): array {
        $completed = $completedSectionIds
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->flip();
        $sectionIds = $learningSections
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $total = $sectionIds->count();
        $completedCount = $sectionIds->filter(
            fn (int $id): bool => $completed->has($id)
        )->count();
        $next = $learningSections->first(
            fn (CourseSection $section): bool => !$completed->has((int) $section->id)
        );

        return [
            'total_sections' => $total,
            'completed_sections' => $completedCount,
            'progress_percentage' => $total > 0
                ? round(($completedCount / $total) * 100, 2)
                : 0.0,
            'is_completed' => $total > 0 && $completedCount === $total,
            'next_section' => $next ? $this->nextSection($next) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function nextSection(CourseSection $section): array
    {
        $type = $section->getSectionType();
        $contentId = (int) $section->sectionable_id;

        return [
            'course_section_id' => (int) $section->id,
            'id' => $contentId,
            'type' => $type,
            'lesson_id' => $type === 'lesson' ? $contentId : null,
            'project_id' => $type === 'project' ? $contentId : null,
            'title' => (string) $section->title,
            'module_id' => $section->module_id ? (int) $section->module_id : null,
            'order' => (int) $section->order,
        ];
    }
}
