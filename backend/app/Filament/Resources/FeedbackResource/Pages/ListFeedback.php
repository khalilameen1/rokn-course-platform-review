<?php

namespace App\Filament\Resources\FeedbackResource\Pages;

use App\Filament\Resources\ContactResource;
use App\Filament\Resources\FeedbackResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    public function exportUrl(): string
    {
        return route('admin.feedback.export', array_filter($this->getTableFilterState('criteria') ?? [], fn ($value): bool => $value !== null && $value !== ''));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('contacts')->label('رسائل الموقع وطلبات الحذف')->url(ContactResource::getUrl())->visible(ContactResource::canViewAny()),
            Action::make('export')->label('تصدير CSV')->url(fn (): string => $this->exportUrl())];
    }

    public function applyTableFilters(): void
    {
        $this->getTableFiltersForm()->getState();
        parent::applyTableFilters();
    }
}
