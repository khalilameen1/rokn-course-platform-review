@php($activeSupportSource = $supportSource ?? 'app')
<nav class="admin-actions mb-4" aria-label="مصدر رسائل الدعم">
    <a
        class="btn {{ $activeSupportSource === 'app' ? 'btn-primary' : 'btn-light' }}"
        href="{{ route('admin.feedback.index') }}"
        @if($activeSupportSource === 'app') aria-current="page" @endif
    >رسائل التطبيق</a>
    <a
        class="btn {{ $activeSupportSource === 'website' ? 'btn-primary' : 'btn-light' }}"
        href="{{ route('admin.contacts.index') }}"
        @if($activeSupportSource === 'website') aria-current="page" @endif
    >رسائل الموقع وطلبات حذف الحساب</a>
    <a class="btn {{ $activeSupportSource === 'portfolio' ? 'btn-primary' : 'btn-light' }}"
        href="{{ route('admin.portfolio-reviews.index') }}"
        @if($activeSupportSource === 'portfolio') aria-current="page" @endif
    >مراجعة المعارض</a>
</nav>
