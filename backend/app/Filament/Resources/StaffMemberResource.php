<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Models\StaffMember;
use App\Services\StaffRoleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** @extends resource<StaffMember> */
final class StaffMemberResource extends Resource
{
    protected static ?string $model = StaffMember::class;

    protected static ?string $modelLabel = 'صلاحيات حساب';

    protected static ?string $pluralModelLabel = 'صلاحيات الفريق';

    protected static bool $isGloballySearchable = false;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return in_array($action, ['viewAny', 'view'], true) && app(AdminPermissionMatrix::class)->isAdministrator(auth('web')->user())
            ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'roles']);
    }

    public static function assignmentAction(string $name = 'assign'): Action
    {
        return Action::make($name)->label('تعيين الصلاحيات')
            ->authorize(fn (): bool => app(AdminPermissionMatrix::class)->isAdministrator(auth('web')->user()))
            ->schema([
                Select::make('user_id')->label('الحساب')->options(fn (): array => app(StaffRoleService::class)->eligibleAccounts())->searchable()->required(),
                Select::make('role_ids')->label('الأدوار')->options(fn (): array => app(StaffRoleService::class)->roles())->multiple()->required(),
            ])->fillForm(fn (?StaffMember $record): array => $record ? ['user_id' => $record->user_id, 'role_ids' => $record->roles->modelKeys()] : [])
            ->action(function (array $data): void {
                app(StaffRoleService::class)->assign(auth('web')->user(), (int) $data['user_id'], array_map('intval', $data['role_ids']));
                Notification::make()->title('تم حفظ الصلاحيات')->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.email')->label('الحساب')->searchable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('roles.name')->label('الأدوار')->badge(),
        ])->recordActions([self::assignmentAction()])->poll('30s')->emptyStateHeading('لم تعيّن أدوارًا بعد');
    }

    public static function getPages(): array
    {
        return ['index' => StaffMemberResource\Pages\ListStaffMembers::route('/')];
    }
}
