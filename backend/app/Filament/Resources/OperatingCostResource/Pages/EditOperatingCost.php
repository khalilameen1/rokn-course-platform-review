<?php

namespace App\Filament\Resources\OperatingCostResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\OperatingCostResource;
use App\Filament\Support\FormValidation;
use App\Models\OperatingCostPool;
use App\Services\AdminOperatingCostAuthoringService;
use App\Support\OperatingCostEditorVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<OperatingCostPool> */
final class EditOperatingCost extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = OperatingCostResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->editorVersion = OperatingCostEditorVersion::for($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $invoice = $this->getRecord();
        try {
            app(AdminOperatingCostAuthoringService::class)->update((int) $invoice->id, $data, $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّرت الفاتورة أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $invoice->refresh();
        $this->editorVersion = OperatingCostEditorVersion::for($invoice);

        return $invoice;
    }
}
