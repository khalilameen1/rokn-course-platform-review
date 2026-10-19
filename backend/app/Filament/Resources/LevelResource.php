<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Level;
use App\Services\AdminLevelAuthoringService;
use App\Services\AdminLevelReadService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** @extends DomainResource<Level> */
final class LevelResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = Level::class;

    protected static ?string $modelLabel = 'شارة';

    protected static ?string $pluralModelLabel = 'الشارات';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string $permissionRoute = 'admin.levels';

    public static function draftFields(): array
    {
        return ['name_ar', 'name_en', 'description_ar', 'description_en', 'order'];
    }

    public static function draftFileFields(): array
    {
        return ['badge_upload'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminLevelReadService::class)->listQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            self::draftRecovery(),
            TextInput::make('name_ar')->label('الاسم بالعربية')->required()->maxLength(255),
            TextInput::make('name_en')->label('الاسم بالإنجليزية')->required()->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('order')->label('الترتيب')->integer()->required()->minValue(1)->maxValue(1000)->default(1),
            ImageEntry::make('current_badge')->label('الصورة الحالية')->hiddenOn('create')->imageHeight(112)
                ->state(fn (?Level $record): ?string => $record?->badge_image_url ? url($record->badge_image_url) : null),
            FileUpload::make('badge_upload')->label('صورة الشارة')->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                ->maxSize(4096)->disk('public')->visibility('public')->storeFiles(false),
            Textarea::make('description_ar')->label('الوصف بالعربية')->maxLength(1000)->rows(3),
            Textarea::make('description_en')->label('الوصف بالإنجليزية')->maxLength(1000)->rows(3)->extraInputAttributes(['dir' => 'ltr']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('badge_image_url')->label('الشارة')->imageHeight(64)
                ->state(fn (Level $record): ?string => $record->badge_image_url ? url($record->badge_image_url) : null),
            TextColumn::make('name_ar')->label('الاسم')->searchable(['name_ar', 'name_en'])->wrap(),
            TextColumn::make('name_en')->label('بالإنجليزية')->extraAttributes(['dir' => 'ltr'])->toggleable(),
            TextColumn::make('order')->label('الترتيب')->numeric()->sortable(),
            TextColumn::make('created_at')->label('تاريخ الإضافة')->date()->toggleable(),
        ])->defaultSort('order')->poll('30s')->emptyStateHeading('لا توجد شارات')->emptyStateDescription('')
            ->recordActions([
                EditAction::make(),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->authorize(fn (Level $record): bool => self::canDelete($record))->action(fn (Level $record) => self::removeLevels(collect([$record]))),
            ])->toolbarActions([
                BulkAction::make('remove')->label('حذف الشارات المحددة')->color('danger')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->authorize(fn (): bool => self::canDeleteAny())->action(fn (Collection $records) => self::removeLevels($records)),
            ]);
    }

    private static function removeLevels(Collection $records): void
    {
        $blocked = 0;
        foreach ($records as $record) {
            $blocked += app(AdminLevelAuthoringService::class)->deleteIfUnused((int) $record->getKey()) ? 0 : 1;
        }
        Notification::make()->title($blocked ? 'بقيت شارات مرتبطة بكورسات أو طلاب' : 'تم الحذف')
            ->status($blocked ? 'warning' : 'success')->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => LevelResource\Pages\ListLevels::route('/'),
            'create' => LevelResource\Pages\CreateLevel::route('/create'),
            'edit' => LevelResource\Pages\EditLevel::route('/{record}/edit'),
        ];
    }
}
