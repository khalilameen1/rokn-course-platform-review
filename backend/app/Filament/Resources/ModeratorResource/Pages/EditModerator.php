<?php

namespace App\Filament\Resources\ModeratorResource\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\ModeratorResource;
use App\Filament\Support\FormValidation;
use App\Models\User;
use App\Services\AdminMfaResetService;
use App\Services\AdminModeratorAuthoringService;
use App\Support\ModeratorEditorVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<User> */
final class EditModerator extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = ModeratorResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->editorVersion = ModeratorEditorVersion::for($this->getRecord());

        return ModeratorResource::profilePayload($this->getRecord()->only(['name_ar', 'name_en', 'email', 'phone', 'active']) + ['manage_credentials' => false]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(AdminModeratorAuthoringService::class)->update((int) $record->getKey(), ModeratorResource::profilePayload($data), $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّرت بيانات مسؤول المحتوى')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $staff = $this->getRecord()->refresh();
        $this->editorVersion = ModeratorEditorVersion::for($staff);
        $this->data['password'] = null;
        $this->data['password_confirmation'] = null;
        $this->data['manage_credentials'] = false;

        return $staff;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('resetMfa')->label('إعادة ضبط التحقق بخطوتين')->color('danger')->authorize(fn (): bool => $this->canResetMfa())
            ->visible(fn (): bool => $this->getRecord()->admin_totp_confirmed_at !== null)
            ->requiresConfirmation()->modalDescription('استخدمه بعد التحقق من هوية المسؤول إذا فقد تطبيق المصادقة ورموز الاسترداد')
            ->schema([TextInput::make('confirm_email')->label('اكتب بريد المسؤول للتأكيد')->required()
                ->helperText(fn (): string => (string) $this->getRecord()->email)
                ->mutateStateForValidationUsing(fn (?string $state): string => strtolower(trim($state ?? '')))
                ->rules(fn (): array => [Rule::in([strtolower(trim((string) $this->getRecord()->email))])])->extraInputAttributes(['dir' => 'ltr'])])
            ->action(function (): void {
                abort_unless($this->canResetMfa(), 403);
                app(AdminMfaResetService::class)->reset($this->getRecord());
                $this->getRecord()->refresh();
                Notification::make()->title('سيُطلب إعداد التحقق بخطوتين عند الدخول التالي')->success()->send();
            })];
    }

    private function canResetMfa(): bool
    {
        return (int) $this->getRecord()->getKey() !== (int) auth('web')->id()
            && ModeratorResource::canManageCredentials()
            && app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.moderators.mfa-reset', 'POST');
    }
}
