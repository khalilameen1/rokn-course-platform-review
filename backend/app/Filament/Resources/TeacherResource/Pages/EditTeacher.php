<?php

namespace App\Filament\Resources\TeacherResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\TeacherResource;
use App\Filament\Support\FormValidation;
use App\Models\User;
use App\Services\AdminTeacherAuthoringService;
use App\Services\AdminTeacherReadService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<User> */
final class EditTeacher extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = TeacherResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $editor = app(AdminTeacherReadService::class)->editor((int) $this->getRecord()->id);
        $teacher = $editor['teacher'];
        $this->editorVersion = $editor['editorVersion'];
        $values = $teacher->only(['name_ar', 'name_en', 'job_title', 'bio_ar', 'bio_en', 'active']);
        $values['image'] = $teacher->photo?->path;
        if (TeacherResource::canManageCredentials()) {
            $values += $teacher->only(['email', 'phone']);
        }

        return $values;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $teacher = $this->getRecord();
        $image = ($data['image'] ?? null) instanceof UploadedFile ? $data['image'] : null;
        try {
            app(AdminTeacherAuthoringService::class)->update((int) $teacher->id,
                TeacherResource::profilePayload($data, false), (bool) ($data['active'] ?? false), $this->editorVersion, $image);
        } catch (ValidationException $exception) {
            // Cross-field and concurrent-write failures need a visible message, not a hidden input error.
            if (! isset($exception->errors()['editor_version']) && ! isset($exception->errors()['active'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title((string) collect($exception->errors())->flatten()->first())->warning()->send();
            throw new Halt;
        }
        $teacher->refresh();
        $this->editorVersion = app(AdminTeacherReadService::class)->editor((int) $teacher->id)['editorVersion'];

        return $teacher;
    }
}
