<x-filament-panels::page>
    <form wire:submit="applyReportFilters">
        {{ $this->form }}
        <x-filament::button type="submit" class="mt-4">عرض التقرير</x-filament::button>
    </form>
    {{ $this->infolist }}
</x-filament-panels::page>
