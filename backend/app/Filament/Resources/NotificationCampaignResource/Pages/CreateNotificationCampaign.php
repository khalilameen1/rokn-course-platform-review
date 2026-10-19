<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationCampaignResource\Pages;

use App\Data\NotificationAuthoringInput;
use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Support\FormValidation;
use App\Models\NotificationCampaign;
use App\Services\AdminNotificationCampaignAuthoringService;
use App\Services\AdminNotificationCampaignReadService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends CreateRecord<NotificationCampaign> */
final class CreateNotificationCampaign extends CreateRecord
{
    use UsesAuthoringCreateReceipt { mount as private mountWithReceipt; }

    protected static string $resource = NotificationCampaignResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public ?int $targetStudentId = null;

    #[Locked]
    public bool $testingDelivery = false;

    public function mount(?int $student = null): void
    {
        abort_unless(NotificationCampaignResource::canCreate(), 403);
        if ($student !== null) {
            $this->targetStudentId = (int) app(AdminNotificationCampaignReadService::class)->student($student)->id;
        }
        $this->mountWithReceipt();
    }

    public function studentLabel(): string
    {
        if ($this->targetStudentId === null) {
            return '';
        }
        $student = app(AdminNotificationCampaignReadService::class)->student($this->targetStudentId);

        return $student->name.' — '.$student->email;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data = $this->deliveryFields($data);

        try {
            return $this->createWithReceipt(NotificationCampaignResource::draftOperation(), NotificationCampaign::class, $data,
                fn ($complete): NotificationCampaign => app(AdminNotificationCampaignAuthoringService::class)
                    ->author(NotificationAuthoringInput::fromValidated((int) auth('web')->id(), $data), $complete));
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
            throw $exception;
        }
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('testDelivery')->label('إرسال تجربة')->visible(fn (): bool => NotificationCampaignResource::allows('test'))
            ->modalDescription('نسخة واحدة لهذا الحساب الآن بعنوان يبدأ بتجربة — لا تُرسل للجمهور')
            ->schema([TextInput::make('test_email')->label('بريد حساب الطالب')->required()->email()->maxLength(255)->extraInputAttributes(['dir' => 'ltr'])])
            ->action(fn (array $data) => $this->sendTest($data['test_email']))];
    }

    public function sendTest(string $email): void
    {
        abort_unless(NotificationCampaignResource::canCreate() && NotificationCampaignResource::allows('test'), 403);
        $this->testingDelivery = true;
        try {
            $data = $this->deliveryFields($this->form->getState());
            app(AdminNotificationCampaignAuthoringService::class)->authorTest((int) auth('web')->id(), $data, $email);
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception);
            // Recipient failures are reported by the test action; content
            // failures also remain beside their fields in the main editor.
            if (isset($exception->errors()['test_email'])) {
                return;
            }
            throw FormValidation::forRecord($exception);
        } finally {
            $this->testingDelivery = false;
        }
        Notification::make()->title('أُضيفت التجربة إلى قائمة الإرسال')->success()->send();
    }

    private function deliveryFields(array $data): array
    {
        return [...$data, 'authoring_request_id' => $this->creationIntent, 'user_id' => $this->targetStudentId,
            'audience' => $this->targetStudentId !== null ? 'all' : ($data['audience'] ?? 'all')];
    }

    protected function completedAuthoringDraftUrl(string $recordId): string
    {
        return NotificationCampaignResource::getUrl('view', ['record' => $recordId]);
    }

    protected function getRedirectUrl(): string
    {
        return NotificationCampaignResource::getUrl('view', ['record' => $this->getRecord()->id]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('تأكيد الإرسال');
    }

    private function notifyRefusal(ValidationException $exception): void
    {
        // The writer can refuse a fixed recipient or a disabled delivery kind.
        // Those identities need not have an editable field on this page.
        Notification::make()->title($exception->validator->errors()->first())->danger()->send();
    }
}
