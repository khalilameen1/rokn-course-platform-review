<?php

namespace App\Filament\Resources\PathResource\Pages;

use App\Filament\Resources\PathResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPaths extends ListRecords
{
    protected static string $resource = PathResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
