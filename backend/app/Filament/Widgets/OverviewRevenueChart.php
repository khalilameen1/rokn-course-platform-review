<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Concerns\HasThemeAwareChartAxes;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

final class OverviewRevenueChart extends ChartWidget
{
    use HasThemeAwareChartAxes;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'تحصيل الفترة حسب الشهر';

    protected ?string $description = 'التحصيل المؤكد فقط بكل قنوات الدفع بالجنيه';

    protected ?string $maxHeight = '280px';

    #[Locked]
    public array $months = [];

    public static function canView(): bool
    {
        return app(AdminPermissionMatrix::class)->allowsCapability(auth('web')->user(), AdminPermissionMatrix::FINANCIAL_REPORTS);
    }

    protected function getData(): array
    {
        abort_unless(self::canView(), 403);

        return ['labels' => array_column($this->months, 'month'), 'datasets' => [
            ['label' => 'تحصيل مؤكد بالجنيه', 'data' => array_column($this->months, 'course_revenue')],
        ]];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
