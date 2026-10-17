@extends('admin.layouts.app')
@section('page.title', $targetStudent ? 'إرسال إشعار للطالب' : 'إرسال إشعار للطلاب')
@section('styles')
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/notifications-dashboard.css') }}">
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
    @php
        $notificationFormId = $targetStudent
            ? 'notificationForm-user-' . $targetStudent->id
            : 'notificationForm-broadcast';
    @endphp
    <div class="admin-page notification-form-page row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header">
                    <i class="fa fa-bell-o"></i>
                    <strong class="card-title pr-2">{{ $targetStudent ? 'إرسال إشعار للطالب' : 'إرسال إشعار للطلاب' }}</strong>
                </div>
                <div class="card-body card-block">
                    <form method="GET" action="{{ route('admin.notifications.create') }}" class="mb-4">
                        @if($targetStudent)<input type="hidden" name="user_id" value="{{ $targetStudent->id }}">@endif
                        <label for="course-search">ابحث عن كورس</label>
                        <div class="input-group">
                            <input class="form-control" id="course-search" maxlength="100" name="course_search" type="search" value="{{ $courseSearch }}" placeholder="الاسم أو الرقم">
                            <div class="input-group-append"><button class="btn btn-outline-secondary" type="submit">بحث</button></div>
                        </div>
                        <small class="form-text text-muted">تظهر أحدث ٥٠ نتيجة</small>
                    </form>
                    {!! Form::open(['method' => 'POST', 'route' => ['admin.notifications.store'], 'files' => true, 'id' => $notificationFormId]) !!}
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
                            <small class="form-text text-muted">نافذة المحتوى الجديد في الرئيسية تعرض عنوان الكورس وغلافه الحاليين وشارة جديد وزر ابدأ الكورس وتظهر نافذة واحدة فقط في الجلسة ولا تظهر مباشرة بعد تسجيل الدخول</small>
                        </div>
                        @unless($targetStudent)
                        <div class="form-group">
                            <label for="notification-audience">الجمهور <span class="text-danger">*</span></label>
                            <select name="audience" id="notification-audience" class="form-control" required>
                                <option value="" {{ old('audience') ? '' : 'selected' }}>اختر الجمهور</option>
                                <option value="not_enrolled" {{ old('audience') === 'not_enrolled' ? 'selected' : '' }}>غير المسجلين في الكورس</option>
                                <option value="enrolled" {{ old('audience') === 'enrolled' ? 'selected' : '' }}>المسجلون في الكورس</option>
                                <option value="all" {{ old('audience') === 'all' ? 'selected' : '' }}>كل الطلاب</option>
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
                        @unless($targetStudent)
                        <div class="form-group">
                            <label for="notification-test-email">إرسال تجربة <small class="text-muted">اختياري</small></label>
                            <div class="input-group">
                                <input class="form-control" id="notification-test-email" name="test_email" type="email" dir="ltr" maxlength="255" autocomplete="off" placeholder="بريد حساب طالب للتجربة" value="{{ old('test_email') }}">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="submit" formaction="{{ route('admin.notifications.test') }}" formnovalidate data-notification-test-send>إرسال تجربة</button>
                                </div>
                            </div>
                            <small class="form-text text-muted">تصل نسخة واحدة الآن لهذا الحساب فقط بعنوان يبدأ بـ «تجربة:» ولا تُرسل للجمهور</small>
                            <div class="small mt-1" data-notification-test-status role="status" aria-live="polite"></div>
                        </div>
                        <div class="alert alert-light border" data-recipient-preview data-preview-url="{{ route('admin.notifications.audience-preview') }}">
                            <strong class="d-block mb-1">مراجعة قبل الإرسال</strong>
                            <div data-recipient-count role="status" aria-live="polite">اختر الجمهور لعرض عدد المستلمين</div>
                            <small class="form-text text-muted">العدد محسوب الآن بنفس قواعد الإرسال وقد يتغير قليلًا حتى موعد الإرسال</small>
                            <label class="d-flex align-items-start mt-2 mb-0" for="notification-confirm-send">
                                <input class="mt-1 ml-2" id="notification-confirm-send" name="confirm_send" type="checkbox" value="1" required>
                                <span data-confirm-send-label>راجعت الجمهور والنص وأؤكد الإرسال</span>
                            </label>
                            @error('confirm_send')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        @endunless
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fa fa-paper-plane"></i> {{ $targetStudent ? 'إرسال للطالب' : 'تأكيد الإرسال' }}
                            </button>
                        </div>
                    {!! Form::close() !!}
                    @include('admin.partials.course-authoring-draft', ['formId' => $notificationFormId])
                    @unless($targetStudent)
                        @include('admin.notifications.partials.review-script', ['formId' => $notificationFormId])
                    @endunless
                </div>
            </div>
        </div>
    </div>
@endsection
