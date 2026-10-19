<?php

namespace App\Filament\Resources\PaymentFindingResource\Pages;

use App\Filament\Resources\PaymentFindingResource;
use App\Filament\Widgets\PaymentFindingStats;
use App\Models\PaymentReconciliationFinding;
use App\Services\PaymentReconciliationReviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListPaymentFindings extends ListRecords
{
    protected static string $resource = PaymentFindingResource::class;

    #[Locked]
    public string $findingVersion = '';

    #[Locked]
    public int $findingId = 0;

    public function getSubheading(): string
    {
        return 'قرارات المراجعة هنا لا تغيّر الطلب أو الرصيد';
    }

    protected function getHeaderWidgets(): array
    {
        return [PaymentFindingStats::class];
    }

    public function decide(Action $action, PaymentReconciliationFinding $record, string $name, array $data): void
    {
        $state = match ($name) {
            'resolve' => PaymentReconciliationFinding::STATE_RESOLVED,
            'ignore' => PaymentReconciliationFinding::STATE_IGNORED,
            'reopen' => PaymentReconciliationFinding::STATE_OPEN,
            default => abort(403),
        };
        abort_unless(PaymentFindingResource::allows($name) && $this->findingId === $record->id, 403);
        try {
            app(PaymentReconciliationReviewService::class)->transition($record->id, $state, $data['note'] ?? '', $this->findingVersion, (int) auth('web')->id());
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['finding'])) {
                Notification::make()->title($exception->errors()['finding'][0])->warning()->send();
                throw new Halt;
            }
            $path = $this->getMountedActionSchema(mountedAction: $action)?->getStatePath();
            if ($path === null) {
                throw $exception;
            }
            throw ValidationException::withMessages([$path.'.note' => $exception->errors()['note'] ?? ['راجع سبب القرار']]);
        }
        Notification::make()->title('تم توثيق القرار')->success()->send();
    }
}
