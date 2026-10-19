<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DeviceSessions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Reactive;

final class DeviceSessionSummary extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $description = 'كل الجلسات النشطة حاليًا — لا يغيّرها بحث الجدول';

    #[Reactive]
    public array $summary = [];

    public static function canView(): bool
    {
        return DeviceSessions::canAccess();
    }

    protected function getStats(): array
    {
        $stats = [Stat::make('كل الأجهزة', array_sum($this->summary))];
        foreach (['android' => 'Android', 'ios' => 'iOS'] as $key => $label) {
            $stats[] = Stat::make($label, $this->summary[$key] ?? 0);
        }

        return $stats;
    }
}
