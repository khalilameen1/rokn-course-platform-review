<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Support\FormValidation;
use App\Models\AdminNotification;
use App\Services\AdminNotificationTemplateAuthoringService;
use App\Support\BusinessClock;
use App\Support\NotificationTemplateEditorVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<AdminNotification> */
final class EditNotificationTemplate extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = NotificationTemplateResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $this->editorVersion = NotificationTemplateEditorVersion::for($record);
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = BusinessClock::forDateTimeInput($record->getAttribute($field));
        }

        return array_replace($data, AdminNotification::presentationOverrides($record->system_key));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $template = $this->getRecord();
        $image = ($data['image'] ?? null) instanceof UploadedFile ? $data['image'] : null;
        try {
            app(AdminNotificationTemplateAuthoringService::class)->update((int) $template->id, $data, $this->editorVersion, $image, (bool) ($data['remove_image'] ?? false));
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّر القالب أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $template->refresh()->load('photo');
        $this->editorVersion = NotificationTemplateEditorVersion::for($template);

        return $template;
    }
}
