<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Concerns\DecoratesOrderTable;
use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    use DecoratesOrderTable;

    protected static string $resource = OrderResource::class;

    public function exportUrl(): string
    {
        return route('admin.orders.export', $this->reportFilters());
    }

    public function reportsUrl(): string
    {
        return OrderResource::getUrl('report', ['filters' => $this->reportFilters()]);
    }

    private function reportFilters(): array
    {
        return array_filter(
            ($this->getTableFilterState('criteria') ?? []) + ['search' => $this->getTableSearch()],
            fn ($value): bool => $value !== null && $value !== ''
        );
    }

    public function applyTableFilters(): void
    {
        // Filter forms are not validated by Filament's default apply action.
        $this->getTableFiltersForm()->getState();
        parent::applyTableFilters();
    }
}
