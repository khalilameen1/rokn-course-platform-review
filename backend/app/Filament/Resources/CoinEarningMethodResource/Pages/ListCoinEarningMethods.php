<?php

namespace App\Filament\Resources\CoinEarningMethodResource\Pages;

use App\Filament\Resources\CoinEarningMethodResource;
use App\Models\CoinEarningMethod;
use App\Services\AdminRewardAuthoringService;
use App\Support\RewardConfigurationVersion;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListCoinEarningMethods extends ListRecords
{
    protected static string $resource = CoinEarningMethodResource::class;

    #[Locked]
    public ?string $pendingVersion = null;

    #[Locked]
    public ?int $pendingRecord = null;

    public function rememberVersion(CoinEarningMethod $record): void
    {
        $this->pendingRecord = (int) $record->id;
        $this->pendingVersion = RewardConfigurationVersion::method($record);
    }

    public function removeMethod(CoinEarningMethod $record): void
    {
        abort_unless(CoinEarningMethodResource::canDelete($record), 403);
        abort_unless($this->pendingRecord === (int) $record->id && $this->pendingVersion !== null, 409);
        try {
            app(AdminRewardAuthoringService::class)->deleteMethod($record, $this->pendingVersion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر حذف المهمة')->warning()->send();

            return;
        }
        Notification::make()->title('تم الحذف')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
