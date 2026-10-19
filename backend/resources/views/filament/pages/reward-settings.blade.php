<x-filament-panels::page>
    <form wire:submit="save" class="fi-sc-form">
        {{ $this->form }}
        @if ($this->canSave())
        <x-filament::actions>
            <x-filament::button type="submit" wire:target="save">حفظ التغييرات</x-filament::button>
        </x-filament::actions>
        @endif
    </form>
</x-filament-panels::page>
