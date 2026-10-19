<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use App\Models\AdminAuditLog;
use Filament\Resources\Pages\ViewRecord;

/** @extends ViewRecord<AdminAuditLog> */
final class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل سجل التغييرات';
    }
}
