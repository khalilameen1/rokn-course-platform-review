<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationCampaignResource\Pages;

use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Support\NotificationCampaignAction;
use App\Models\NotificationCampaign;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<NotificationCampaign> */
final class ViewNotificationCampaign extends ViewRecord
{
    protected static string $resource = NotificationCampaignResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title_ar;
    }

    protected function getHeaderActions(): array
    {
        return [NotificationCampaignAction::make('cancel'), NotificationCampaignAction::make('retry')];
    }
}
