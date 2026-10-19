<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Models\User;
use App\Services\AdminStudentReadService;
use App\Services\DeviceLoginService;
use App\Services\StudentAccountStateService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends ViewRecord<User> */
final class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    #[Locked]
    public string $accountVersion;

    #[Locked]
    public string $deviceVersion;

    #[Locked]
    public string $devicePolicy;

    #[Locked]
    public bool $expectedActive;

    private ?array $summary = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->captureVersions();
    }

    public function getTitle(): string
    {
        return $this->getRecord()->getFilamentName();
    }

    public function summary(): array
    {
        return $this->summary ??= app(AdminStudentReadService::class)->profile($this->getRecord());
    }

    private function captureVersions(): void
    {
        $data = $this->summary();
        $this->accountVersion = $data['accountStateVersion'];
        $this->deviceVersion = $data['deviceStateVersion'];
        $this->devicePolicy = $data['deviceLoginPolicy'];
        $this->expectedActive = (bool) $this->getRecord()->active;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('تعديل البيانات'),
            Action::make('setActive')->label(fn (): string => $this->expectedActive ? 'تعطيل الحساب' : 'تفعيل الحساب')
                ->color(fn (): string => $this->expectedActive ? 'danger' : 'success')->requiresConfirmation()
                ->authorize(fn (): bool => StudentResource::allows('deactive', 'PATCH'))
                ->modalDescription(fn (): string => $this->expectedActive ? 'يُنهي جلسات الطالب الحالية دون حذف حسابه' : 'يتيح للطالب تسجيل الدخول مجددًا')
                ->action(fn () => $this->perform('deactive', fn ($accounts) => $accounts->setActive(
                    $this->getRecord(), $this->expectedActive, $this->accountVersion, ! $this->expectedActive), 'PATCH')),
            Action::make('resetDevice')->label('إعادة تعيين الجهاز')->requiresConfirmation()
                ->authorize(fn (): bool => StudentResource::allows('reset-device'))
                ->visible(fn (): bool => $this->devicePolicy === DeviceLoginService::POLICY_SINGLE_PERMANENT && filled($this->getRecord()->locked_device_id))
                ->modalDescription('ينهي الجلسات الحالية ويتيح تسجيل الدخول من جهاز جديد')
                ->action(fn () => $this->perform('reset-device', fn ($accounts) => $accounts->resetDevice($this->getRecord(), $this->devicePolicy, $this->deviceVersion))),
            Action::make('notify')->label('إرسال إشعار')->visible(fn (): bool => StudentResource::allowsRoute('admin.notifications.store', 'POST'))
                ->url(fn (): string => route('admin.notifications.create', ['user_id' => $this->getRecord()->id])),
        ];
    }

    private function perform(string $route, Closure $operation, string $method = 'POST'): void
    {
        abort_unless(StudentResource::allows($route, $method), 403);
        try {
            $operation(app(StudentAccountStateService::class));
        } catch (ValidationException $exception) {
            Notification::make()->title((string) collect($exception->errors())->flatten()->first())->warning()->send();
            throw new Halt;
        }
        $this->getRecord()->refresh();
        $this->summary = null;
        $this->captureVersions();
        Notification::make()->title('تم حفظ الإجراء')->success()->send();
    }
}
