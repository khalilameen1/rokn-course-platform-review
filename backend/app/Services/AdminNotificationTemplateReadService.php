<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminNotification;
use Illuminate\Database\Eloquent\Builder;

final class AdminNotificationTemplateReadService
{
    /** @return Builder<AdminNotification> */
    public function query(): Builder
    {
        return AdminNotification::query()->with('photo');
    }
}
