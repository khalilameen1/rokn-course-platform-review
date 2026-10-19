<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Models\Package;
use App\Services\AdminEconomyReadService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends DomainResource<Package> */
final class PackageResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'التجارة';

    protected static ?int $navigationSort = 20;

    protected static ?string $model = Package::class;

    protected static ?string $modelLabel = 'حزمة متجر';

    protected static ?string $pluralModelLabel = 'حزم المتاجر';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string $permissionRoute = 'admin.packages';

    public static function validationFieldMap(): array
    {
        return ['channels' => 'is_active'];
    }

    public static function draftFields(): array
    {
        return ['name_ar', 'name_en', 'price', 'coins', 'sort_order', 'is_active', 'direct_enabled',
            'google_enabled', 'google_product_id', 'apple_enabled', 'apple_product_id'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminEconomyReadService::class)->packagesQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            self::draftRecovery(),
            Section::make('الحزمة')->columns(2)->schema([
                TextInput::make('name_ar')->label('الاسم بالعربية')->required()->maxLength(255),
                TextInput::make('name_en')->label('الاسم بالإنجليزية')->required()->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('price')->label('سعر كاشير بالجنيه')->required()->numeric()->minValue(0.01),
                TextInput::make('coins')->label('عدد عملات رُكن')->required()->integer()->minValue(1)
                    ->disabled(fn (?Package $record): bool => filled($record?->getAttribute('google_product_id')) || filled($record?->getAttribute('apple_product_id')))->dehydrated(),
                TextInput::make('sort_order')->label('الترتيب')->integer()->minValue(0)->maxValue(10000)->required()->default(100),
                Toggle::make('is_active')->label('ظاهرة للشراء')->default(false),
            ])->columnSpanFull(),
            Section::make('قنوات الشراء')->columns(2)->schema([
                Toggle::make('direct_enabled')->label('كاشير')->default(false)->columnSpanFull(),
                Toggle::make('google_enabled')->label('Google Play')->default(false)->live(),
                TextInput::make('google_product_id')->label('معرّف منتج Google Play')->maxLength(191)->regex('/^[a-z0-9._]+$/')
                    ->required(fn (Get $get): bool => (bool) $get('google_enabled'))->unique(ignoreRecord: true)
                    ->disabled(fn (?Package $record): bool => filled($record?->getAttribute('google_product_id')))->dehydrated()->extraInputAttributes(['dir' => 'ltr']),
                Toggle::make('apple_enabled')->label('App Store')->default(false)->live(),
                TextInput::make('apple_product_id')->label('معرّف منتج App Store')->maxLength(191)->regex('/^[A-Za-z0-9._-]+$/')
                    ->required(fn (Get $get): bool => (bool) $get('apple_enabled'))->unique(ignoreRecord: true)
                    ->disabled(fn (?Package $record): bool => filled($record?->getAttribute('apple_product_id')))->dehydrated()->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('الحزمة')->columns(2)->schema([
            TextEntry::make('name_ar')->label('الاسم بالعربية'), TextEntry::make('name_en')->label('الاسم بالإنجليزية'),
            TextEntry::make('price')->label('سعر كاشير بالجنيه')->numeric(decimalPlaces: 2),
            TextEntry::make('coins')->label('عدد عملات رُكن')->numeric(),
            IconEntry::make('is_active')->label('ظاهرة للشراء')->boolean(),
            IconEntry::make('direct_enabled')->label('كاشير')->boolean(),
            IconEntry::make('google_enabled')->label('Google Play')->boolean(),
            TextEntry::make('google_product_id')->label('معرّف Google Play')->placeholder('غير مربوط')->copyable(),
            IconEntry::make('apple_enabled')->label('App Store')->boolean(),
            TextEntry::make('apple_product_id')->label('معرّف App Store')->placeholder('غير مربوط')->copyable(),
        ])->columnSpanFull()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name_ar')->label('الحزمة')->searchable(['name_ar', 'name_en'])->wrap(),
            TextColumn::make('price')->label('سعر كاشير')->money('EGP')->sortable(),
            TextColumn::make('coins')->label('عملات رُكن')->numeric()->sortable(),
            IconColumn::make('is_active')->label('ظاهرة')->boolean(),
            TextColumn::make('channels')->label('القنوات')->badge()->visibleFrom('md')
                ->state(fn (Package $record): array => array_values(array_intersect_key(['direct' => 'كاشير', 'google' => 'Google Play', 'apple' => 'App Store'], array_filter($record->availableChannels())))),
            TextColumn::make('orders_count')->label('الطلبات')->numeric()->sortable()->visibleFrom('md')
                ->url(fn (Package $record): string => OrderResource::listUrl(['package_id' => $record->id])),
        ])->defaultSort('sort_order')->defaultPaginationPageOption(30)->paginated([10, 30, 50, 100])
            ->poll('30s')->filters([TernaryFilter::make('is_active')->label('ظاهرة للشراء')])
            ->emptyStateHeading('لا توجد حزم')->emptyStateDescription('')->recordActions([
                ViewAction::make(), EditAction::make(),
                Action::make('report')->label('تقرير التحصيل')->visible(fn (): bool => OrderResource::canViewAny())
                    ->url(fn (Package $record): string => OrderResource::getUrl('report', ['filters' => ['package_id' => $record->id]])),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()->authorize(fn (Package $record): bool => self::canDelete($record))
                    ->mountUsing(fn (Package $record, ListPackages $livewire) => $livewire->rememberVersion($record))
                    ->action(fn (Package $record, ListPackages $livewire) => $livewire->removePackage($record)),
            ]);
    }

    public static function getRelations(): array
    {
        return [PackageResource\RelationManagers\OrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPackages::route('/'),
            'create' => PackageResource\Pages\CreatePackage::route('/create'),
            'view' => PackageResource\Pages\ViewPackage::route('/{record}'),
            'edit' => PackageResource\Pages\EditPackage::route('/{record}/edit')];
    }
}
