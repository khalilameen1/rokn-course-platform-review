<?php

namespace App\Filament\Resources\RewardRuleResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\RewardRuleResource;
use App\Models\RewardRule;
use App\Services\AdminRewardAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<RewardRule> */
final class CreateRewardRule extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = RewardRuleResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->createWithReceipt(RewardRuleResource::draftOperation(), RewardRule::class, $data,
            fn ($complete): RewardRule => app(AdminRewardAuthoringService::class)->createRule($data, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return RewardRuleResource::getUrl();
    }
}
