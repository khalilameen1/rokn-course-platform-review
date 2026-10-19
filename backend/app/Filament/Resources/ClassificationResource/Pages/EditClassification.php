<?php

namespace App\Filament\Resources\ClassificationResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\ClassificationResource;
use App\Filament\Support\FormValidation;
use App\Models\Classification;
use App\Services\AdminClassificationAuthoringService;
use App\Services\AdminClassificationReadService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<Classification> */
final class EditClassification extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = ClassificationResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $row = $this->getRecord();
        $reader = app(AdminClassificationReadService::class);
        $this->editorVersion = $reader->editorVersion($row);

        return $row->only(['name_ar', 'name_en', 'show_on_home', 'home_order']) + ['course_ids' => $reader->visibleCanonicalCourseIds($row)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $row = $this->getRecord();
        try {
            app(AdminClassificationAuthoringService::class)->update($row, ClassificationResource::rowPayload($data), $data['course_ids'] ?? [], $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغير الصف أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $row->refresh();
        $this->editorVersion = app(AdminClassificationReadService::class)->editorVersion($row);

        return $row;
    }
}
