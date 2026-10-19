<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Filament\Support\FormValidation;
use App\Models\User;
use App\Services\AdminStudentAuthoringService;
use App\Support\StudentEditorVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<User> */
final class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->editorVersion = StudentEditorVersion::for($this->getRecord());

        return $this->getRecord()->only(['name', 'email', 'phone']);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(AdminStudentAuthoringService::class)->update((int) $record->getKey(), $data, $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title($exception->errors()['editor_version'][0])->warning()->send();
            throw new Halt;
        }
        $this->getRecord()->refresh();
        $this->editorVersion = StudentEditorVersion::for($this->getRecord());

        return $this->getRecord();
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.users.show', $this->getRecord());
    }
}
