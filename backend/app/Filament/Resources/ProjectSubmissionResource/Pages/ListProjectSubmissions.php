<?php

namespace App\Filament\Resources\ProjectSubmissionResource\Pages;

use App\Filament\Resources\ProjectSubmissionResource;
use App\Filament\Widgets\ProjectSubmissionStats;
use Filament\Resources\Pages\ListRecords;

final class ListProjectSubmissions extends ListRecords
{
    protected static string $resource = ProjectSubmissionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ProjectSubmissionStats::class];
    }
}
