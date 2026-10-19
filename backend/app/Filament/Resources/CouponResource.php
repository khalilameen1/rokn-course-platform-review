<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CouponResource\Pages\ListCoupons;
use App\Models\Coupon;
use App\Services\AdminCouponReadService;
use App\Support\BusinessClock;
use App\Support\UnicodeText;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** @extends DomainResource<Coupon> */
final class CouponResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = Coupon::class;

    protected static ?string $modelLabel = 'كود خصم';

    protected static ?string $pluralModelLabel = 'الخصومات';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string $permissionRoute = 'admin.coupons';

    public static function draftFields(): array
    {
        return ['name_ar', 'name_en', 'code', 'balance', 'course_id', 'starts_at', 'max_redemptions', 'expiry_date', 'active'];
    }

    public static function draftFileFields(): array
    {
        return ['image'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminCouponReadService::class)->listQuery();
    }

    public static function form(Schema $schema): Schema
    {
        $name = fn (string $field) => TextInput::make($field)->label($field === 'name_ar' ? 'الاسم بالعربية' : 'الاسم بالإنجليزية')
            ->required($field === 'name_ar')->minLength($field === 'name_ar' ? 2 : null)->maxLength(100)
            ->mutateStateForValidationUsing(fn ($state) => UnicodeText::clean($state, false))
            ->dehydrateStateUsing(fn ($state) => UnicodeText::clean($state, false))
            ->extraInputAttributes(['dir' => $field === 'name_ar' ? 'rtl' : 'ltr']);

        return $schema->components([
            self::draftRecovery(),
            Section::make('كود الخصم')->columns(2)->schema([
                $name('name_ar'), $name('name_en'),
                TextInput::make('code')->label('الكود')->required()->minLength(3)->maxLength(50)
                    ->mutateStateForValidationUsing(fn ($state): string => UnicodeText::identifier($state))
                    ->dehydrateStateUsing(fn ($state): string => UnicodeText::identifier($state))
                    ->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('balance')->label('نسبة الخصم')->suffix('٪')->required()->integer()->minValue(1)->maxValue(100),
                Select::make('course_id')->label('نطاق الكود')->placeholder('كل الكورسات')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(AdminCouponReadService::class)->courseOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => app(AdminCouponReadService::class)->courseLabel($value))
                    ->rules(['integer', Rule::exists('courses', 'id')]),
                TextInput::make('max_redemptions')->label('الحد الكلي للاستخدام')->placeholder('بلا حد')->integer()->minValue(1)->maxValue(10000000),
            ])->columnSpanFull(),
            Section::make('التوقيت والظهور')->columns(2)->schema([
                // This is a local wall-time contract, not a UTC timestamp for Filament to convert a second time.
                TextInput::make('starts_at')->label('يبدأ بتوقيت القاهرة')->type('datetime-local')->rules(['nullable', 'date']),
                DatePicker::make('expiry_date')->label('تاريخ الانتهاء')->required()->native()
                    ->minDate(fn (string $operation) => $operation === 'create' ? BusinessClock::now()->format('Y-m-d') : null),
                Toggle::make('active')->label('مفعّل')->default(false),
            ])->columnSpanFull(),
            Section::make('الصورة')->columns(2)->collapsible()->collapsed()->schema([
                ImageEntry::make('current_image')->label('الصورة الحالية')->hiddenOn('create')->imageHeight(112)
                    ->state(fn (?Coupon $record): ?string => $record?->photo?->assetPath()),
                FileUpload::make('image')->label('صورة الكود')->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(4096)->disk('public')->visibility('public')->storeFiles(false),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name_ar')->label('الحملة')->searchable(['name_ar', 'name_en'])->wrap()
                ->description(fn (Coupon $record): HtmlString => new HtmlString('<bdi dir="ltr">'.e(Str::limit($record->code, 14)).'</bdi>')),
            TextColumn::make('code')->label('الكود')->searchable()->copyable()->visibleFrom('md')->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('balance')->label('الخصم')->suffix('٪')->sortable(),
            TextColumn::make('scope')->label('النطاق')->wrap()->visibleFrom('md')
                ->state(fn (Coupon $record): string => $record->course->name_ar ?? ($record->getAttribute('course_id') === null ? 'كل الكورسات' : 'ارتباط قديم غير صالح')),
            TextColumn::make('redemptions_count')->label('الاستخدام')->numeric()->sortable()->visibleFrom('md')
                ->description(fn (Coupon $record): string => $record->getAttribute('max_redemptions') ? 'من '.$record->getAttribute('max_redemptions') : 'بلا حد كلي'),
            TextColumn::make('expiry_date')->label('ينتهي')->date('Y-m-d')->sortable()->visibleFrom('md'),
            IconColumn::make('active')->label('مفعّل')->boolean(),
        ])->defaultSort('id', 'desc')->defaultPaginationPageOption(25)->paginated([10, 25, 50, 100])->poll('30s')
            ->filters([TernaryFilter::make('active')->label('مفعّل')])
            ->emptyStateHeading('لا توجد خصومات')->emptyStateDescription('')->recordActions([ActionGroup::make([
                EditAction::make(),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->authorize(fn (Coupon $record): bool => self::canDelete($record))
                    ->mountUsing(fn (Coupon $record, ListCoupons $livewire) => $livewire->rememberVersion($record))
                    ->action(fn (Coupon $record, ListCoupons $livewire) => $livewire->removeCoupon($record)),
            ])->label('إجراءات الكود')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCoupons::route('/'), 'create' => CouponResource\Pages\CreateCoupon::route('/create'),
            'edit' => CouponResource\Pages\EditCoupon::route('/{record}/edit')];
    }
}
