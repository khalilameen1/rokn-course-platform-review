<?php

namespace App\Filament\Resources\ModeratorResource\Pages;

use App\Filament\Resources\ModeratorResource;
use App\Filament\Resources\StaffMemberResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListModerators extends ListRecords
{
    protected static string $resource = ModeratorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), Action::make('roles')->label('صلاحيات الفريق')->url(StaffMemberResource::getUrl())->visible(fn (): bool => StaffMemberResource::canViewAny())];
    }
}
