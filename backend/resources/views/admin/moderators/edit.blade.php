@extends('admin.layouts.app')
@section('page.title', 'تعديل مسؤول المحتوى')
@section('content')
<div class="admin-page animated fadeIn">
    <div class="card admin-card">
        <div class="card-header"><strong>تعديل مسؤول المحتوى</strong></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.moderators.update', $moderator) }}">
                <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                @include('admin.moderators._form')
                <button class="btn btn-primary" type="submit">حفظ</button>
                <a class="btn btn-light" href="{{ route('admin.moderators.index') }}">إلغاء</a>
            </form>
        </div>
    </div>
    <div class="card admin-card mt-4">
        <div class="card-header"><strong>التحقق بخطوتين</strong></div>
        <div class="card-body">
            @if($moderator->admin_totp_confirmed_at)
                <p class="mb-2">مفعّل منذ {{ \App\Support\BusinessClock::format($moderator->admin_totp_confirmed_at) }}</p>
                <p class="text-muted">استخدم إعادة الضبط فقط إذا فقد مسؤول المحتوى تطبيق المصادقة ورموز الاسترداد معًا وتحققت من هويته بقناة أخرى. سيُطلب منه إعداد تطبيق جديد عند دخوله التالي.</p>
                <form method="POST" action="{{ route('admin.moderators.mfa-reset', $moderator) }}">
                    @csrf
                    <div class="form-group">
                        <label for="mfa-reset-confirm">اكتب <bdi dir="ltr">{{ $moderator->email }}</bdi> للتأكيد</label>
                        <input id="mfa-reset-confirm" class="form-control" name="confirm_email" type="text" dir="ltr" autocomplete="off" required>
                        @error('confirm_email')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-outline-danger" type="submit">إعادة ضبط التحقق بخطوتين</button>
                </form>
            @else
                <p class="text-muted mb-0">لم يُكمل إعداد التحقق بخطوتين بعد، وسيُطلب منه ذلك عند دخوله التالي.</p>
            @endif
        </div>
    </div>
</div>
@endsection
