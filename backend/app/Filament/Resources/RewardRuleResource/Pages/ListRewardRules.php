<?php

namespace App\Filament\Resources\RewardRuleResource\Pages;

use App\Filament\Resources\RewardRuleResource;
use App\Models\RewardRule;
use App\Services\AdminRewardAuthoringService;
use App\Support\RewardConfigurationVersion;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListRewardRules extends ListRecords
{
    protected static string $resource = RewardRuleResource::class;

    #[Locked]
    public ?string $pendingVersion = null;

    #[Locked]
    public ?int $pendingRecord = null;

    public function rememberVersion(RewardRule $record): void
    {
        $this->pendingRecord = (int) $record->id;
        $this->pendingVersion = RewardConfigurationVersion::rule($record);
    }

    public function removeRule(RewardRule $record): void
    {
        abort_unless(RewardRuleResource::canDelete($record), 403);
        abort_unless($this->pendingRecord === (int) $record->id && $this->pendingVersion !== null, 409);
        try {
            app(AdminRewardAuthoringService::class)->deleteRule($record, $this->pendingVersion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر حذف المكافأة')->warning()->send();

            return;
        }
        Notification::make()->title('تم الحذف')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
