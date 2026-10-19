<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseCodeResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\CourseCodeResource;
use App\Filament\Support\FormValidation;
use App\Models\CourseCode;
use App\Services\AdminCourseCodeAuthoringService;
use App\Support\BusinessClock;
use App\Support\CourseCodeEditorVersion;
use App\Support\CourseCodeFormData;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<CourseCode> */
final class EditCourseCode extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = CourseCodeResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $code = $this->getRecord();
        abort_unless($code->type === 'course', 403);
        $this->editorVersion = CourseCodeEditorVersion::for($code);
        foreach (['start_date', 'expiry_date'] as $field) {
            $data[$field] = BusinessClock::forDateTimeInput($code->getAttribute($field));
        }
        $data['allowed_email_domains'] = implode(', ', $code->allowed_email_domains ?? []);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $code = $this->getRecord();
        try {
            app(AdminCourseCodeAuthoringService::class)->update((int) $code->id, CourseCodeFormData::payload($data), $this->editorVersion);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['data.course_id' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّر الكود أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $code->refresh();
        $this->editorVersion = CourseCodeEditorVersion::for($code);

        return $code;
    }
}
