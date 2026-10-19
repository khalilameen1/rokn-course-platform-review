<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Path;
use App\Services\AdminPathAuthoringService;
use App\Services\AdminPathReadService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** @extends DomainResource<Path> */
final class PathResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = Path::class;

    protected static ?string $modelLabel = 'مسار';

    protected static ?string $pluralModelLabel = 'المسارات';

    protected static ?string $recordTitleAttribute = 'title_ar';

    protected static string $permissionRoute = 'admin.paths';

    public static function draftFields(): array
    {
        return ['title_ar', 'title_en', 'interest_ids', 'course_ids'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminPathReadService::class)->listQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            self::draftRecovery(),
            TextInput::make('title_ar')->label('الاسم بالعربية')->required()->maxLength(255),
            TextInput::make('title_en')->label('الاسم بالإنجليزية')->required()->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
            Select::make('interest_ids')->label('المجالات')->multiple()->searchable()->options(fn (): array => app(AdminPathReadService::class)->interestOptions())
                ->nestedRecursiveRules(['integer', 'distinct', Rule::exists('classifications', 'id')])->default([]),
            Select::make('course_ids')->label('الكورسات')->multiple()->searchable()->options(fn (): array => app(AdminPathReadService::class)->courseOptions())
                ->maxItems(200)->nestedRecursiveRules(['integer', 'distinct', Rule::exists('courses', 'id')])->default([]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title_ar')->label('المسار')->searchable(['title_ar', 'title_en'])->wrap(),
            TextColumn::make('interests.name_ar')->label('المجالات')->badge(),
            TextColumn::make('courses_count')->label('الكورسات')->numeric()->sortable(),
        ])->defaultSort('id', 'desc')->poll('30s')->emptyStateHeading('لا توجد مسارات')->emptyStateDescription('')
            ->recordActions([
                EditAction::make(),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->authorize(fn (Path $record): bool => self::canDelete($record))
                    ->action(fn (Path $record) => self::removePaths(collect([$record]))),
            ])->toolbarActions([
                BulkAction::make('remove')->label('حذف المسارات المحددة')->color('danger')->requiresConfirmation()
                    ->authorize(fn (): bool => self::canDeleteAny())->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => self::removePaths($records)),
            ]);
    }

    private static function removePaths(Collection $records): void
    {
        $removed = 0;
        foreach ($records as $record) {
            $removed += app(AdminPathAuthoringService::class)->deleteIfUnused((int) $record->getKey()) ? 1 : 0;
        }
        $blocked = $records->count() - $removed;
        Notification::make()->title($blocked ? 'بقيت مسارات مرتبطة بكورسات' : 'تم الحذف')
            ->body($blocked ? 'انقل الكورسات إلى مسار آخر ثم أعد المحاولة' : null)
            ->status($blocked ? 'warning' : 'success')->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => PathResource\Pages\ListPaths::route('/'),
            'create' => PathResource\Pages\CreatePath::route('/create'),
            'edit' => PathResource\Pages\EditPath::route('/{record}/edit'),
        ];
    }
}
