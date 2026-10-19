<?php

namespace App\Filament\Resources\CoinEarningMethodResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\CoinEarningMethodResource;
use App\Models\CoinEarningMethod;
use App\Services\AdminRewardAuthoringService;
use App\Support\RewardMethodInput;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<CoinEarningMethod> */
final class CreateCoinEarningMethod extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = CoinEarningMethodResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt(CoinEarningMethodResource::draftOperation(), CoinEarningMethod::class, $data,
            fn ($complete): CoinEarningMethod => app(AdminRewardAuthoringService::class)->createMethod(RewardMethodInput::fromLocalForm($data), $complete));
    }

    protected function getRedirectUrl(): string
    {
        return CoinEarningMethodResource::getUrl();
    }
}
