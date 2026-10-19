<?php

namespace App\Filament\Resources\PathResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\PathResource;
use App\Models\Path;
use App\Services\AdminPathAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<Path> */
final class CreatePath extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = PathResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt(
            'filament.paths.store', Path::class, $data,
            fn ($complete): Path => app(AdminPathAuthoringService::class)->create($data, $complete)
        );
    }

    protected function getRedirectUrl(): string
    {
        return PathResource::getUrl();
    }
}
