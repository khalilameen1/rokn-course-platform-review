<?php

namespace App\Filament\Resources\OperatingCostResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\OperatingCostResource;
use App\Models\OperatingCostPool;
use App\Services\AdminOperatingCostAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<OperatingCostPool> */
final class CreateOperatingCost extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = OperatingCostResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt(OperatingCostResource::draftOperation(), OperatingCostPool::class, $data,
            fn ($complete): OperatingCostPool => app(AdminOperatingCostAuthoringService::class)->create($data, (int) auth('web')->id(), $complete));
    }

    protected function getRedirectUrl(): string
    {
        return OperatingCostResource::getUrl();
    }
}
