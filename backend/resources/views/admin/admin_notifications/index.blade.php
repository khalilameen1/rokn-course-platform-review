@extends('admin.layouts.app')

@section('page.title', 'قوالب الإشعارات')
@section('styles')<link rel="stylesheet" href="{{ asset('admin/assets/css/notifications-dashboard.css') }}">@endsection

@section('content')
<div class="admin-page notifications-page" dir="rtl">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 admin-gap">
        <div><h1 class="h3 mb-1">نبرة الإشعارات وتجربة العودة</h1><p class="text-muted mb-0">عدّل ما يراه الطالب قبل التسجيل وبعده، ورسائل الاحتفاظ والجديد، من مكان واحد.</p></div>
        <div>
            <a class="btn btn-light" href="{{ route('admin.notifications.index') }}">سجل الإرسال</a>
            <a class="btn btn-primary" href="{{ route('admin.admin_notifications.create') }}"><i class="fa fa-plus ml-1"></i> إضافة قالب أو إعلان</a>
        </div>
    </div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="notification-template-grid">
        @forelse($admin_notifications as $notification)
            <article class="notification-template-card {{ !$notification->is_active ? 'is-disabled' : '' }}">
                <div class="notification-template-card__visual">@if($notification->public_image_url)<img src="{{ $notification->public_image_url }}" alt="">@else<i class="fa fa-bell-o" aria-hidden="true"></i>@endif</div>
                <div class="notification-template-card__copy">
                    <div class="notification-template-card__meta"><span>{{ \App\Models\AdminNotification::SURFACES[$notification->surface] ?? $notification->surface }}</span><span>{{ $notification->is_active ? 'مفعّل' : 'متوقف' }}</span>@if($notification->cooldown_hours)<span>تهدئة {{ $notification->cooldown_hours }}س</span>@endif</div>
                    <h2>{{ $notification->title_ar }}</h2>
                    <p>{{ $notification->description_ar }}</p>
                    @if($notification->action_label_ar && $notification->link)
                        <div class="small text-primary">{{ $notification->action_label_ar }}</div>
                    @endif
                    <small><code>{{ $notification->system_key ?: 'إعلان يدوي' }}</code> · أولوية {{ $notification->priority }}</small>
                </div>
                <div class="notification-template-card__actions">
                    <a class="btn btn-sm btn-primary" href="{{ route('admin.admin_notifications.edit', $notification) }}">تعديل</a>
                    <form action="{{ route('admin.admin_notifications.destroy', $notification) }}" method="post" onsubmit="if(!confirm('{{ $notification->isSystemTemplate() ? 'إيقاف هذا القالب؟' : 'حذف هذا القالب؟' }}')) return false; const button=this.querySelector('button[type=submit]'); if(button.disabled) return false; button.disabled=true;">@csrf @method('DELETE')<input name="editor_version" type="hidden" value="{{ $editorVersions->get($notification->id) }}"><button class="btn btn-sm btn-outline-danger" type="submit">{{ $notification->isSystemTemplate() ? 'إيقاف' : 'حذف' }}</button></form>
                </div>
            </article>
        @empty
            <div class="card p-5 text-center text-muted">لا توجد قوالب بعد.</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $admin_notifications->links() }}</div>
</div>
@endsection
