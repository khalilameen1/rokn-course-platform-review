<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\NotificationTemplateResource;
use App\Models\AdminNotification;
use App\Services\AdminNotificationTemplateAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/** @extends CreateRecord<AdminNotification> */
final class CreateNotificationTemplate extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = NotificationTemplateResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $image = ($data['image'] ?? null) instanceof UploadedFile ? $data['image'] : null;

        return $this->createWithReceipt(NotificationTemplateResource::draftOperation(), AdminNotification::class, $data,
            fn ($complete): AdminNotification => app(AdminNotificationTemplateAuthoringService::class)->create($data, $this->creationIntent, $image, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return NotificationTemplateResource::getUrl();
    }
}
