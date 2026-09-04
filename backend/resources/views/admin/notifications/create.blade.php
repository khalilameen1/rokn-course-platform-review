@extends('admin.layouts.app')
@section('page.title', $targetStudent ? 'إرسال إشعار للطالب' : 'إرسال إشعار للطلاب')
@section('styles')
    <link rel="stylesheet" href="{{ asset('admin/assets/css/notifications-dashboard.css') }}">
@endsection
@section('breadcrumbs')
    <div class="col-sm-12">
        <div class="page-header float-right">
            <div class="page-title">
                <h1>الإشعارات</h1>
            </div>
        </div>
    </div>
@endsection
@section('content')
    <div class="admin-page notification-form-page row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header">
                    <i class="fa fa-bell-o"></i>
                    <strong class="card-title pr-2">{{ $targetStudent ? 'إرسال إشعار للطالب' : 'إرسال إشعار للطلاب' }}</strong>
                </div>
                <div class="card-body card-block">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <form method="GET" action="{{ route('admin.notifications.create') }}" class="mb-4">
                        @if($targetStudent)<input type="hidden" name="user_id" value="{{ $targetStudent->id }}">@endif
                        <label for="course-search">ابحث عن كورس</label>
                        <div class="input-group">
                            <input class="form-control" id="course-search" maxlength="100" name="course_search" type="search" value="{{ $courseSearch }}" placeholder="الاسم أو الرقم">
                            <div class="input-group-append"><button class="btn btn-outline-secondary" type="submit">بحث</button></div>
                        </div>
                        <small class="form-text text-muted">تظهر أحدث ٥٠ نتيجة</small>
                    </form>
                    {!! Form::open(['method' => 'POST', 'route' => ['admin.notifications.store'], 'files' => true, 'id' => 'notificationForm']) !!}
                        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                        @if($targetStudent)
                            <input type="hidden" name="user_id" value="{{ $targetStudent->id }}">
                            <input type="hidden" name="audience" value="all">
                            <div class="alert alert-info">
                                <strong>{{ $targetStudent->name }}</strong>
                                <span class="d-block">{{ $targetStudent->email }}</span>
                            </div>
                        @endif
                        <div class="form-group">
                            <label for="notification-course">الكورس (اختياري)</label>
                            <select name="course_id" id="notification-course" class="form-control">
                                <option value="">إشعار عام</option>
                                @foreach($courses as $course)
                                    <option value="{{ $course->id }}" {{ (string) old('course_id') === (string) $course->id ? 'selected' : '' }}>
                                        {{ $course->name_ar }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">اختيار كورس مطلوب عند استهداف المسجلين أو غير المسجلين ويجعل الضغط يفتح صفحة الكورس.</small>
                        </div>
                        @unless($targetStudent)
                        <div class="form-group">
                            <label for="notification-audience">الجمهور</label>
                            <select name="audience" id="notification-audience" class="form-control" required>
                                <option value="not_enrolled" {{ old('audience') === 'not_enrolled' ? 'selected' : '' }}>غير المسجلين في الكورس</option>
                                <option value="enrolled" {{ old('audience') === 'enrolled' ? 'selected' : '' }}>المسجلون في الكورس</option>
                                <option value="all" {{ old('audience', 'all') === 'all' ? 'selected' : '' }}>كل الطلاب</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="notification-kind">نوع الإشعار العام</label>
                            <select name="notification_kind" id="notification-kind" class="form-control">
                                <option value="marketing" {{ old('notification_kind', 'marketing') === 'marketing' ? 'selected' : '' }}>جديد وعروض — لمن فعّلها</option>
                                <option value="service" {{ old('notification_kind') === 'service' ? 'selected' : '' }}>خدمة أو حساب — يصل للجميع</option>
                            </select>
                            <small class="form-text text-muted">يُستخدم عند اختيار إشعار عام فقط</small>
                        </div>
                        @endunless
                        <div class="form-group">
                            <label for="notification-title">عنوان الإشعار <span class="text-danger">*</span></label>
                            <input name="title_ar" id="notification-title" maxlength="80" placeholder="عنوان قصير" class="form-control" type="text" required value="{{ old('title_ar') }}">
                        </div>
                        <div class="form-group">
                            <label for="notification-message">نص الإشعار <span class="text-danger">*</span></label>
                            <textarea name="message_ar" id="notification-message" maxlength="240" placeholder="اكتب المطلوب مباشرة" class="form-control" rows="4" required>{{ old('message_ar') }}</textarea>
                        </div>
                        <div class="form-group">
                            <label for="notification-image">الصورة <small class="text-muted">اختيارية</small></label>
                            <input accept="image/jpeg,image/png,image/webp" class="form-control-file" id="notification-image" name="image" type="file">
                            <small class="form-text text-muted">تظهر داخل التطبيق وفي إشعار الهاتف عندما يدعم الجهاز ذلك</small>
                        </div>
                        <div class="form-group">
                            <label for="notification-action-label">نص الزر <small class="text-muted">اختياري</small></label>
                            <input name="action_label" id="notification-action-label" maxlength="40" placeholder="مثال: افتح الكورس" class="form-control" type="text" value="{{ old('action_label') }}">
                        </div>
                        <div class="form-group">
                            <label for="notification-action-link">وجهة الزر <small class="text-muted">اختيارية</small></label>
                            <input name="action_link" id="notification-action-link" maxlength="2000" placeholder="مثال: /wallet أو /profile/certificates" class="form-control" dir="ltr" type="text" value="{{ old('action_link') }}">
                            <small class="form-text text-muted">عند اختيار كورس تُضبط الوجهة تلقائيًا على الكورس</small>
                        </div>
                        <div class="form-group">
                            <label for="notification-send-at">موعد الإرسال <small class="text-muted">اختياري</small></label>
                            <input class="form-control" id="notification-send-at" name="send_at" type="datetime-local" value="{{ old('send_at') }}">
                            <small class="form-text text-muted">اتركه فارغًا للإرسال الآن</small>
                        </div>
                        <div class="form-group">
                            <label for="notification-title-en">English title <small class="text-muted">(optional)</small></label>
                            <input name="title_en" id="notification-title-en" maxlength="80" class="form-control" type="text" value="{{ old('title_en') }}" dir="ltr">
                        </div>
                        <div class="form-group">
                            <label for="notification-message-en">English message <small class="text-muted">(optional)</small></label>
                            <textarea name="message_en" id="notification-message-en" maxlength="240" class="form-control" rows="3" dir="ltr">{{ old('message_en') }}</textarea>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fa fa-paper-plane"></i> {{ $targetStudent ? 'إرسال للطالب' : 'إضافة إلى قائمة الإرسال' }}
                            </button>
                        </div>
                    {!! Form::close() !!}
                    @include('admin.partials.course-authoring-draft', ['formId' => 'notificationForm'])
                </div>
            </div>
        </div>
    </div>
@endsection
