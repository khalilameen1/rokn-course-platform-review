<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\StudentResource;
use App\Models\User;
use App\Services\AdminStudentAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/** @extends CreateRecord<User> */
final class CreateStudent extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = StudentResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt('filament.users.store', User::class, $data,
            fn ($complete): User => app(AdminStudentAuthoringService::class)->create(
                $data, $this->creationIntent, ($data['image'] ?? null) instanceof UploadedFile ? $data['image'] : null, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.users.show', $this->getRecord());
    }
}
