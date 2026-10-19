<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Filament\Concerns\DecoratesOrderTable;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\StudentResource;
use App\Services\AdminPaymentOperationsReadService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class OrdersRelationManager extends RelationManager
{
    use DecoratesOrderTable;

    protected static string $relationship = 'orders';

    protected static ?string $title = 'المشتريات';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return StudentResource::canView($ownerRecord) && OrderResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return OrderResource::table($table)
            ->query(app(AdminPaymentOperationsReadService::class)->ordersQuery()->where('user_id', $this->getOwnerRecord()->getKey()))
            ->headerActions([])->filters([])->toolbarActions([]);
    }
}
