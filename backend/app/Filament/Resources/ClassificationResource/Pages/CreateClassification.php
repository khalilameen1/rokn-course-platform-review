<?php

namespace App\Filament\Resources\ClassificationResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\ClassificationResource;
use App\Models\Classification;
use App\Services\AdminClassificationAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<Classification> */
final class CreateClassification extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = ClassificationResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt('filament.classifications.store', Classification::class, $data,
            fn ($complete): Classification => app(AdminClassificationAuthoringService::class)->create(
                ClassificationResource::rowPayload($data), $data['course_ids'] ?? [], $complete));
    }

    protected function getRedirectUrl(): string
    {
        return ClassificationResource::getUrl();
    }
}
