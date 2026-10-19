<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends ReadResource<Order> */
final class OrderResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = Order::class;

    protected static ?string $modelLabel = 'طلب';

    protected static ?string $pluralModelLabel = 'الطلبات';

    protected static ?string $recordTitleAttribute = 'order_ref';

    protected static string $legacyListRoute = 'admin.orders.index';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم الطلب')->sortable()->searchable(),
            TextColumn::make('order_ref')->label('المرجع')->searchable()->copyable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('user.name_ar')->label('الطالب')->searchable(['name_ar', 'name_en', 'email'])->wrap(),
            TextColumn::make('status')->label('الحالة')->badge()->searchable(),
            TextColumn::make('payment_method')->label('طريقة الدفع')->badge(),
            TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
        ])->filters([SelectFilter::make('status')->label('الحالة')->options([
            Order::STATUS_PENDING => 'معلق', Order::STATUS_APPROVED => 'معتمد', Order::STATUS_REJECTED => 'مرفوض',
        ])])->recordActions([Action::make('details')->label('تفاصيل الطلب')->url(fn (Order $record): string => route('admin.orders.show', $record))])
            ->recordUrl(null)
            ->defaultSort('id', 'desc')->poll('30s')->emptyStateHeading('لا توجد طلبات')->emptyStateDescription('');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'order_ref', 'user.email'];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return route('admin.orders.show', $record);
    }

    public static function getPages(): array
    {
        return ['index' => OrderResource\Pages\ListOrders::route('/')];
    }
}
