<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Auth\AdminPermissionMatrix;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

final class OverviewCourseCoinsChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'العملات المصروفة على الكورسات منذ البداية';

    protected ?string $description = 'مشتراة بمال مقابل مكافآت وليست إيرادًا نقديًا';

    protected ?string $maxHeight = '280px';

    #[Locked]
    public array $courses = [];

    public static function canView(): bool
    {
        return app(AdminPermissionMatrix::class)->isAdministrator(auth('web')->user());
    }

    protected function getData(): array
    {
        abort_unless(self::canView(), 403);

        return ['labels' => array_column($this->courses, 'name'), 'datasets' => [
            ['label' => 'مشتراة', 'data' => array_column($this->courses, 'paid_coins'), 'backgroundColor' => '#2563eb'],
            ['label' => 'مكافآت', 'data' => array_column($this->courses, 'reward_coins'), 'backgroundColor' => '#94a3b8'],
        ]];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
