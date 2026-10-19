<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Support\RawJs;

trait HasThemeAwareChartAxes
{
    protected function getOptions(): RawJs
    {
        // Filament updates the chart's root text color on theme changes, while
        // Chart.js axes otherwise retain their own #666 default. Resolve ticks
        // from that same native color on every draw instead of fixing a theme.
        return RawJs::make(<<<'JS'
            {
                scales: {
                    x: { ticks: { color: (context) => context.chart.options.color } },
                    y: { ticks: { color: (context) => context.chart.options.color } },
                },
            }
            JS);
    }
}
