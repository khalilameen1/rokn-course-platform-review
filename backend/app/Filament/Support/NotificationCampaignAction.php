<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\NotificationCampaignResource;
use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/** Native confirmation only; the existing compare-and-set owner decides the transition. */
final class NotificationCampaignAction
{
    public static function make(string $operation): Action
    {
        if (! in_array($operation, ['cancel', 'retry'], true)) {
            throw new \InvalidArgumentException('Unknown campaign action.');
        }

        return Action::make($operation)->label($operation === 'cancel' ? 'إلغاء الحملة' : 'إعادة المحاولة')
            ->color($operation === 'cancel' ? 'danger' : 'primary')->requiresConfirmation()
            ->authorize(fn (): bool => NotificationCampaignResource::allows($operation))
            ->visible(fn (NotificationCampaign $record): bool => $operation === 'cancel'
                ? NotificationCampaignService::looksCancellable($record) : $record->status === NotificationCampaign::STATUS_FAILED)
            ->modalDescription($operation === 'cancel' ? 'لن تُرسل الحملة ولا يمكن استئنافها بعد الإلغاء' : 'يُستكمل الإرسال دون تكرار الإشعارات المسجلة')
            ->action(function (NotificationCampaign $record) use ($operation): void {
                abort_unless(NotificationCampaignResource::allows($operation), 403);
                $owner = app(NotificationCampaignService::class);
                $changed = $operation === 'cancel' ? $owner->cancel($record) : $owner->retry($record);
                if (! $changed) {
                    Notification::make()->title('تغيّرت حالة الحملة')->body('أعد تحميل القائمة لعرض حالتها الحالية')->warning()->send();
                    throw new Halt;
                }
                $record->refresh();
                Notification::make()->title($operation === 'cancel' ? 'أُلغيت الحملة ولن تُرسل' : 'أُعيدت الحملة إلى قائمة الإرسال')->success()->send();
            });
    }
}
