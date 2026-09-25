<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseSection;
use App\Models\StudentSectionProgress;
use Illuminate\Support\Collection;

/** Pure projection of an already-entitled section sequence and lineage-resolved progress. */
final class SectionProgressSummary
{
    /**
     * @param Collection<int, CourseSection> $sections
     * @param Collection<int, StudentSectionProgress> $progress
     * @return array<string, mixed>
     */
    public static function for(Collection $sections, Collection $progress): array
    {
        $sectionIds = $sections->pluck('id')->flip();
        $progress = $progress->filter(fn ($row): bool => $sectionIds->has((int) $row->course_section_id));
        $completedIds = $progress->where('is_completed', true)->pluck('course_section_id')
            ->map(fn ($id): int => (int) $id)->unique()->flip();
        $sectionsByType = [];
        $completedByType = [];
        foreach ($sections as $section) {
            $type = $section->getSectionType();
            $sectionsByType[$type] = ($sectionsByType[$type] ?? 0) + 1;
            $completedByType[$type] = ($completedByType[$type] ?? 0)
                + ($completedIds->has((int) $section->id) ? 1 : 0);
        }
        $total = $sections->count();
        $completed = $completedIds->count();

        return [
            'total_sections' => $total,
            'completed_sections' => $completed,
            'progress_percentage' => $total > 0 ? min(100, (int) round(($completed / $total) * 100)) : 0,
            'sections_by_type' => $sectionsByType,
            'completed_by_type' => $completedByType,
            'last_activity' => $progress->map(fn ($row) => $row->completed_at ?? $row->updated_at)->filter()->max(),
        ];
    }
}
