<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Reactive;

final class OrderSummary extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['default' => 2, '@xl' => 3];

    protected ?string $description = 'الأرقام تخص نتائج التصفية الحالية';

    #[Reactive]
    public array $report = [];

    public static function canView(): bool
    {
        return OrderResource::canViewAny();
    }

    protected function getStats(): array
    {
        $stats = $this->report['stats'];

        return [
            Stat::make('إجمالي الطلبات', $stats['total']),
            Stat::make('مفتوحة محليًا', $stats['pending']),
            Stat::make('مسجلة كمدفوعة', $stats['approved']),
            Stat::make('فشلت أو رُفضت', $stats['rejected']),
            Stat::make('مغلقة', $stats['cancelled']),
            Stat::make('التحصيل المؤكد بالجنيه', number_format($stats['total_amount'], 2))
                ->description('دون الاختبارات والعمليات المرتجعة والتقديرات'),
        ];
    }
}
