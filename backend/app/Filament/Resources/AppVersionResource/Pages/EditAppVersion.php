<?php

namespace App\Filament\Resources\AppVersionResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\AppVersionResource;
use App\Filament\Support\FormValidation;
use App\Models\AppVersion;
use App\Services\AppReleaseAuthoringService;
use App\Support\AppVersionEditorVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<AppVersion> */
final class EditAppVersion extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = AppVersionResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->editorVersion = AppVersionEditorVersion::for($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $version = $this->getRecord();
        try {
            app(AppReleaseAuthoringService::class)->update((int) $version->id, AppVersionResource::authoringData($data), $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّر الإصدار أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $version->refresh();
        $this->editorVersion = AppVersionEditorVersion::for($version);

        return $version;
    }
}
