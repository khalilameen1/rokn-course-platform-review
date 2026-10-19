<?php

declare(strict_types=1);

namespace App\Filament\Resources\BunnyCleanupResource\Pages;

use App\Filament\Resources\BunnyCleanupResource;
use Filament\Resources\Pages\ListRecords;

final class ListBunnyCleanup extends ListRecords
{
    protected static string $resource = BunnyCleanupResource::class;

    public function getSubheading(): string
    {
        return 'الاعتماد لا يحذف الملف الآن — العامل يلتزم بفترة الاحتفاظ ويعيد فحص الارتباط بالمحتوى';
    }
}
