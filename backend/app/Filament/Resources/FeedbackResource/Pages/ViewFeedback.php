<?php

namespace App\Filament\Resources\FeedbackResource\Pages;

use App\Filament\Resources\FeedbackResource;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Rules\SupportAssignee;
use App\Services\AdminSupportCaseReadService;
use App\Services\PortfolioModerationService;
use App\Services\SupportCaseCompensationService;
use App\Services\SupportCaseService;
use App\Support\BusinessClock;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** @extends ViewRecord<FeedbackReport> */
final class ViewFeedback extends ViewRecord
{
    protected static string $resource = FeedbackResource::class;

    #[Locked]
    public int $caseVersion;

    #[Locked]
    public string $messageRequestId;

    private ?array $details = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->caseVersion = (int) $this->getRecord()->getAttribute('version');
        $this->messageRequestId = (string) Str::uuid();
    }

    public function getTitle(): string
    {
        return 'حالة '.strtoupper(substr($this->getRecord()->public_id, -8));
    }

    public function details(): array
    {
        return $this->details ??= app(AdminSupportCaseReadService::class)->show($this->getRecord());
    }

    public function compensableCoins(): int
    {
        return $this->details()['compensableCoins'];
    }

    public function portfolioOwner(): ?User
    {
        return $this->details()['portfolioOwner'];
    }

    public function conversation(): array
    {
        $feedback = $this->details()['feedback'];
        $messages = $feedback->messages->map(fn ($message): array => [
            'kind' => ($message->visibility === 'internal' ? 'ملاحظة داخلية' : ($message->author_type === 'learner' ? 'الطالب' : 'فريق الدعم'))
                .($message->author_type === 'staff' && filled($message->author?->name) ? ' — '.$message->author->name : ''),
            'sent_at' => BusinessClock::format($message->created_at), 'body' => $message->body,
            'attachments' => $message->attachments->map(fn (FeedbackAttachment $attachment): array => $this->attachment($attachment))->all(),
        ])->all();

        if (! filled($feedback->client_request_id) || ! $feedback->messages->contains('client_request_id', $feedback->client_request_id)) {
            array_unshift($messages, ['kind' => 'البلاغ الأصلي', 'body' => $feedback->message, 'sent_at' => BusinessClock::format($feedback->created_at), 'attachments' => []]);
        }

        return $messages;
    }

    public function originalAttachments(): array
    {
        return $this->details()['feedback']->attachments->whereNull('support_case_message_id')
            ->map(fn (FeedbackAttachment $attachment): array => $this->attachment($attachment))->values()->all();
    }

    private function attachment(FeedbackAttachment $attachment): array
    {
        return ['url' => $attachment->getAttribute('is_available') ? route('admin.feedback.attachment', [$this->getRecord(), $attachment]) : null];
    }

    public function history(): array
    {
        $labels = ['created' => 'استلام البلاغ', 'updated' => 'تحديث الحالة', 'staff_replied' => 'رد فريق الدعم', 'internal_note' => 'ملاحظة داخلية',
            'learner_replied' => 'رد الطالب', 'reopened' => 'إعادة فتح الحالة', 'claimed' => 'ربط الحالة بالحساب',
            'compensated' => 'تعويض', 'portfolio_sharing_suspended' => 'إيقاف مشاركة الأعمال', 'portfolio_sharing_restored' => 'رفع إيقاف المشاركة'];

        return $this->details()['feedback']->events->map(fn ($event): array => [
            'event' => $labels[$event->event_type] ?? $event->event_type, 'actor' => $event->actor?->name ?: 'النظام',
            'at' => BusinessClock::format($event->created_at),
        ])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('message')->label('الرد أو إضافة ملاحظة')->authorize(fn (): bool => FeedbackResource::allows('message'))
                ->schema([
                    Select::make('visibility')->label('نوع الرسالة')->options(['customer' => 'رد يراه الطالب', 'internal' => 'ملاحظة داخلية للفريق'])->required()->default('customer'),
                    Textarea::make('message')->label('الرسالة')->required()->minLength(2)->maxLength(4000)->rows(5),
                ])->modalSubmitActionLabel('حفظ الرسالة')
                ->action(function (Action $action, array $data): void {
                    $this->perform($action, 'message', fn () => app(SupportCaseService::class)->appendStaffMessage($this->getRecord(), auth('web')->user(),
                        trim($data['message']), $data['visibility'], $this->messageRequestId, $this->caseVersion));
                    $this->messageRequestId = (string) Str::uuid();
                }),
            Action::make('state')->label('إدارة الحالة')->authorize(fn (): bool => FeedbackResource::allows('update', 'PATCH'))
                ->fillForm(fn (): array => $this->getRecord()->only(['status', 'priority', 'assigned_to', 'resolution_kind']))
                ->schema([
                    Select::make('status')->label('الحالة')->options(FeedbackResource::STATUSES)->required(),
                    Select::make('priority')->label('الأولوية')->options(FeedbackResource::PRIORITIES)->required(),
                    Select::make('assigned_to')->label('المسؤول')->placeholder('غير مسند')->searchable()
                        ->options(fn (): array => app(AdminSupportCaseReadService::class)->assignees()->mapWithKeys(fn ($user): array => [$user->id => $user->getFilamentName()])->all())
                        ->rules(['integer', new SupportAssignee]),
                    Select::make('resolution_kind')->label('نتيجة المعالجة')->options(FeedbackResource::RESOLUTIONS),
                ])->modalSubmitActionLabel('حفظ الحالة')
                ->action(fn (Action $action, array $data) => $this->perform($action, 'update', fn () => app(SupportCaseService::class)->updateState(
                    $this->getRecord(), [...$data, 'version' => $this->caseVersion], (int) auth('web')->id()), 'PATCH')),
            Action::make('compensate')->label('تعويض الطالب')->color('warning')->authorize(fn (): bool => FeedbackResource::allows('compensate'))
                ->visible(fn (): bool => $this->compensableCoins() > 0)->requiresConfirmation()
                ->modalDescription('تُضاف العملات إلى محفظة الطالب فور التأكيد')
                ->schema([
                    TextInput::make('amount')->label('عدد عملات التعويض')->required()->integer()->minValue(1)
                        ->maxValue(fn (): int => min(100000000, $this->compensableCoins()))
                        ->helperText(fn (): string => 'الحد الأقصى المتاح للتعويض '.$this->compensableCoins()),
                    Textarea::make('note')->label('سبب التعويض')->required()->minLength(8)->maxLength(1000),
                    Checkbox::make('confirm_credit')->label('أؤكد إضافة التعويض إلى محفظة الطالب')->accepted(),
                ])->modalSubmitActionLabel('تأكيد التعويض')
                ->action(fn (Action $action, array $data) => $this->perform($action, 'compensate', function () use ($data): void {
                    try {
                        app(SupportCaseCompensationService::class)->compensate($this->getRecord(), [...$data, 'version' => $this->caseVersion], (int) auth('web')->id());
                    } catch (\DomainException|\InvalidArgumentException) {
                        throw ValidationException::withMessages(['amount' => 'راجع قيمة التعويض والحد المتاح لهذا الطلب']);
                    }
                })),
            Action::make('portfolioSharing')->label(fn (): string => $this->portfolioOwner()?->portfolio_sharing_suspended_at ? 'رفع إيقاف المشاركة' : 'إيقاف مشاركة الأعمال')
                ->color('danger')->authorize(fn (): bool => FeedbackResource::allows('portfolio-sharing'))->visible(fn (): bool => $this->portfolioOwner() !== null)
                ->requiresConfirmation()->modalDescription('الإيقاف يخفي الأعمال عن الآخرين دون حذفها — رفعه لا يعتمد محتوى جديدًا')
                ->action(fn (Action $action) => $this->perform($action, 'portfolio-sharing', fn () => app(PortfolioModerationService::class)->setSharingSuspended(
                    $this->getRecord(), (int) auth('web')->id(), $this->portfolioOwner()?->portfolio_sharing_suspended_at === null))),
        ];
    }

    private function perform(Action $action, string $route, Closure $operation, string $method = 'POST'): void
    {
        abort_unless(FeedbackResource::allows($route, $method), 403);
        try {
            $operation();
        } catch (ValidationException $exception) {
            $statePath = $this->getMountedActionSchema(mountedAction: $action)?->getStatePath();
            if ($statePath === null) {
                throw $exception;
            }
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$statePath.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }
            Notification::make()->title($exception->getStatusCode() === 409 ? 'تغيّرت الحالة — أعد تحميلها قبل تنفيذ الإجراء' : 'تعذر تنفيذ الإجراء لهذه الحالة')->warning()->send();
            throw new Halt;
        }
        $this->getRecord()->refresh();
        $this->caseVersion = (int) $this->getRecord()->getAttribute('version');
        $this->details = null;
        Notification::make()->title('تم حفظ الإجراء')->success()->send();
    }
}
