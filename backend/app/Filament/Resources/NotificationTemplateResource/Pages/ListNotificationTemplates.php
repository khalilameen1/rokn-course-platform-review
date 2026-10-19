<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\NotificationTemplateResource;
use App\Models\AdminNotification;
use App\Services\AdminNotificationTemplateAuthoringService;
use App\Support\NotificationTemplateEditorVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    #[Locked]
    public ?int $pendingRecord = null;

    #[Locked]
    public ?string $pendingVersion = null;

    public function remember(AdminNotification $record): void
    {
        $this->pendingRecord = (int) $record->id;
        $this->pendingVersion = NotificationTemplateEditorVersion::for($record);
    }

    public function remove(AdminNotification $record): void
    {
        abort_unless(NotificationTemplateResource::canDelete($record), 403);
        abort_unless($this->pendingRecord === (int) $record->id && $this->pendingVersion !== null, 409);
        try {
            $disabled = app(AdminNotificationTemplateAuthoringService::class)->deleteOrDisable((int) $record->id, $this->pendingVersion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر إيقاف القالب')->warning()->send();
            throw new Halt;
        }
        Notification::make()->title($disabled ? 'تم إيقاف القالب' : 'تم حذف القالب')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('إعلان جديد')->visible(fn (): bool => NotificationTemplateResource::canCreate())->url(NotificationTemplateResource::getUrl('create')),
            Action::make('campaigns')->label('سجل الإرسال')->visible(fn (): bool => NotificationCampaignResource::canViewAny())->url(NotificationCampaignResource::getUrl())];
    }
}
