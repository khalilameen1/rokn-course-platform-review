<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Auth\AdminPermissionMatrix;
use App\Auth\AdminSessionIdentity;
use App\Filament\Support\FormValidation;
use App\Services\AdminSelfAccountAuthoringService;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Filament's actual profile lifecycle, rate limiter, confirmation and session handling. */
final class EditStaffProfile extends EditProfile
{
    protected static ?string $title = 'بيانات دخولي';

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.admin_data', 'GET');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        parent::mount();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ['email' => $this->getUser()->getAttribute('email')];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            TextInput::make('password')->label('كلمة السر الجديدة')->password()->revealable()->autocomplete('new-password')
                ->minLength(10)->maxLength(72)->same('passwordConfirmation')->live(debounce: 500)
                ->dehydrated(fn ($state): bool => filled($state))->dehydrateStateUsing(fn (#[\SensitiveParameter] $state): string => Hash::make($state))
                ->helperText('اتركها فارغة للاحتفاظ بكلمة السر الحالية'),
            $this->getPasswordConfirmationFormComponent(),
            TextInput::make('current_password')->label('كلمة السر الحالية')->password()->autocomplete('current-password')->required()->currentPassword(guard: 'web'),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('البريد الإلكتروني')->email()->required()->maxLength(255)
            ->unique(ignoreRecord: true)->dehydrateStateUsing(fn (string $state): string => strtolower(trim($state)))->extraInputAttributes(['dir' => 'ltr']);
    }

    protected function beforeValidate(): void
    {
        abort_unless(app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.update_admin_data', 'POST'), 403);
    }

    protected function handleRecordUpdate(Model $record, #[\SensitiveParameter] array $data): Model
    {
        try {
            $staff = app(AdminSelfAccountAuthoringService::class)->change((int) $record->getKey(), $data['email'], $data['password'] ?? null, $data['current_password']);
        } catch (ValidationException $exception) {
            throw FormValidation::forRecord($exception);
        }
        auth('web')->setUser($staff);
        session()->put(AdminSessionIdentity::SESSION_KEY, app(AdminSessionIdentity::class)->fingerprint($staff));

        return $staff;
    }

    protected function afterSave(): void
    {
        $this->data['current_password'] = null;
    }
}
