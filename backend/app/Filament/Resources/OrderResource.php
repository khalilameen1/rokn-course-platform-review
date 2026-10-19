<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Order;
use App\Services\AdminPaymentOperationsReadService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
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
        return app(AdminPaymentOperationsReadService::class)->ordersQuery();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم الطلب')->sortable()->visibleFrom('md'),
            TextColumn::make('order_ref')->label('المرجع')->copyable()->extraAttributes(['dir' => 'ltr'])->toggleable()->visibleFrom('lg'),
            TextColumn::make('user.name')->label('الطالب')->wrap()
                ->description(fn (Order $record): string => '#'.$record->id),
            TextColumn::make('user.email')->label('البريد')->visibleFrom('md')->extraAttributes(['dir' => 'ltr'])->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('product')->label('المنتج')->wrap()->visibleFrom('md')
                ->state(fn (Order $record): string => $record->course->title ?? $record->package->name_ar ?? $record->package->name_en ?? 'بدون منتج مرتبط')
                ->description(fn (Order $record): ?string => $record->courseCode?->code ? 'كود منحة '.$record->courseCode->code : null),
            TextColumn::make('display_amount')->label('الإجمالي والصافي')->state(fn (Order $record): string => self::displayAmount($record))
                ->description(fn (Order $record): ?string => self::amountDetail($record))->wrap(),
            TextColumn::make('payment_method')->label('قناة الدفع')->badge()->visibleFrom('md')
                ->formatStateUsing(fn (string $state): string => app(AdminPaymentOperationsReadService::class)->channelLabels()[$state] ?? $state)
                ->description(fn (Order $record): ?string => implode(' · ', array_filter([$record->getAttribute('provider_evidence_status'), $record->getAttribute('provider_evidence_source')])) ?: null)->wrap(),
            TextColumn::make('payment_operation_label')->label('حالة الدفع')->badge()->wrap()
                ->color(fn (Order $record): string => $record->getAttribute('payment_operation_tone') === 'muted' ? 'gray' : $record->getAttribute('payment_operation_tone'))
                ->description(fn (Order $record): ?string => implode(' · ', array_filter([
                    $record->gateway_settlement_status === 'test_purchase' ? 'اختبار خارج الإيراد' : null,
                    $record->status === Order::STATUS_APPROVED && ! $record->isFinanciallyEffective() ? $record->financialStatusLabel() : null,
                ])) ?: null),
            TextColumn::make('created_at')->label('التاريخ')->visibleFrom('md')->formatStateUsing(fn ($state): string => BusinessClock::format($state, 'Y-m-d H:i'))->sortable(),
        ])->searchable()->searchPlaceholder('رقم الطلب أو المرجع أو الطالب')
            ->searchUsing(fn (Builder $query, string $search) => app(AdminPaymentOperationsReadService::class)->applySearch($query, $search))
            ->filters([
                Filter::make('criteria')->label('تصفية الطلبات')->schema(self::filterSchema())
                    ->query(fn (Builder $query, array $data) => app(AdminPaymentOperationsReadService::class)->applyFilters($query, $data))
                    ->indicateUsing(fn (array $data): array => self::filterIndicators($data)),
            ], layout: FiltersLayout::Modal)->filtersFormColumns(2)->recordActions([ActionGroup::make([
                Action::make('receipt')->label('إيصال الدفع')->visible(fn (Order $record): bool => filled($record->payment_screenshot_url))
                    ->modalContent(fn (Order $record) => view('filament.orders.receipt', ['url' => $record->payment_screenshot_url]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('إغلاق'),
                Action::make('details')->label('تفاصيل الطلب')->url(fn (Order $record): string => route('admin.orders.show', $record)),
            ])])->headerActions([
                Action::make('export')->label('تصدير CSV')->url(fn (OrderResource\Pages\ListOrders $livewire): string => $livewire->exportUrl()),
                Action::make('reports')->label('تقارير التحصيل')->url(fn (OrderResource\Pages\ListOrders $livewire): string => $livewire->reportsUrl()),
            ])
            ->recordUrl(null)
            ->defaultSort('id', 'desc')->defaultPaginationPageOption(25)->poll('30s')->emptyStateHeading('لا توجد طلبات')->emptyStateDescription('');
    }

    public static function filterSchema(): array
    {
        return [
            Select::make('state')->label('حالة عملية الدفع')->options(AdminPaymentOperationsReadService::stateLabels()),
            Select::make('payment_method')->label('قناة الدفع')->options(fn (): array => app(AdminPaymentOperationsReadService::class)->channelLabels()),
            TextInput::make('package_id')->label('رقم حزمة المتجر')->integer()->minValue(1)->exists('packages', 'id'),
            TextInput::make('user_search')->label('اسم أو بريد أو هاتف الطالب')->maxLength(120),
            TextInput::make('course_search')->label('اسم الكورس')->maxLength(120),
            DatePicker::make('date_from')->label('من تاريخ')->native(),
            DatePicker::make('date_to')->label('إلى تاريخ')->native()->afterOrEqual(fn (Get $get): ?string => filled($get('date_from')) ? 'date_from' : null),
            TextInput::make('amount_min')->label('أقل مبلغ')->numeric()->minValue(0)->maxValue(9999999999.99),
            TextInput::make('amount_max')->label('أعلى مبلغ')->numeric()->minValue(0)->maxValue(9999999999.99)
                ->gte(fn (Get $get): ?string => filled($get('amount_min')) ? 'amount_min' : null),
        ];
    }

    /** Translate legacy/report filters once into the native table's URL contract. */
    public static function listUrl(array $filters = [], int $page = 1): string
    {
        $search = $filters['search'] ?? '';
        unset($filters['search']);

        return self::getUrl('index', ['filters' => ['criteria' => $filters], 'search' => $search, ...($page > 1 ? ['page' => $page] : [])]);
    }

    private static function filterIndicators(array $data): array
    {
        $labels = ['state' => 'الحالة', 'payment_method' => 'القناة', 'package_id' => 'الحزمة', 'user_search' => 'الطالب', 'course_search' => 'الكورس', 'date_from' => 'من', 'date_to' => 'إلى', 'amount_min' => 'أقل مبلغ', 'amount_max' => 'أعلى مبلغ'];
        $indicators = [];
        foreach (array_intersect_key($data, $labels) as $field => $value) {
            if (blank($value)) {
                continue;
            }
            $indicators[] = match ($field) {
                'state' => AdminPaymentOperationsReadService::stateLabels()[$value] ?? $labels[$field],
                'payment_method' => app(AdminPaymentOperationsReadService::class)->channelLabels()[$value] ?? $value,
                default => $labels[$field].' '.$value,
            };
        }

        return $indicators;
    }

    /** Presentation only; amounts and allocations come from the existing ledger reader. */
    private static function displayAmount(Order $order): string
    {
        if (in_array($order->payment_method, [Order::PAYMENT_METHOD_WALLET, Order::PAYMENT_METHOD_WALLET_COINS], true)) {
            return number_format((int) ($order->ledger_total_coins ?? 0)).' عملة رُكن';
        }
        $cash = in_array($order->payment_method, [Order::PAYMENT_METHOD_KASHIER, Order::PAYMENT_METHOD_GOOGLE_PLAY, Order::PAYMENT_METHOD_APP_STORE], true);

        return number_format((float) ($cash ? ($order->gateway_gross_amount ?? $order->final_amount) : $order->final_amount), 2).' '.($cash ? ($order->gateway_currency ?: 'EGP') : 'جنيه');
    }

    private static function amountDetail(Order $order): ?string
    {
        $notes = [];
        if (in_array($order->payment_method, [Order::PAYMENT_METHOD_KASHIER, Order::PAYMENT_METHOD_GOOGLE_PLAY, Order::PAYMENT_METHOD_APP_STORE], true)) {
            if ($order->gateway_gross_amount === null || $order->gateway_settlement_status === 'catalog_estimate') {
                $notes[] = 'تقدير كتالوج';
            }
            $notes[] = 'الصافي '.($order->gateway_net_amount === null ? 'بانتظار التسوية' : number_format((float) $order->gateway_net_amount, 2).' '.($order->gateway_currency ?: 'EGP'));
        }
        if (in_array($order->payment_method, [Order::PAYMENT_METHOD_WALLET, Order::PAYMENT_METHOD_WALLET_COINS], true) && ! $order->getAttribute('coin_allocation_complete')) {
            $notes[] = 'ربط الدفتر غير مكتمل';
        }
        if ($order->discount_amount > 0) {
            $notes[] = 'خصم '.number_format((float) $order->discount_amount, 2);
        }

        return implode(' · ', $notes) ?: null;
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
        return ['index' => OrderResource\Pages\ListOrders::route('/'), 'report' => OrderResource\Pages\PaymentReport::route('/report')];
    }
}
