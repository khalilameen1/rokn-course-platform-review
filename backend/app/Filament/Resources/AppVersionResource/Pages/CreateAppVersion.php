<?php

namespace App\Filament\Resources\AppVersionResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\AppVersionResource;
use App\Models\AppVersion;
use App\Services\AppReleaseAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<AppVersion> */
final class CreateAppVersion extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = AppVersionResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $data = AppVersionResource::authoringData($data);

        return $this->createWithReceipt(AppVersionResource::draftOperation(), AppVersion::class, $data,
            fn ($complete): AppVersion => app(AppReleaseAuthoringService::class)->create($data, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return AppVersionResource::getUrl();
    }
}
