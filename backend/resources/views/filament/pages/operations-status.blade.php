<x-filament-panels::page>
    <x-filament::section heading="أداء التطبيق" description="الطلبات البطيئة والأخطاء والاستعلامات">
        <x-slot name="afterHeader">
            <x-filament::badge :color="config('pulse.enabled') ? 'success' : 'gray'">
                {{ config('pulse.enabled') ? 'التسجيل مفعّل' : 'التسجيل غير مفعّل' }}
            </x-filament::badge>
        </x-slot>
        <x-filament::button tag="a" :href="url('/operations/health')">فتح Laravel Pulse</x-filament::button>
    </x-filament::section>
    <x-filament::section heading="الطوابير والمهام" description="المهام الجارية والفاشلة ووقت الانتظار">
        <x-filament::button tag="a" :href="url('/operations/queues')">فتح Laravel Horizon</x-filament::button>
        <x-filament::button tag="a" color="gray" :href="route('admin.product-operations.index')">حالة المنتج ومفاتيح الإيقاف</x-filament::button>
    </x-filament::section>
    <x-filament::section heading="محادثات رُكن" description="Langfuse من خلال OpenRouter Broadcast">
        <p>تفعيل الربط واستقبال أول محادثة يحتاجان إعداد الحساب الخارجي</p>
        <x-filament::button tag="a" href="https://openrouter.ai/settings/observability" target="_blank" rel="noopener noreferrer">إعداد الربط</x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
