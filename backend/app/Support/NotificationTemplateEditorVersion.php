<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminNotification;

final class NotificationTemplateEditorVersion
{
    public static function for(AdminNotification $notification): string
    {
        $notification->loadMissing('photo');
        return hash('sha256', json_encode([
            (string) $notification->system_key,
            (string) $notification->surface,
            (string) $notification->title_ar,
            (string) $notification->title_en,
            (string) $notification->description_ar,
            (string) $notification->description_en,
            (string) $notification->action_label_ar,
            (string) $notification->action_label_en,
            (string) $notification->secondary_action_label_ar,
            (string) $notification->secondary_action_label_en,
            (string) $notification->link,
            (bool) $notification->is_active,
            (bool) $notification->is_dismissible,
            (int) $notification->priority,
            (int) $notification->cooldown_hours,
            $notification->starts_at?->toIso8601String(),
            $notification->ends_at?->toIso8601String(),
            (string) ($notification->photo?->path ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
