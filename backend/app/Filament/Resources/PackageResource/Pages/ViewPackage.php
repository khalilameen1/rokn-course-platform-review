<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PackageResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewPackage extends ViewRecord
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), Action::make('report')->label('تقرير التحصيل')->visible(fn (): bool => OrderResource::canViewAny())
            ->url(fn (): string => OrderResource::getUrl('report', ['filters' => ['package_id' => $this->getRecord()->getKey()]]))];
    }
}
