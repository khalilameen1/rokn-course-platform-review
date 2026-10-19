<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Services\AdminPaymentOperationsReadService;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        $orders = $records instanceof Collection ? $records : collect($records->items());
        // Filament caches the page collection. Decorate it once, never per cell.
        if ($orders->isNotEmpty() && $orders->first()->getAttribute('payment_operation_state') === null) {
            app(AdminPaymentOperationsReadService::class)->decorate($orders);
        }

        return $records;
    }

    public function exportUrl(): string
    {
        return route('admin.orders.export', $this->reportFilters());
    }

    public function reportsUrl(): string
    {
        return route('admin.orders.index', $this->reportFilters());
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
