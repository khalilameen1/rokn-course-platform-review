<div
    wire:ignore
    x-load
    x-load-src="{{ versioned_asset('js/authoring-draft.js') }}"
    x-data="authoringDraft(@js($getLivewire()->authoringDraftConfig()))"
    data-authoring-draft
>
    <div x-show="recoverable.length" x-cloak role="status">
        <p>توجد مسودة أخرى لهذا النموذج</p>
        <template x-for="(candidate, index) in recoverable" :key="candidate.entry.key">
            <x-filament::button type="button" color="gray" size="sm" x-on:click="recover(index)">
                <span x-text="index === 0 ? 'استعادة أحدث مسودة' : 'استعادة المسودة ' + (index + 1)"></span>
            </x-filament::button>
        </template>
    </div>
</div>
