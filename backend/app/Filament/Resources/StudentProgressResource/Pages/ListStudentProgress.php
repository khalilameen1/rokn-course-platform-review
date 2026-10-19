<?php

namespace App\Filament\Resources\StudentProgressResource\Pages;

use App\Filament\Resources\StudentProgressResource;
use App\Services\StudentProgressSummaryService;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

final class ListStudentProgress extends ListRecords
{
    protected static string $resource = StudentProgressResource::class;

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        $users = $records instanceof Collection ? $records : collect($records->items());
        if ($users->isNotEmpty() && $users->first()->getAttribute('learning_progress') === null) {
            $courseId = $this->getTableFilterState('course_id')['value'] ?? null;
            $summaries = app(StudentProgressSummaryService::class)->latestForUsers($users, filled($courseId) ? (int) $courseId : null);
            foreach ($users as $user) {
                $user->setAttribute('learning_progress', $summaries->get($user->id));
            }
        }

        return $records;
    }
}
