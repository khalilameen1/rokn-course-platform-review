<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\OperatingCostResource;
use App\Filament\Resources\OperatingCostResource\Pages\ListOperatingCosts;
use App\Services\AdminOperatingCostReadService;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class OperatingCostSummary extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected ?string $description = 'الأرقام تخص نتائج التصفية الحالية';

    public static function canView(): bool
    {
        return OperatingCostResource::canViewAny();
    }

    protected function getTablePage(): string
    {
        return ListOperatingCosts::class;
    }

    protected function getStats(): array
    {
        $summary = app(AdminOperatingCostReadService::class)->summary($this->getPageTableQuery());

        return [
            Stat::make('فواتير نهائية بالجنيه', number_format($summary['actual_egp'], 2)),
            Stat::make('غير نهائية — مستبعدة', number_format($summary['estimated_egp'], 2)),
            Stat::make('فواتير ينقصها سعر التحويل', $summary['missing_fx']),
        ];
    }
}
