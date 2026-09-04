<div class="section-card-modern">
    <div class="section-header-modern">
        <h3 class="section-title"><i class="fa fa-bell"></i> الإشعارات</h3>
        <div>
            <a href="{{ route('admin.notifications.create', ['user_id' => $user->id]) }}" class="btn-action-modern btn-edit"><i class="fa fa-paper-plane"></i> إرسال للطالب</a>
            <a href="{{ route('admin.notifications.create') }}" class="btn-action-modern btn-edit"><i class="fa fa-users"></i> إشعار جماعي</a>
            <a href="{{ route('admin.notifications.index') }}" class="btn-action-modern btn-edit"><i class="fa fa-list"></i> سجل الإرسال</a>
        </div>
    </div>
    <div class="section-body">
        <div class="alert {{ $user->notifications_status && $user->device_tokens_count > 0 ? 'alert-success' : 'alert-warning' }} mb-0">
            @if($user->notifications_status && $user->device_tokens_count > 0)
                إشعارات الهاتف مفعلة على {{ $user->device_tokens_count }} جهاز
            @else
                سيظهر الإشعار داخل حساب الطالب
            @endif
        </div>
    </div>
</div>
