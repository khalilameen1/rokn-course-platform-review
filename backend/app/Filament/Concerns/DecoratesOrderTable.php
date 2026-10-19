<?php

namespace App\Filament\Concerns;

use App\Services\AdminPaymentOperationsReadService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

/** One page-level financial projection, shared by lists and nested read-only tables. */
trait DecoratesOrderTable
{
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        $orders = $records instanceof Collection ? $records : collect($records->items());
        if ($orders->isNotEmpty() && $orders->first()->getAttribute('payment_operation_state') === null) {
            app(AdminPaymentOperationsReadService::class)->decorate($orders);
        }

        return $records;
    }
}
