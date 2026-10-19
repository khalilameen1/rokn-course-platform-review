<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Models\User;
use App\Support\BusinessClock;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** @extends DomainResource<User> */
final class ModeratorResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'content-staff';

    protected static ?string $modelLabel = 'مسؤول محتوى';

    protected static ?string $pluralModelLabel = 'فريق المحتوى';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 30;

    protected static string $permissionRoute = 'admin.moderators';

    protected static bool $isGloballySearchable = false;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if ($action === 'create' && ! self::canManageCredentials()) {
            return Response::deny();
        }

        return in_array($action, ['viewAny', 'view', 'create', 'update'], true) ? parent::getAuthorizationResponse($action, $record) : Response::deny();
    }

    public static function canManageCredentials(): bool
    {
        return app(AdminPermissionMatrix::class)->allowsCapability(auth('web')->user(), AdminPermissionMatrix::ACCOUNT_CREDENTIALS);
    }

    public static function profilePayload(array $data): array
    {
        if (! self::canManageCredentials()) {
            unset($data['manage_credentials'], $data['email'], $data['password'], $data['password_confirmation'], $data['phone']);
        }

        return $data;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', 'moderator');
    }

    public static function draftFields(): array
    {
        return ['name_ar', 'name_en'];
    }

    public static function form(Schema $schema): Schema
    {
        $credentials = fn (string $operation, Get $get): bool => self::canManageCredentials() && ($operation === 'create' || (bool) $get('manage_credentials'));

        return $schema->components([
            self::draftRecovery(),
            Section::make('الحساب')->columns(2)->schema([
                TextInput::make('name_ar')->label('الاسم بالعربية')->required()->maxLength(255),
                TextInput::make('name_en')->label('الاسم بالإنجليزية')->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('phone')->label('الهاتف')->tel()->maxLength(20)->unique(ignoreRecord: true)->visible(fn (): bool => self::canManageCredentials())->extraInputAttributes(['dir' => 'ltr']),
                Toggle::make('active')->label('الحساب نشط')->default(true)->required(),
            ])->columnSpanFull(),
            Toggle::make('manage_credentials')->label('تعديل بيانات الدخول')->default(false)->live()->visible(fn (string $operation): bool => $operation === 'edit' && self::canManageCredentials())->columnSpanFull(),
            Section::make('بيانات الدخول')->columns(2)->visible($credentials)->schema([
                TextInput::make('email')->label('بريد تسجيل الدخول')->email()->required()->maxLength(255)->unique(ignoreRecord: true)
                    ->mutateStateForValidationUsing(fn (?string $state): string => strtolower(trim($state ?? '')))
                    ->dehydrateStateUsing(fn (string $state): string => strtolower(trim($state)))->extraInputAttributes(['dir' => 'ltr'])->columnSpanFull(),
                TextInput::make('password')->label('كلمة السر')->password()->revealable()->autocomplete('new-password')->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(10)->maxLength(72)->same('password_confirmation'),
                TextInput::make('password_confirmation')->label('تأكيد كلمة السر')->password()->autocomplete('new-password')->requiredWith('password')->dehydrated(false),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name_ar')->label('المسؤول')->searchable(['name_ar', 'name_en', 'email'])->wrap()
                ->description(fn (User $record): string => '#'.$record->id.' · '.($record->active ? 'نشط' : 'موقوف')),
            TextColumn::make('email')->label('بريد تسجيل الدخول')->copyable()->extraAttributes(['dir' => 'ltr'])->visibleFrom('md'),
            TextColumn::make('last_dashboard_login_at')->label('آخر دخول بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->placeholder('لم يدخل بعد')->visibleFrom('md'),
        ])->recordActions([EditAction::make()])->defaultSort('id', 'desc')->poll('30s')->defaultPaginationPageOption(25)
            ->emptyStateHeading('لم تُضف حسابات محتوى بعد')->emptyStateDescription('');
    }

    public static function getPages(): array
    {
        return ['index' => ModeratorResource\Pages\ListModerators::route('/'), 'create' => ModeratorResource\Pages\CreateModerator::route('/create'),
            'edit' => ModeratorResource\Pages\EditModerator::route('/{record}/edit')];
    }
}
