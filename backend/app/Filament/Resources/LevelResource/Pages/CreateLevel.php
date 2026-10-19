<?php

namespace App\Filament\Resources\LevelResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\LevelResource;
use App\Models\Level;
use App\Services\AdminLevelAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/** @extends CreateRecord<Level> */
final class CreateLevel extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = LevelResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $image = ($data['badge_upload'] ?? null) instanceof UploadedFile ? $data['badge_upload'] : null;

        return $this->createWithReceipt('filament.levels.store', Level::class, $data,
            fn ($complete): Level => app(AdminLevelAuthoringService::class)->create($data, $this->creationIntent, $image, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return LevelResource::getUrl();
    }
}
