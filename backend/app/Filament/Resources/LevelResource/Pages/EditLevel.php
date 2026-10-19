<?php

namespace App\Filament\Resources\LevelResource\Pages;

use App\Filament\Resources\LevelResource;
use App\Filament\Support\FormValidation;
use App\Models\Level;
use App\Services\AdminLevelAuthoringService;
use App\Support\LevelEditorVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<Level> */
final class EditLevel extends EditRecord
{
    protected static string $resource = LevelResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $level = $this->getRecord();
        $this->editorVersion = LevelEditorVersion::for($level);

        return $level->only(['name_ar', 'name_en', 'description_ar', 'description_en', 'order']);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $level = $this->getRecord();
        $image = ($data['badge_upload'] ?? null) instanceof UploadedFile ? $data['badge_upload'] : null;
        try {
            app(AdminLevelAuthoringService::class)->update((int) $level->id, $data, $this->editorVersion, $image);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيرت الشارة أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $level->refresh()->load('photo');
        $this->editorVersion = LevelEditorVersion::for($level);

        return $level;
    }
}
