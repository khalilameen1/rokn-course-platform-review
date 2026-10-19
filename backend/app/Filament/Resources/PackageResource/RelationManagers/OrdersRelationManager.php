<?php

namespace App\Filament\Resources\PackageResource\RelationManagers;

use App\Filament\Concerns\DecoratesOrderTable;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PackageResource;
use App\Services\AdminPaymentOperationsReadService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class OrdersRelationManager extends RelationManager
{
    use DecoratesOrderTable;

    protected static string $relationship = 'orders';

    protected static ?string $title = 'عمليات الدفع';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PackageResource::canView($ownerRecord) && OrderResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return OrderResource::table($table)
            ->query(app(AdminPaymentOperationsReadService::class)->ordersQuery()->where('package_id', $this->getOwnerRecord()->getKey()))
            ->headerActions([])->filters([])->toolbarActions([]);
    }
}
