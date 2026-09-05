@extends('admin.layouts.app')

@section('page.title', 'رسالة دعم')

@section('content')
@php
    $statuses = [
        'new' => 'جديد', 'reviewing' => 'قيد المراجعة',
        'waiting_for_user' => 'بانتظار الطالب', 'resolved' => 'محلول',
        'closed' => 'مغلق', 'dismissed' => 'مغلق قديم',
    ];
    $priorities = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];
    $categories = ['bug' => 'مشكلة', 'suggestion' => 'اقتراح', 'course_content' => 'محتوى', 'playback' => 'تشغيل'];
    $resolutions = ['fixed' => 'تم الإصلاح', 'guidance' => 'تم الإرشاد', 'compensated' => 'تم التعويض', 'not_reproducible' => 'تعذر تكرار المشكلة', 'duplicate' => 'بلاغ مكرر'];
    $caseNumber = strtoupper(substr($feedback->public_id, -8));
@endphp

<div class="admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'حالة '.$caseNumber,
        'pageDescription' => $categories[$feedback->category] ?? 'دعم',
        'pageIcon' => 'fa-life-ring',
        'pageActionUrl' => route('admin.feedback.index'),
        'pageActionLabel' => 'العودة إلى رسائل الدعم',
        'pageActionIcon' => 'fa-arrow-right',
        'pageActionClass' => 'btn-light',
    ])

    <div class="row">
        <div class="col-lg-8">
            <div class="card admin-card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>المحادثة</strong>
                    @include('admin.partials.status-badge', ['badgeStatus' => $feedback->status, 'badgeLabel' => $statuses[$feedback->status] ?? $feedback->status])
                </div>
                <div class="card-body">
                    @forelse($feedback->messages as $message)
                        <div class="admin-copy mb-3 p-3 border rounded {{ $message->visibility === 'internal' ? 'bg-light' : '' }}">
                            <div class="d-flex justify-content-between mb-2">
                                <strong>
                                    @if($message->visibility === 'internal') ملاحظة داخلية
                                    @elseif($message->author_type === 'learner') الطالب
                                    @else فريق الدعم
                                    @endif
                                </strong>
                                <span class="text-muted">{{ \App\Support\BusinessClock::format($message->created_at) }}</span>
                            </div>
                            <div class="admin-prewrap">{{ $message->body }}</div>
                            @foreach($message->attachments as $attachment)
                                @if($attachment->is_available)
                                    <a class="btn btn-sm btn-outline-primary mt-3" target="_blank" rel="noopener" href="{{ route('admin.feedback.attachment', [$feedback, $attachment]) }}"><i class="fa fa-image"></i> عرض الصورة</a>
                                @else
                                    <span class="text-danger d-block mt-2">الصورة غير متاحة</span>
                                @endif
                            @endforeach
                        </div>
                    @empty
                        <div class="admin-copy mb-3 p-3 border rounded">{{ $feedback->message }}</div>
                    @endforelse

                    @foreach($feedback->attachments->whereNull('support_case_message_id') as $attachment)
                        @if($attachment->is_available)
                            <a class="btn btn-sm btn-outline-primary mb-3" target="_blank" rel="noopener" href="{{ route('admin.feedback.attachment', [$feedback, $attachment]) }}"><i class="fa fa-image"></i> الصورة الأصلية</a>
                        @else
                            <span class="text-danger d-block mb-3">الصورة الأصلية غير متاحة</span>
                        @endif
                    @endforeach

                    <form method="POST" action="{{ route('admin.feedback.message', $feedback) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="version" value="{{ $feedback->version }}">
                        <input type="hidden" name="client_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <div class="form-group">
                            <label for="reply-message">الرد</label>
                            <textarea id="reply-message" class="form-control" name="message" rows="5" maxlength="4000" required>{{ old('message') }}</textarea>
                        </div>
                        <div class="form-group">
                            <label for="visibility">نوع الرسالة</label>
                            <select id="visibility" class="form-control" name="visibility">
                                <option value="customer">رد يراه الطالب</option>
                                <option value="internal">ملاحظة داخلية للفريق</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" type="submit"><i class="fa fa-paper-plane"></i> حفظ الرسالة</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <form method="POST" action="{{ route('admin.feedback.update', $feedback) }}" class="card admin-card mb-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="version" value="{{ $feedback->version }}">
                <div class="card-header"><strong>إدارة الحالة</strong></div>
                <div class="card-body">
                    <div class="form-group"><label for="status">الحالة</label><select id="status" class="form-control" name="status">@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($feedback->status === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-group"><label for="priority">الأولوية</label><select id="priority" class="form-control" name="priority">@foreach($priorities as $value => $label)<option value="{{ $value }}" @selected($feedback->priority === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-group"><label for="assigned-to">المسؤول</label><select id="assigned-to" class="form-control" name="assigned_to"><option value="">غير مسند</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected((int)$feedback->assigned_to === (int)$admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
                    <div class="form-group"><label for="resolution-kind">نتيجة المعالجة</label><select id="resolution-kind" class="form-control" name="resolution_kind"><option value="">دون نتيجة نهائية</option>@foreach($resolutions as $value => $label)<option value="{{ $value }}" @selected($feedback->resolution_kind === $value)>{{ $label }}</option>@endforeach</select></div>
                    <button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> حفظ الحالة</button>
                </div>
            </form>

            <div class="card admin-card mb-4">
                <div class="card-header"><strong>السياق</strong></div>
                <div class="card-body">
                    <div class="mb-3"><div class="admin-detail-label">الطالب</div><div class="admin-detail-value">{{ $feedback->user?->name ?: 'زائر' }}</div></div>
                    <div class="mb-3"><div class="admin-detail-label">البريد</div><div class="admin-detail-value" dir="ltr">{{ $feedback->user?->email ?: ($feedback->requester_email ?: '—') }}</div></div>
                    <div class="mb-3"><div class="admin-detail-label">الكورس</div><div class="admin-detail-value">{{ $feedback->course?->title ?: '—' }}</div></div>
                    <div class="mb-3"><div class="admin-detail-label">الطلب</div><div class="admin-detail-value">{{ $feedback->order_id ? '#'.$feedback->order_id : '—' }}</div></div>
                    <div class="mb-3"><div class="admin-detail-label">التطبيق</div><div class="admin-detail-value" dir="ltr">{{ $feedback->platform ?: '—' }} · {{ $feedback->app_version ?: '—' }} · {{ $feedback->build_number ?: '—' }}</div></div>
                    <div><div class="admin-detail-label">موعد أول رد</div><div class="admin-detail-value">{{ $feedback->first_response_due_at ? \App\Support\BusinessClock::format($feedback->first_response_due_at) : '—' }}</div></div>
                </div>
            </div>

            @if($feedback->order)
                <form method="POST" action="{{ route('admin.feedback.compensate', $feedback) }}" class="card admin-card mb-4">
                    @csrf
                    <input type="hidden" name="version" value="{{ $feedback->version }}">
                    <div class="card-header"><strong>تعويض عطل من طرفنا</strong></div>
                    <div class="card-body">
                        <p class="text-muted">يُسجل التعويض على الطلب الموثق ولا يُنفذ تلقائيًا</p>
                        <div class="form-group"><label for="amount">عملات ركن</label><input id="amount" class="form-control" type="number" name="amount" min="1" required></div>
                        <div class="form-group"><label for="note">سبب التعويض</label><textarea id="note" class="form-control" name="note" rows="3" maxlength="1000" required></textarea></div>
                        <button class="btn btn-outline-primary" type="submit">تسجيل التعويض</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    @if($feedback->events->isNotEmpty())
        <div class="card admin-card mb-4">
            <div class="card-header"><strong>سجل الحالة</strong></div>
            <div class="card-body">
                @foreach($feedback->events as $event)
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>{{ $event->event_type }} · {{ $event->actor?->name ?: 'النظام' }}</span>
                        <span class="text-muted">{{ \App\Support\BusinessClock::format($event->created_at) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
