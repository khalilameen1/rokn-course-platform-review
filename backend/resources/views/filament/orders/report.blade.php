<x-filament-panels::page wire:poll.60s>
    <form wire:submit="applyReportFilters">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
