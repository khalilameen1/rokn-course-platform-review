<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AppVersionResource;
use App\Services\AppReleasePolicyService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class ReleaseReadiness extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return AppVersionResource::canViewAny();
    }

    protected function getStats(): array
    {
        $stats = [];
        foreach (app(AppReleasePolicyService::class)->launchReadiness()['channels'] as $channel => $status) {
            $stats[] = Stat::make(AppVersionResource::channels()[$channel], $status['ready'] ? 'جاهز' : 'غير جاهز')
                ->description($status['ready'] ? 'رابط التحديث جاهز' : ($status['reason'] === 'invalid_download_url' ? 'الرابط لا يطابق القناة' : 'لا يوجد إصدار نشط صالح'))
                ->color($status['ready'] ? 'success' : 'warning');
        }

        return $stats;
    }
}
