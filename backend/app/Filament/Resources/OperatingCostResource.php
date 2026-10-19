<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OperatingCostResource\Pages\ListOperatingCosts;
use App\Models\OperatingCostPool;
use App\Services\AdminOperatingCostReadService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** @extends DomainResource<OperatingCostPool> */
final class OperatingCostResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'التجارة';

    protected static ?int $navigationSort = 40;

    protected static ?string $model = OperatingCostPool::class;

    protected static ?string $slug = 'operating-costs';

    protected static ?string $modelLabel = 'فاتورة تشغيل';

    protected static ?string $pluralModelLabel = 'المصروفات';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $permissionRoute = 'admin.operating-costs';

    public static function draftFields(): array
    {
        return ['name', 'service_key', 'course_id', 'period_start', 'period_end', 'amount', 'currency', 'fx_rate_to_egp', 'is_final', 'notes'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminOperatingCostReadService::class)->invoices();
    }

    public static function courseField(): Select
    {
        return Select::make('course_id')->label('نطاق الفاتورة')->placeholder('مشتركة — كل المنصة')->searchable()
            ->getSearchResultsUsing(fn (string $search): array => app(AdminOperatingCostReadService::class)->courseOptions($search))
            ->getOptionLabelUsing(fn ($value): ?string => app(AdminOperatingCostReadService::class)->courseLabel($value))
            ->rules(['integer', Rule::exists('courses', 'id')]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            self::draftRecovery(),
            Section::make('الفاتورة')->columns(2)->schema([
                TextInput::make('name')->label('اسم الفاتورة')->required()->maxLength(160),
                Select::make('service_key')->label('الخدمة')->options(OperatingCostPool::SERVICES)->required(),
                self::courseField()->helperText('اختر كورسًا فقط إذا كانت الفاتورة تخصه مباشرة'),
                Toggle::make('is_final')->label('فاتورة نهائية مؤكدة من المزود')->default(true),
                DatePicker::make('period_start')->label('بداية الفترة')->required()->native(),
                DatePicker::make('period_end')->label('نهاية الفترة')->required()->native()->afterOrEqual('period_start'),
            ])->columnSpanFull(),
            Section::make('القيمة')->columns(2)->schema([
                TextInput::make('amount')->label('المبلغ')->required()->numeric()->minValue(0)->maxValue(1000000000)->step('0.0001'),
                Select::make('currency')->label('عملة الفاتورة')->options(['EGP' => 'جنيه مصري — EGP', 'USD' => 'دولار أمريكي — USD'])->required()->default('EGP')->live(),
                TextInput::make('fx_rate_to_egp')->label('سعر الدولار لهذه الفاتورة')->suffix('ج.م')->numeric()->minValue(0.0001)->maxValue(10000)->step('0.0001')
                    ->visible(fn (Get $get): bool => $get('currency') === 'USD')->required(fn (Get $get): bool => $get('currency') === 'USD'),
                Hidden::make('allocation_driver')->default('active_students')->required()->in(array_keys(OperatingCostPool::DRIVERS)),
                Textarea::make('notes')->label('ملاحظات')->maxLength(2000)->rows(3)->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الفاتورة')->searchable()->wrap()
                ->description(fn (OperatingCostPool $record): string => OperatingCostPool::SERVICES[$record->getAttribute('service_key')] ?? $record->getAttribute('service_key')),
            TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state, OperatingCostPool $record): string => number_format((float) $state, 4).' '.$record->getAttribute('currency'))
                ->description(fn (OperatingCostPool $record): ?string => $record->getAttribute('currency') === 'USD' ? ($record->amountEgp() ? number_format($record->amountEgp()->toFloat(), 2).' ج.م' : 'ينقص سعر التحويل') : null),
            TextColumn::make('is_final')->label('الحالة')->badge()->formatStateUsing(fn ($state): string => $state ? 'نهائية' : 'غير نهائية')
                ->color(fn ($state): string => $state ? 'success' : 'warning'),
            TextColumn::make('period_end')->label('الفترة')->date('Y-m-d')->sortable()->visibleFrom('md')
                ->description(fn (OperatingCostPool $record): string => 'من '.$record->period_start->format('Y-m-d')),
            TextColumn::make('scope')->label('النطاق')->wrap()->visibleFrom('md')
                ->state(fn (OperatingCostPool $record): string => $record->course->name_ar ?? ($record->getAttribute('course_id') === null ? 'كل المنصة' : 'كورس سابق')),
        ])->defaultSort('period_end', 'desc')->defaultPaginationPageOption(30)->paginated([10, 30, 50, 100])->poll('30s')
            ->filters([
                SelectFilter::make('service_key')->label('الخدمة')->options(OperatingCostPool::SERVICES),
                TernaryFilter::make('is_final')->label('نهائية'),
                Filter::make('criteria')->schema([
                    self::courseField(),
                    DatePicker::make('from')->label('من')->native(),
                    DatePicker::make('to')->label('إلى')->native()->afterOrEqual(fn (Get $get): string => filled($get('from')) ? 'from' : ''),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['course_id'] ?? null, fn ($query, $id) => $query->where('course_id', $id))
                    ->when($data['from'] ?? null, fn ($query, $date) => $query->where('period_end', '>=', $date))
                    ->when($data['to'] ?? null, fn ($query, $date) => $query->where('period_start', '<=', $date))),
            ])->emptyStateHeading('لا توجد فواتير')->emptyStateDescription('')->recordActions([ActionGroup::make([
                EditAction::make(),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->authorize(fn (OperatingCostPool $record): bool => self::canDelete($record))
                    ->mountUsing(fn (OperatingCostPool $record, ListOperatingCosts $livewire) => $livewire->rememberVersion($record))
                    ->action(fn (OperatingCostPool $record, ListOperatingCosts $livewire) => $livewire->removeInvoice($record)),
            ])->label('إجراءات الفاتورة')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOperatingCosts::route('/'), 'create' => OperatingCostResource\Pages\CreateOperatingCost::route('/create'),
            'edit' => OperatingCostResource\Pages\EditOperatingCost::route('/{record}/edit')];
    }
}
