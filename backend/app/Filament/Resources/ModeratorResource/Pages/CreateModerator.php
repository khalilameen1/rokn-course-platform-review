<?php

namespace App\Filament\Resources\ModeratorResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\ModeratorResource;
use App\Models\User;
use App\Services\AdminModeratorAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<User> */
final class CreateModerator extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = ModeratorResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt('filament.moderators.store', User::class, $data,
            fn ($complete): User => app(AdminModeratorAuthoringService::class)->create($data, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return ModeratorResource::getUrl();
    }
}
