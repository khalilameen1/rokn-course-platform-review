<?php

namespace App\Filament\Resources\TeacherResource\Pages;

use App\Filament\Resources\TeacherResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<User> */
final class ViewTeacher extends ViewRecord
{
    protected static string $resource = TeacherResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->getFilamentName();
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
