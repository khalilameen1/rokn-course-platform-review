@extends('admin.layouts.app')

@section('page.title', 'مراجعة محاولات المشاريع')

@section('content')
@php
    $statuses = [
        'pending' => ['label' => 'بانتظار المراجعة', 'icon' => 'fa-clock-o'],
        'passed' => ['label' => 'مقبولة', 'icon' => 'fa-check-circle'],
        'needs_resubmission' => ['label' => 'تحتاج إعادة إرسال', 'icon' => 'fa-repeat'],
    ];
    $effortLabels = [
        'valid' => 'تسليم صالح للمراجعة',
        'invalid' => 'تسليم غير كافٍ',
        'unknown' => 'لم يُفحص بعد',
    ];
@endphp

<div class="admin-page animated fadeIn">
    @include('admin.partials.page-header', [
        'pageTitle' => 'مراجعة محاولات المشاريع',
        'pageDescription' => 'قرارات المراجعة اليدوية موثقة باسم المراجع.',
        'pageIcon' => 'fa-tasks',
    ])

    <div class="row mb-3">
        @foreach($statuses as $status => $details)
            <div class="col-md-4 mb-3">
                @include('admin.partials.metric-card', [
                    'metricLabel' => $details['label'],
                    'metricValue' => number_format((int) ($statusCounts[$status] ?? 0)),
                    'metricIcon' => $details['icon'],
                    'metricHref' => route('admin.project-submissions.index', ['status' => $status]),
                ])
            </div>
        @endforeach
    </div>

    <div class="card admin-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.project-submissions.index') }}" class="row align-items-end">
                <div class="col-md-6 mb-2">
                    <label for="search">{{ $isAdministrator ? 'بحث بالطالب أو الكورس أو المحاولة' : 'بحث بالكورس أو المشروع أو المحاولة' }}</label>
                    <input id="search" name="search" type="search" class="form-control" value="{{ $filters['search'] ?? '' }}" placeholder="{{ $isAdministrator ? 'الاسم أو البريد أو الهاتف أو الكورس أو UUID' : 'اسم الكورس أو المشروع أو UUID' }}">
                </div>
                <div class="col-md-3 mb-2">
                    <label for="status">الحالة</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">كل الحالات</option>
                        @foreach($statuses as $status => $details)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $details['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 mb-2 admin-actions">
                    <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> تطبيق</button>
                    <a href="{{ route('admin.project-submissions.index') }}" class="btn btn-light">مسح</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card admin-card">
        <div class="table-responsive">
            <table class="table table-hover admin-table mb-0">
                <thead class="thead-light"><tr><th>الطالب</th><th>الكورس / المشروع</th><th>الحالة</th><th>المحاولة</th><th>وقت الإرسال</th><th></th></tr></thead>
                <tbody>
                @forelse($submissions as $submission)
                    @php
                        $section = optional($submission->project)->section;
                        $course = optional($section)->course;
                    @endphp
                    <tr>
                        <td>
                            @if($isAdministrator)
                                <strong>{{ optional($submission->user)->name ?: 'حساب محذوف' }}</strong><br><small class="text-muted">{{ optional($submission->user)->email }}</small>
                            @else
                                <strong>محاولة {{ Str::limit($submission->public_id, 12) }}</strong>
                            @endif
                        </td>
                        <td><strong>{{ optional($course)->title ?: 'كورس غير متاح' }}</strong><br><small class="text-muted">{{ optional($section)->title ?: 'مشروع #' . $submission->project_id }}</small></td>
                        <td>@include('admin.partials.status-badge', ['badgeStatus' => $submission->review_status])</td>
                        <td><span class="admin-code">{{ Str::limit($submission->public_id, 18) }}</span><br><small class="text-muted">{{ $effortLabels[$submission->effort_status] ?? 'حالة التسليم غير معروفة' }}</small></td>
                        <td>{{ $submission->submitted_at ? \App\Support\BusinessClock::format($submission->submitted_at) : '—' }}</td>
                        <td><a href="{{ route('admin.project-submissions.show', $submission) }}" class="btn btn-sm btn-outline-primary"><i class="fa fa-eye"></i> مراجعة</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">لا توجد محاولات مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($submissions->hasPages())<div class="card-footer">{{ $submissions->links() }}</div>@endif
    </div>
</div>
@endsection
