<?php

namespace App\Filament\Resources\PathResource\Pages;

use App\Filament\Resources\PathResource;
use App\Filament\Support\FormValidation;
use App\Models\Path;
use App\Services\AdminPathAuthoringService;
use App\Services\AdminPathReadService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<Path> */
final class EditPath extends EditRecord
{
    protected static string $resource = PathResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $path = $this->getRecord();
        $reader = app(AdminPathReadService::class);
        $this->editorVersion = $reader->editorVersion($path);

        return [
            'title_ar' => $path->title_ar, 'title_en' => $path->title_en,
            'interest_ids' => $path->interests->modelKeys(),
            'course_ids' => $reader->selectedCourseIds((int) $path->id),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $path = $this->getRecord();
        try {
            app(AdminPathAuthoringService::class)->update((int) $path->getKey(), $data, $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغير المسار أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $path->refresh();
        $this->editorVersion = app(AdminPathReadService::class)->editorVersion($path);

        return $path;
    }
}
