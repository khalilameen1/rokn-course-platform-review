<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\StudentResource;
use App\Services\AdminTodayReadService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class TodayStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return app(AdminPermissionMatrix::class)->isAdministrator(auth('web')->user());
    }

    protected function getStats(): array
    {
        $today = app(AdminTodayReadService::class)->read();

        return [
            Stat::make('مبيعات اليوم المؤكدة', number_format($today['gross'], 2).' جنيه')->url(route('admin.orders.index')),
            Stat::make('تسجيلات اليوم', $today['registrations'])->url(StudentResource::getUrl()),
            Stat::make('بلاغات مفتوحة', $today['open_feedback'])->url(route('admin.feedback.index')),
            Stat::make('مهام فاشلة', $today['failed_jobs'])->color($today['failed_jobs'] > 0 ? 'danger' : 'success')->url(route('admin.product-operations.index')),
        ];
    }
}
