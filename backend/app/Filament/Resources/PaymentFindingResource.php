<?php

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\PaymentFindingResource\Pages\ListPaymentFindings;
use App\Models\PaymentReconciliationFinding;
use App\Services\AdminPaymentFindingReadService;
use App\Support\BusinessClock;
use App\Support\PaymentFindingEditorVersion;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends ReadResource<PaymentReconciliationFinding> */
final class PaymentFindingResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = PaymentReconciliationFinding::class;

    protected static ?string $modelLabel = 'نتيجة مطابقة';

    protected static ?string $pluralModelLabel = 'مطابقة المدفوعات';

    protected static string|\UnitEnum|null $navigationGroup = 'التجارة';

    protected static ?int $navigationSort = 15;

    protected static string $legacyListRoute = 'admin.payment-reconciliation-findings.index';

    public static function getEloquentQuery(): Builder
    {
        return app(AdminPaymentFindingReadService::class)->query();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_ref')->label('الطلب')->wrap()->extraAttributes(['dir' => 'ltr'])
                ->url(fn (PaymentReconciliationFinding $record): ?string => $record->order ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null)
                ->description(fn (PaymentReconciliationFinding $record): string => $record->provider.' · '.($record->provider_transaction_id ?: 'بلا رقم معاملة')),
            TextColumn::make('kind')->label('النتيجة')->wrap()->formatStateUsing(fn (string $state): string => AdminPaymentFindingReadService::KINDS[$state] ?? $state)
                ->description(fn (PaymentReconciliationFinding $record): string => AdminPaymentFindingReadService::NEXT_STEPS[$record->kind] ?? 'راجع الطلب ودليل المزود قبل تسجيل القرار'),
            TextColumn::make('state')->label('المراجعة')->badge()->formatStateUsing(fn (string $state): string => AdminPaymentFindingReadService::STATES[$state] ?? $state)
                ->color(fn (string $state): string => ['open' => 'warning', 'resolved' => 'success'][$state] ?? 'gray'),
            TextColumn::make('attempts')->label('المحاولات')->numeric()->visibleFrom('md'),
            TextColumn::make('last_seen_at')->label('آخر رصد بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->visibleFrom('md'),
            TextColumn::make('resolver.name')->label('آخر قرار بواسطة')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ])->searchable()->searchPlaceholder('مرجع الطلب')->searchUsing(fn (Builder $query, string $search): Builder => app(AdminPaymentFindingReadService::class)->search($query, $search))
            ->filters([
                SelectFilter::make('state')->label('حالة المراجعة')->options(AdminPaymentFindingReadService::STATES)->default('open'),
                SelectFilter::make('kind')->label('نوع النتيجة')->options(fn (): array => app(AdminPaymentFindingReadService::class)->kinds()),
            ])->recordActions([ActionGroup::make([
                Action::make('evidence')->label('تفاصيل المطابقة')->stickyModalHeader()->stickyModalFooter()->schema([
                    ...collect(['local_status' => 'حالة الطلب', 'local_financial_status' => 'الحالة المالية', 'provider_status' => 'حالة المزود', 'provider_transaction_id' => 'مرجع المزود',
                        'attempts' => 'المحاولات', 'resolution_note' => 'سبب آخر قرار', 'resolver.name' => 'صاحب القرار'])
                        ->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label)
                            ->visible(fn (TextEntry $component): bool => filled($component->getState())))->values()->all(),
                    TextEntry::make('first_seen_at')->label('أول رصد بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName()),
                    TextEntry::make('last_seen_at')->label('آخر رصد بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName()),
                    TextEntry::make('resolved_at')->label('تاريخ القرار بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())
                        ->visible(fn (PaymentReconciliationFinding $record): bool => $record->resolved_at !== null),
                    TextEntry::make('evidence')->label('دليل المطابقة')->state(fn (PaymentReconciliationFinding $record): string => json_encode($record->evidence ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                        ->extraAttributes(['dir' => 'ltr', 'class' => 'whitespace-pre-wrap break-all']),
                ])->modalSubmitAction(false)->modalCancelActionLabel('إغلاق'),
                self::decision('resolve', 'توثيق الحل', PaymentReconciliationFinding::STATE_RESOLVED),
                self::decision('ignore', 'تجاهل بسبب موثق', PaymentReconciliationFinding::STATE_IGNORED),
                self::decision('reopen', 'إعادة فتح المراجعة', PaymentReconciliationFinding::STATE_OPEN),
            ])->label('إجراءات المطابقة')])->recordUrl(null)->poll('30s')->defaultPaginationPageOption(25)
            ->emptyStateHeading('لا توجد نتائج مطابقة')->emptyStateDescription('');
    }

    private static function decision(string $name, string $label, string $state): Action
    {
        return Action::make($name)->label($label)->authorize(fn (): bool => self::allows($name))
            ->visible(fn (PaymentReconciliationFinding $record): bool => $state === PaymentReconciliationFinding::STATE_OPEN ? $record->state !== PaymentReconciliationFinding::STATE_OPEN : $record->state === PaymentReconciliationFinding::STATE_OPEN)
            ->requiresConfirmation()->modalDescription('يوثق هذا القرار المراجعة فقط — لا يغيّر الطلب أو الرصيد')->modalSubmitActionLabel('حفظ القرار')
            ->schema([Textarea::make('note')->label('سبب القرار')->required()->minLength(3)->maxLength(2000)->rows(3)])
            ->mountUsing(function (?Schema $schema, PaymentReconciliationFinding $record, ListPaymentFindings $livewire): void {
                $livewire->findingId = $record->id;
                $livewire->findingVersion = PaymentFindingEditorVersion::for($record);
                $schema?->fill();
            })->action(fn (Action $action, PaymentReconciliationFinding $record, ListPaymentFindings $livewire, array $data) => $livewire->decide($action, $record, $name, $data));
    }

    public static function allows(string $action): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.payment-reconciliation-findings.'.$action, 'PATCH');
    }

    public static function listUrl(array $filters = []): string
    {
        return self::getUrl('index', ['search' => $filters['order_ref'] ?? '', 'filters' => ['state' => ['value' => $filters['state'] ?? ''], 'kind' => ['value' => $filters['kind'] ?? '']]]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentFindings::route('/')];
    }
}
