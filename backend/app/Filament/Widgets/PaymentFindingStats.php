<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PaymentFindingResource;
use App\Services\AdminPaymentFindingReadService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class PaymentFindingStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected int|array|null $columns = ['default' => 2, '@xl' => 4];

    public static function canView(): bool
    {
        return PaymentFindingResource::canViewAny();
    }

    protected function getStats(): array
    {
        $counts = app(AdminPaymentFindingReadService::class)->stateCounts();
        $stats = [];
        foreach (AdminPaymentFindingReadService::STATES as $state => $label) {
            $stats[] = Stat::make($label, number_format($counts[$state] ?? 0))->url(PaymentFindingResource::listUrl(['state' => $state]));
        }
        $stats[] = Stat::make('إجمالي النتائج', number_format(array_sum($counts)))->url(PaymentFindingResource::listUrl());

        return $stats;
    }
}
