<?php

namespace App\Filament\Resources\RewardRuleResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\RewardRuleResource;
use App\Filament\Support\FormValidation;
use App\Models\RewardRule;
use App\Services\AdminRewardAuthoringService;
use App\Support\RewardConfigurationVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<RewardRule> */
final class EditRewardRule extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = RewardRuleResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->editorVersion = RewardConfigurationVersion::rule($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $rule = $this->getRecord();
        try {
            app(AdminRewardAuthoringService::class)->updateRule($rule, $data, $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّرت المكافأة أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $rule->refresh();
        $this->editorVersion = RewardConfigurationVersion::rule($rule);

        return $rule;
    }
}
