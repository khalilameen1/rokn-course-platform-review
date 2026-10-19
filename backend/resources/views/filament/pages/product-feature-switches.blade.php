<x-filament-panels::page>
    <x-filament::section>
        <p>الحالة الفعلية للطلبات الجديدة تُحدّث تلقائيًا كل ٣٠ ثانية</p>
        @if(config('operations.disaster_recovery_mode', false))
            <p>وضع التعافي يوقف الدفع حتى إذا كان آخر قرار محفوظ يسمح به</p>
        @endif
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
