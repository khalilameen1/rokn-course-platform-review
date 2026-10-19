<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Classification;
use App\Services\AdminClassificationAuthoringService;
use App\Services\AdminClassificationReadService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** @extends DomainResource<Classification> */
final class ClassificationResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = Classification::class;

    protected static ?string $modelLabel = 'صف';

    protected static ?string $pluralModelLabel = 'صفوف الرئيسية';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string $permissionRoute = 'admin.classifications';

    public static function getEloquentQuery(): Builder
    {
        return app(AdminClassificationReadService::class)->listQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name_ar')->label('الاسم بالعربية')->required()->maxLength(255),
            TextInput::make('name_en')->label('الاسم بالإنجليزية')->required()->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('home_order')->label('الترتيب')->integer()->required()->minValue(0)->maxValue(10000)->default(0),
            Toggle::make('show_on_home')->label('يظهر في الرئيسية')->default(true),
            Select::make('course_ids')->label('الكورسات')->multiple()->searchable()->columnSpanFull()->maxItems(500)->default([])
                ->options(fn (): array => app(AdminClassificationReadService::class)->homeCourseOptions()->pluck('name_ar', 'id')->all())
                ->nestedRecursiveRules(['integer', 'distinct', Rule::exists('courses', 'id')]),
        ]);
    }

    public static function rowPayload(array $data): array
    {
        return array_intersect_key($data, array_flip(['name_ar', 'name_en', 'home_order', 'show_on_home']));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name_ar')->label('الصف')->searchable(['name_ar', 'name_en'])->wrap(),
            TextColumn::make('home_order')->label('الترتيب')->numeric()->sortable(),
            TextColumn::make('home_courses_count')->label('الكورسات الظاهرة')->numeric()->sortable(),
            TextColumn::make('show_on_home')->label('الحالة')->badge()->formatStateUsing(fn ($state): string => $state ? 'ظاهر' : 'مخفي')
                ->color(fn ($state): string => $state ? 'success' : 'gray'),
        ])->defaultSort('home_order')->poll('30s')->filters([TernaryFilter::make('show_on_home')->label('يظهر في الرئيسية')])
            ->emptyStateHeading('لا توجد صفوف')->emptyStateDescription('')->recordActions([
                EditAction::make(),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->authorize(fn (Classification $record): bool => self::canDelete($record))->action(fn (Classification $record) => self::removeRows(collect([$record]))),
            ])->toolbarActions([
                BulkAction::make('remove')->label('حذف الصفوف المحددة')->color('danger')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->authorize(fn (): bool => self::canDeleteAny())->action(fn (Collection $records) => self::removeRows($records)),
            ]);
    }

    private static function removeRows(Collection $records): void
    {
        $blocked = 0;
        foreach ($records as $record) {
            $blocked += app(AdminClassificationAuthoringService::class)->delete($record) ? 0 : 1;
        }
        Notification::make()->title($blocked ? 'بقيت صفوف مرتبطة بكورسات' : 'تم الحذف')
            ->body($blocked ? 'انقل الكورسات إلى صف آخر قبل حذفها' : null)->status($blocked ? 'warning' : 'success')->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ClassificationResource\Pages\ListClassifications::route('/'),
            'create' => ClassificationResource\Pages\CreateClassification::route('/create'),
            'edit' => ClassificationResource\Pages\EditClassification::route('/{record}/edit'),
        ];
    }
}
