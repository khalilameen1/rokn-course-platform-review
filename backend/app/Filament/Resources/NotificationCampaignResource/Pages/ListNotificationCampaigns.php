<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationCampaignResource\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\NotificationCampaignResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListNotificationCampaigns extends ListRecords
{
    protected static string $resource = NotificationCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('إشعار جديد')->url(route('admin.notifications.create'))->visible(fn (): bool => NotificationCampaignResource::allows('store')),
            Action::make('templates')->label('القوالب')->url(route('admin.admin_notifications.index'))
                ->visible(fn (): bool => app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.admin_notifications.index', 'GET'))];
    }
}
