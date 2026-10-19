<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use App\Services\AdminAuditLogReadService;
use Filament\Resources\Pages\ListRecords;

final class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getSubheading(): string
    {
        return app(AdminAuditLogReadService::class)->recordsChanges() ? 'للقراءة فقط' : 'تسجيل القيم قبل وبعد غير متاح حتى تشغيل migrations';
    }

    public function applyTableFilters(): void
    {
        $this->getTableFiltersForm()->getState();
        parent::applyTableFilters();
    }
}
