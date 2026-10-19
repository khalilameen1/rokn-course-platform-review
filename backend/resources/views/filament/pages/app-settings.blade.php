<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        @if ($this->canSave())
            <div class="mt-6">
                <x-filament::button type="submit" wire:target="save" wire:loading.attr="disabled">حفظ التغييرات</x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>
