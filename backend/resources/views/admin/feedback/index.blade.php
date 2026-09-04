@extends('admin.layouts.app')

@section('page.title', 'رسائل الدعم')

@section('content')
@php
    $categories = ['bug' => 'مشكلة', 'suggestion' => 'اقتراح', 'course_content' => 'محتوى', 'playback' => 'تشغيل'];
    $priorities = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];
@endphp

<div class="admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'رسائل الدعم',
        'pageDescription' => 'رسائل التطبيق والموقع وطلبات حذف الحساب',
        'pageIcon' => 'fa-commenting-o',
    ])

    @include('admin.partials.support-inbox-tabs', ['supportSource' => 'app'])

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

    <div class="card admin-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>تصفية الملاحظات</strong>
            <span class="badge badge-primary">{{ number_format($reports->total()) }}</span>
        </div>
        <div class="card-body">
            <form method="GET">
                <div class="row">
                    <div class="col-md-4 mb-3"><label for="q">بحث</label><input id="q" class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="رقم البلاغ أو الرسالة أو المستخدم"></div>
                    <div class="col-md-2 mb-3"><label for="status">الحالة</label><select id="status" class="form-control" name="status"><option value="">الكل</option>@foreach(['new'=>'جديد','reviewing'=>'قيد المراجعة','waiting_for_user'=>'بانتظار الطالب','resolved'=>'محلول','closed'=>'مغلق','dismissed'=>'مغلق قديم'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-2 mb-3"><label for="category">النوع</label><select id="category" class="form-control" name="category"><option value="">الكل</option>@foreach($categories as $value=>$label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-2 mb-3"><label for="priority">الأولوية</label><select id="priority" class="form-control" name="priority"><option value="">الكل</option>@foreach($priorities as $value=>$label)<option value="{{ $value }}" @selected(($filters['priority'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-2 mb-3"><label for="app-version">إصدار التطبيق</label><input id="app-version" class="form-control" name="app_version" value="{{ $filters['app_version'] ?? '' }}"></div>
                    <div class="col-md-2 mb-3"><label for="assigned-to">المسؤول</label><select id="assigned-to" class="form-control" name="assigned_to"><option value="">الكل</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected((string)($filters['assigned_to'] ?? '') === (string)$admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
                    <div class="col-md-2 mb-3"><label for="overdue">الاستجابة</label><select id="overdue" class="form-control" name="overdue"><option value="">الكل</option><option value="1" @selected(($filters['overdue'] ?? '') === '1')>تجاوز الموعد</option></select></div>
                    <div class="col-md-2 mb-3"><label for="from">من</label><input id="from" class="form-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
                    <div class="col-md-2 mb-3"><label for="to">إلى</label><input id="to" class="form-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
                </div>
                <div class="admin-actions">
                    <button class="btn btn-primary" type="submit"><i class="fa fa-filter"></i> تطبيق</button>
                    <a href="{{ route('admin.feedback.index') }}" class="btn btn-light">مسح</a>
                    <a href="{{ route('admin.feedback.export', request()->query()) }}" class="btn btn-outline-primary"><i class="fa fa-download"></i> تصدير</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card admin-card">
        <div class="table-responsive">
            <table class="table table-hover admin-table mb-0">
                <thead class="thead-light"><tr><th>رقم الحالة</th><th>النوع</th><th>الرسالة</th><th>الحالة</th><th>الأولوية</th><th>المسؤول</th><th>الاستجابة</th><th>آخر تحديث</th></tr></thead>
                <tbody>
                @forelse($reports as $report)
                    <tr>
                        <td><a class="admin-code" href="{{ route('admin.feedback.show', $report) }}">{{ strtoupper(substr($report->public_id, -8)) }}</a></td>
                        <td>{{ $categories[$report->category] ?? $report->category }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($report->message, 85) }}</td>
                        <td>@include('admin.partials.status-badge', ['badgeStatus' => $report->status])</td>
                        <td>@include('admin.partials.status-badge', ['badgeStatus' => $report->priority, 'badgeLabel' => $priorities[$report->priority] ?? $report->priority, 'badgeTone' => $report->priority === 'urgent' ? 'danger' : ($report->priority === 'high' ? 'warning' : 'muted')])</td>
                        <td>{{ $report->assignee?->name ?: 'غير مسند' }}</td>
                        <td>
                            @if(!$report->last_staff_message_at && $report->first_response_due_at)
                                <span class="{{ $report->first_response_due_at->isPast() ? 'text-danger' : 'text-muted' }}">{{ \App\Support\BusinessClock::relative($report->first_response_due_at) }}</span>
                            @else
                                <span class="text-success">تم الرد</span>
                            @endif
                        </td>
                        <td>{{ \App\Support\BusinessClock::format($report->updated_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">لا توجد حالات مطابقة</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $reports->links() }}</div>
</div>
@endsection
