<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactResource\Pages;

use App\Filament\Resources\ContactResource;
use App\Models\Contact;
use App\Models\User;
use App\Services\AdminContactWorkflowService;
use App\Services\ContactAccountLookupService;
use App\Support\ContactEditorVersion;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends ViewRecord<Contact> */
final class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    #[Locked]
    public string $editorVersion;

    private bool $accountLookedUp = false;

    private ?User $account = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->editorVersion = ContactEditorVersion::for($this->getRecord());
    }

    public function getTitle(): string
    {
        return $this->getRecord()->isAccountDeletionRequest() ? 'طلب حذف حساب' : 'رسالة تواصل';
    }

    private function matchedAccount(): ?User
    {
        if (! $this->accountLookedUp) {
            $this->account = app(ContactAccountLookupService::class)->forEmail($this->getRecord()->email);
            $this->accountLookedUp = true;
        }

        return $this->account;
    }

    public function matchedAccountLabel(): string
    {
        $user = $this->matchedAccount();

        return $user ? $user->name.' — رقم '.$user->id : 'لا يوجد حساب نشط مطابق للبريد حاليًا';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markRead')->label('تحديد كمقروءة')->authorize(fn (): bool => ContactResource::allows('read'))
                ->visible(fn (): bool => ! $this->getRecord()->read)
                ->action(fn (Action $action) => $this->perform($action, 'read', fn ($workflow) => $workflow->markRead((int) $this->getRecord()->id, $this->editorVersion))),
            Action::make('reply')->label('الرد عبر البريد')->visible(fn (): bool => ! $this->getRecord()->isAccountDeletionRequest())
                ->url(fn (): string => 'mailto:'.$this->getRecord()->email),
            Action::make('startProcessing')->label('بدء المعالجة')->requiresConfirmation()
                ->authorize(fn (): bool => ContactResource::allows('processing'))
                ->visible(fn (): bool => $this->getRecord()->isAccountDeletionRequest() && ! $this->getRecord()->isResolved() && ! $this->getRecord()->isProcessing())
                ->action(fn (Action $action) => $this->perform($action, 'processing', fn ($workflow) => $workflow->markProcessing((int) $this->getRecord()->id, $this->editorVersion, (int) auth('web')->id()))),
            Action::make('closeRequest')->label('إغلاق الطلب')->authorize(fn (): bool => ContactResource::allows('close-deletion-request'))
                ->visible(fn (): bool => $this->getRecord()->isProcessing() && ! $this->getRecord()->isResolved())
                ->schema([
                    Select::make('outcome')->label('نتيجة المعالجة')->options(ContactResource::OUTCOMES)->required(),
                    Textarea::make('resolution_note')->label('ملاحظة داخلية')->maxLength(500),
                    Checkbox::make('confirm_close')->label('راجعت الطلب واخترت النتيجة الصحيحة')->accepted(),
                ])->modalSubmitActionLabel('حفظ النتيجة وإغلاق الطلب')
                ->action(fn (Action $action, array $data) => $this->perform($action, 'close-deletion-request', fn ($workflow) => $workflow->closeDeletionRequest(
                    (int) $this->getRecord()->id, [...$data, 'editor_version' => $this->editorVersion], (int) auth('web')->id()))),
            Action::make('deleteAccount')->label('حذف الحساب بعد التحقق')->color('danger')
                ->authorize(fn (): bool => ContactResource::allows('execute-account-deletion'))
                ->visible(fn (): bool => $this->getRecord()->isAccountDeletionRequest() && $this->getRecord()->isProcessing() && ! $this->getRecord()->isResolved() && $this->matchedAccount() !== null)
                ->modalDescription('حذف نهائي للحساب المطابق بعد التحقق من صاحبه — ينهي جلساته ويبدأ تنظيف ملفاته')
                ->schema([
                    TextInput::make('account_email')->label('بريد الحساب للتأكيد')->required()->maxLength(255)->extraInputAttributes(['dir' => 'ltr', 'autocomplete' => 'off']),
                    Textarea::make('verification_note')->label('كيف تحققت من صاحب الحساب')->required()->minLength(8)->maxLength(500),
                    Checkbox::make('confirm_identity')->label('تحققت من أن مقدم الطلب صاحب الحساب')->accepted(),
                    Checkbox::make('confirm_delete')->label('أؤكد حذف الحساب نهائيًا وإنهاء جلساته')->accepted(),
                ])->modalSubmitActionLabel('حذف الحساب نهائيًا')
                ->action(function (Action $action, array $data): void {
                    $pending = false;
                    $this->perform($action, 'execute-account-deletion', function ($workflow) use ($data, &$pending): void {
                        $pending = $workflow->executeVerifiedDeletion((int) $this->getRecord()->id, [...$data, 'editor_version' => $this->editorVersion], (int) auth('web')->id());
                    }, false);
                    Notification::make()->title($pending ? 'تم إغلاق الحساب وبدأ تنظيف ملفاته' : 'تم حذف الحساب وبياناته الشخصية')->success()->send();
                }),
            Action::make('deleteMessage')->label('حذف الرسالة')->color('danger')->requiresConfirmation()
                ->authorize(fn (): bool => ContactResource::allows('destroy', 'DELETE'))
                ->visible(fn (): bool => ! $this->getRecord()->isAccountDeletionRequest())
                ->action(function (Action $action): void {
                    $this->perform($action, 'destroy', fn ($workflow) => $workflow->deleteMessage((int) $this->getRecord()->id, $this->editorVersion), false, false);
                    Notification::make()->title('تم حذف الرسالة')->success()->send();
                    $this->redirect(ContactResource::getUrl());
                }),
        ];
    }

    private function perform(Action $action, string $route, Closure $operation, bool $notify = true, bool $refresh = true): void
    {
        abort_unless(ContactResource::allows($route, $route === 'destroy' ? 'DELETE' : 'POST'), 403);
        try {
            $operation(app(AdminContactWorkflowService::class));
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['editor_version'])) {
                Notification::make()->title($exception->errors()['editor_version'][0])->warning()->send();
                throw new Halt;
            }
            $schema = $this->getMountedActionSchema(mountedAction: $action);
            if ($schema === null) {
                throw $exception;
            }
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$schema->getStatePath().'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        if ($refresh) {
            $this->getRecord()->refresh()->load(['resolver', 'resolvedUser']);
            $this->editorVersion = ContactEditorVersion::for($this->getRecord());
            $this->accountLookedUp = false;
            $this->account = null;
        }
        if ($notify) {
            Notification::make()->title('تم حفظ الإجراء')->success()->send();
        }
    }
}
