<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseCodeResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\CourseCodeResource;
use App\Models\CourseCode;
use App\Services\AdminCourseCodeAuthoringService;
use App\Support\CourseCodeFormData;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<CourseCode> */
final class CreateCourseCode extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = CourseCodeResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt(CourseCodeResource::draftOperation(), CourseCode::class, $data,
            fn ($complete): CourseCode => app(AdminCourseCodeAuthoringService::class)->createBatch(
                CourseCodeFormData::payload($data), (int) $data['number_of_codes'], $complete));
    }

    protected function getRedirectUrl(): string
    {
        return CourseCodeResource::getUrl();
    }
}
