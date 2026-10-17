@extends('admin.layouts.app')

@section('page.title', 'سجل التدقيق')

@section('content')
@php
    $subjectLabels = [
        'Course' => 'كورس', 'CourseAccessPlan' => 'فئة سعرية', 'Package' => 'باقة', 'Coupon' => 'كوبون',
        'CourseCode' => 'كود', 'CoinEarningMethod' => 'طريقة ربح', 'RewardRule' => 'قاعدة مكافأة',
        'OperatingCostPool' => 'مركز تكلفة', 'Setting' => 'الإعدادات', 'User' => 'حساب',
    ];
    $eventLabels = ['created' => 'إنشاء', 'updated' => 'تعديل', 'deleted' => 'حذف'];
    $formatValue = static fn ($value): string => $value === null ? '—' : (is_bool($value) ? ($value ? 'نعم' : 'لا') : (string) $value);
@endphp
<div class="admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'سجل التدقيق',
        'pageDescription' => 'كل تعديل من لوحة التحكم: من نفّذه ومتى ونتيجته، مع القيم قبل وبعد للأسعار والعملات والصلاحيات. للقراءة فقط.',
        'pageIcon' => 'fa-history',
    ])

    <div class="card admin-card mb-4">
        <div class="card-body">
            <form method="GET">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="audit-actor">المنفّذ</label>
                        <select id="audit-actor" class="form-control" name="actor_id">
                            <option value="">الكل</option>
                            @foreach($actors as $actor)
                                <option value="{{ $actor->id }}" @selected((string) ($filters['actor_id'] ?? '') === (string) $actor->id)>{{ $actor->name ?: $actor->name_ar }} · {{ $actor->role === 'admin' ? 'مسؤول' : 'محتوى' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="audit-action">الإجراء أو المسار</label>
                        <input id="audit-action" class="form-control" name="action" dir="ltr" maxlength="120" value="{{ $filters['action'] ?? '' }}" placeholder="admin.coupons">
                    </div>
                    <div class="col-md-2 mb-3"><label for="audit-from">من</label><input id="audit-from" class="form-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
                    <div class="col-md-2 mb-3"><label for="audit-to">إلى</label><input id="audit-to" class="form-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
                </div>
                <div class="admin-actions">
                    <button class="btn btn-primary" type="submit"><i class="fa fa-filter"></i> تطبيق</button>
                    <a href="{{ route('admin.audit-log.index') }}" class="btn btn-light">مسح</a>
                </div>
            </form>
        </div>
    </div>

    @unless($recordsChanges)
        <div class="alert alert-warning">شغّل migrations لتسجيل القيم قبل وبعد التعديل.</div>
    @endunless

    <div class="card admin-card">
        <div class="table-responsive">
            <table class="table table-hover admin-table mb-0 text-right">
                <thead class="thead-light"><tr><th>الوقت</th><th>المنفّذ</th><th>الإجراء</th><th>النتيجة</th><th>القيم قبل وبعد</th></tr></thead>
                <tbody>
                @forelse($entries as $entry)
                    <tr>
                        <td class="text-nowrap">{{ \App\Support\BusinessClock::format($entry->occurred_at) }}</td>
                        <td>
                            @if($entry->actor_id)
                                <strong>{{ $entry->actor?->name ?: ($entry->actor?->name_ar ?: 'حساب محذوف') }}</strong>
                            @else
                                <strong>سطر الأوامر</strong>
                            @endif
                            <br><small class="text-muted">{{ $entry->actor_role }}</small>
                        </td>
                        <td>
                            <bdi dir="ltr" class="admin-value--ltr">{{ $entry->http_method }} {{ $entry->route_name ?: $entry->path }}</bdi>
                            @if(!empty($entry->route_parameters))
                                <br><small class="text-muted"><bdi dir="ltr">@foreach((array) $entry->route_parameters as $key => $value){{ $key }}={{ is_scalar($value) ? $value : '…' }}@if(!$loop->last), @endif @endforeach</bdi></small>
                            @endif
                        </td>
                        <td><bdi dir="ltr">{{ $entry->response_status }}</bdi></td>
                        <td>
                            @forelse((array) ($entry->changes ?? []) as $change)
                                <div class="mb-1">
                                    <strong>{{ $subjectLabels[$change['subject'] ?? ''] ?? ($change['subject'] ?? '') }} #<bdi>{{ $change['id'] ?? '' }}</bdi></strong>
                                    <small class="text-muted">{{ $eventLabels[$change['event'] ?? ''] ?? '' }}</small>
                                    @foreach(array_unique(array_merge(array_keys((array) ($change['old'] ?? [])), array_keys((array) ($change['attributes'] ?? [])))) as $field)
                                        <br><small><bdi dir="ltr">{{ $field }}: {{ $formatValue($change['old'][$field] ?? null) }} → {{ $formatValue($change['attributes'][$field] ?? null) }}</bdi></small>
                                    @endforeach
                                </div>
                            @empty
                                <small class="text-muted">{{ !empty($entry->request_fields) ? 'الحقول: '.implode('، ', array_slice((array) $entry->request_fields, 0, 8)) : '—' }}</small>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5">لا توجد عمليات مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $entries->links() }}</div>
</div>
@endsection
