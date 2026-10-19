<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\PackageResource;
use App\Filament\Support\FormValidation;
use App\Models\Package;
use App\Services\AdminPackageAuthoringService;
use App\Support\PackageEditorVersion;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<Package> */
final class EditPackage extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = PackageResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->editorVersion = PackageEditorVersion::for($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $package = $this->getRecord();
        try {
            app(AdminPackageAuthoringService::class)->update((int) $package->id, $data, $this->editorVersion);
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->warning()->send();
            throw new Halt;
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception, PackageResource::validationFieldMap());
            }
            Notification::make()->title('تغيّرت الحزمة أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $package->refresh();
        $this->editorVersion = PackageEditorVersion::for($package);

        return $package;
    }
}
