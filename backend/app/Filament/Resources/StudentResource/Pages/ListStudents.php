<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

final class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    #[Locked]
    public string $noteIntent;

    public function mount(): void
    {
        parent::mount();
        $this->noteIntent = (string) Str::uuid();
    }

    public function exportUrl(): string
    {
        return route('admin.users.export', array_filter(['search' => $this->getTableSearch(), 'active' => $this->getTableFilterState('active')['value'] ?? null],
            fn ($value): bool => $value !== null && $value !== ''));
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), Action::make('export')->label('تصدير CSV')->url(fn (): string => $this->exportUrl()),
            Action::make('notify')->label('إرسال إشعار جماعي')->visible(StudentResource::allowsRoute('admin.notifications.store', 'POST'))
                ->url(route('admin.notifications.create'))];
    }
}
