<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Filament\Resources\StudentResource\RelationManagers;
use App\Filament\Resources\StudentResource\StudentProfileSchema;
use App\Models\User;
use App\Support\UnicodeText;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Component;
use UnitEnum;

/** @extends ReadResource<User> */
final class StudentResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'الطلبة';

    protected static ?int $navigationSort = 10;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'students';

    protected static ?string $modelLabel = 'طالب';

    protected static ?string $pluralModelLabel = 'الطلبة';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string $legacyListRoute = 'admin.users.index';

    public static function allowsRoute(string $route, string $method): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), $route, $method);
    }

    public static function allows(string $action, string $method = 'POST'): bool
    {
        return self::allowsRoute('admin.users.'.$action, $method);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StudentProfileSchema::configure($schema);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\NotesRelationManager::class, RelationManagers\OrdersRelationManager::class, RelationManagers\ProjectsRelationManager::class];
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (in_array($action, ['create', 'update'], true)) {
            $allowed = auth('web')->user()?->active && app(AdminPermissionMatrix::class)->allows(auth('web')->user(),
                'admin.users.'.($action === 'create' ? 'store' : 'update'), $action === 'create' ? 'POST' : 'PATCH');

            return $allowed && ($record === null || $record->getAttribute('role') === 'client') ? Response::allow() : Response::deny();
        }

        return parent::getAuthorizationResponse($action, $record);
    }

    // Student identity/contact fields are not persisted as browser drafts.
    public static function draftFields(): array
    {
        return [];
    }

    public static function draftFileFields(): array
    {
        return [];
    }

    public static function draftOperation(): string
    {
        return 'filament.users.store';
    }

    public static function validationFieldMap(): array
    {
        return [];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('الاسم')->required()->minLength(2)->maxLength(255)
                ->mutateStateForValidationUsing(fn ($state): string => UnicodeText::clean($state, false))
                ->dehydrateStateUsing(fn ($state): string => UnicodeText::clean($state, false))->columnSpanFull(),
            TextInput::make('email')->label('البريد')->required()->email()->maxLength(255)->extraInputAttributes(['dir' => 'ltr'])
                ->mutateStateForValidationUsing(fn (string $state): string => strtolower(trim($state)))
                ->rules(fn (?User $record, Component $livewire): array => [Rule::unique('users', 'email')->ignore(self::profileIdentity($record, $livewire))])
                ->dehydrateStateUsing(fn (string $state): string => strtolower(trim($state))),
            TextInput::make('phone')->label('الهاتف')->required()->maxLength(30)->extraInputAttributes(['dir' => 'ltr'])
                ->mutateStateForValidationUsing(fn ($state): string => UnicodeText::identifier($state))
                ->dehydrateStateUsing(fn ($state): string => UnicodeText::identifier($state))
                ->rules(fn (?User $record, Component $livewire): array => [Rule::unique('users', 'phone')->ignore(self::profileIdentity($record, $livewire))]),
            FileUpload::make('image')->label('الصورة')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(4096)->storeFiles(false)->visibleOn('create')->columnSpanFull(),
        ]);
    }

    private static function profileIdentity(?User $record, Component $livewire): ?int
    {
        if ($record !== null) {
            return $record->id;
        }

        return $livewire instanceof CreateStudent
            ? User::withTrashed()->where('authoring_request_id', $livewire->creationIntent)->value('id') : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->students();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم الطالب')->sortable()->searchable(),
            TextColumn::make('name_ar')->label('الاسم')->searchable(['name_ar', 'name_en', 'name'])->placeholder('بدون اسم')->wrap()
                ->url(fn (User $record): string => self::getUrl('view', ['record' => $record->id])),
            TextColumn::make('email')->label('البريد')->searchable()->copyable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('phone')->label('الهاتف')->searchable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('active')->label('الحالة')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'موقوف')->color(fn (bool $state): string => $state ? 'success' : 'danger'),
            TextColumn::make('created_at')->label('تاريخ التسجيل')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
        ])->filters([TernaryFilter::make('active')->label('نشط')])
            ->recordActions([Action::make('workspace')->label('ملف الطالب')->url(fn (User $record): string => self::getUrl('view', ['record' => $record->id]))])
            ->recordUrl(null)
            ->defaultSort('id', 'desc')->poll('30s')->emptyStateHeading('لا يوجد طلبة')->emptyStateDescription('');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'name_ar', 'name_en', 'name', 'email', 'phone'];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return self::getUrl('view', ['record' => $record->getKey()]);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['البريد' => (string) $record->getAttribute('email'), 'رقم الطالب' => (string) $record->getKey()];
    }

    public static function getPages(): array
    {
        return ['index' => StudentResource\Pages\ListStudents::route('/'),
            'create' => CreateStudent::route('/create'),
            'view' => ViewStudent::route('/{record}'),
            'edit' => StudentResource\Pages\EditStudent::route('/{record}/edit')];
    }
}
