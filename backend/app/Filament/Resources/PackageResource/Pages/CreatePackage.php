<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\PackageResource;
use App\Models\Package;
use App\Services\AdminPackageAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<Package> */
final class CreatePackage extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = PackageResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt(PackageResource::draftOperation(), Package::class, $data,
            fn ($complete): Package => app(AdminPackageAuthoringService::class)->create($data, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return PackageResource::getUrl();
    }
}
