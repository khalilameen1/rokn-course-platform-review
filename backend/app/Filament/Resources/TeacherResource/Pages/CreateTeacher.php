<?php

namespace App\Filament\Resources\TeacherResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\TeacherResource;
use App\Models\User;
use App\Services\AdminTeacherAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/** @extends CreateRecord<User> */
final class CreateTeacher extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = TeacherResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $payload = TeacherResource::profilePayload($data, true);
        $image = ($data['image'] ?? null) instanceof UploadedFile ? $data['image'] : null;

        return $this->createWithReceipt(
            'filament.teachers.store', User::class, $data,
            fn ($complete): User => app(AdminTeacherAuthoringService::class)->create($payload, (bool) ($data['active'] ?? false), $this->creationIntent, $image, $complete)
        );
    }

    protected function getRedirectUrl(): string
    {
        return TeacherResource::getUrl();
    }
}
