<?php

namespace App\Filament\Resources\CoinEarningMethodResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\CoinEarningMethodResource;
use App\Filament\Support\FormValidation;
use App\Models\CoinEarningMethod;
use App\Services\AdminRewardAuthoringService;
use App\Support\BusinessClock;
use App\Support\RewardConfigurationVersion;
use App\Support\RewardMethodInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<CoinEarningMethod> */
final class EditCoinEarningMethod extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = CoinEarningMethodResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $method = $this->getRecord();
        $this->editorVersion = RewardConfigurationVersion::method($method);
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = BusinessClock::forDateTimeInput($method->getAttribute($field));
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $method = $this->getRecord();
        try {
            app(AdminRewardAuthoringService::class)->updateMethod($method, RewardMethodInput::fromLocalForm($data), $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception, CoinEarningMethodResource::validationFieldMap());
            }
            Notification::make()->title('تغيّرت المهمة أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $method->refresh();
        $this->editorVersion = RewardConfigurationVersion::method($method);

        return $method;
    }
}
