<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends ReadResource<User> */
final class StudentResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'students';

    protected static ?string $modelLabel = 'طالب';

    protected static ?string $pluralModelLabel = 'الطلبة';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string $legacyListRoute = 'admin.users.index';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->students();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم الطالب')->sortable()->searchable(),
            TextColumn::make('name_ar')->label('الاسم')->searchable(['name_ar', 'name_en', 'name'])->placeholder('بدون اسم')->wrap()
                ->url(fn (User $record): string => route('admin.users.show', $record)),
            TextColumn::make('email')->label('البريد')->searchable()->copyable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('phone')->label('الهاتف')->searchable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('active')->label('الحالة')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'موقوف')->color(fn (bool $state): string => $state ? 'success' : 'danger'),
            TextColumn::make('created_at')->label('تاريخ التسجيل')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
        ])->filters([TernaryFilter::make('active')->label('نشط')])
            ->recordActions([Action::make('workspace')->label('ملف الطالب')->url(fn (User $record): string => route('admin.users.show', $record))])
            ->recordUrl(null)
            ->defaultSort('id', 'desc')->poll('30s')->emptyStateHeading('لا يوجد طلبة')->emptyStateDescription('');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'name_ar', 'name_en', 'name', 'email', 'phone'];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return route('admin.users.show', $record);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['البريد' => (string) $record->getAttribute('email'), 'رقم الطالب' => (string) $record->getKey()];
    }

    public static function getPages(): array
    {
        return ['index' => StudentResource\Pages\ListStudents::route('/')];
    }
}
