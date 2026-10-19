<?php

namespace App\Filament\Resources\StudentProgressResource\Pages;

use App\Filament\Resources\StudentProgressResource;
use App\Models\User;
use App\Services\AdminStudentProgressReadService;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<User> */
final class ViewStudentProgress extends ViewRecord
{
    protected static string $resource = StudentProgressResource::class;

    private ?array $details = null;

    public function getTitle(): string
    {
        return 'تقدم '.$this->getRecord()->getFilamentName();
    }

    public function details(): array
    {
        return $this->details ??= app(AdminStudentProgressReadService::class)->workspace((int) $this->getRecord()->id);
    }

    public function totals(): array
    {
        $courses = $this->details()['coursesProgress'];
        $completed = $courses->sum('progress.completed_sections');
        $sections = $courses->sum('progress.total_sections');

        return ['courses' => $courses->count(), 'completed' => $completed, 'sections' => $sections,
            'percentage' => $sections > 0 ? (int) round($completed / $sections * 100) : 0];
    }

    public function courses(): array
    {
        return $this->details()['coursesProgress']->map(fn (array $course): array => [
            'title' => $course['course']?->title ?: 'كورس غير متاح', 'percentage' => $course['progress']['progress_percentage'].'%',
            'ratio' => $course['progress']['completed_sections'].' من '.$course['progress']['total_sections'],
            'enrolled_at' => $course['enrollment']->enrolled_at, 'last_activity' => $course['progress']['last_activity'],
            'types' => collect($course['progress']['sections_by_type'])->map(fn ($count, $type): string => (['lesson' => 'مقاطع', 'project' => 'مشاريع'][$type] ?? $type)
                .' '.($course['progress']['completed_by_type'][$type] ?? 0).' من '.$count)->implode(' · '),
            'sections' => $course['sections_detail']->map(fn (array $section): array => [
                ...$section, 'kind' => ['lesson' => 'مقطع', 'project' => 'مشروع'][$section['type']] ?? $section['type'],
            ])->all(),
        ])->all();
    }
}
