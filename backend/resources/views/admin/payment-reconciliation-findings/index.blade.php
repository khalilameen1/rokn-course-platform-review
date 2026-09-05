@extends('admin.layouts.app')

@section('page.title', 'مراجعة تسوية المدفوعات')

@section('content')
@php
    $stateLabels = [
        'open' => 'مفتوحة',
        'resolved' => 'محلولة',
        'ignored' => 'متجاهلة',
    ];
    $stateTones = [
        'open' => 'warning',
        'resolved' => 'success',
        'ignored' => 'muted',
    ];
    $kindLabels = [
        'provider_unavailable' => 'تعذر الاتصال بمزود الدفع',
        'provider_status_missing' => 'المزود لم يُرجع حالة',
        'captured_evidence_mismatch' => 'بيانات التحصيل لا تطابق الطلب',
        'captured_transaction_conflict' => 'مرجع التحصيل مرتبط بعملية أخرى',
        'captured_local_fulfillment_blocked' => 'تم التحصيل ولم يكتمل التسليم',
        'provider_reversal_requires_review' => 'المزود عكس عملية مكتملة',
        'provider_failed_local_settled' => 'المزود رفض عملية مسجلة محليًا كمكتملة',
        'provider_pending_after_local_expiry' => 'العملية ما زالت معلقة لدى المزود بعد انتهائها محليًا',
        'provider_local_status_mismatch' => 'حالة المزود تختلف عن حالة الطلب',
        'late_capture_overlaps_newer_payment' => 'تحصيل متأخر يتداخل مع محاولة أحدث',
        'capture_after_account_deletion' => 'تم التحصيل بعد حذف الحساب',
        'reconciliation_exception' => 'تعذر إكمال فحص المطابقة',
    ];
    $kindActions = [
        'provider_unavailable' => 'أعد المراجعة بعد عودة المزود',
        'provider_status_missing' => 'تحقق من العملية في لوحة المزود',
        'captured_evidence_mismatch' => 'طابق المبلغ والعملة ومرجع العملية',
        'captured_transaction_conflict' => 'تحقق من الطلب الصحيح قبل أي تسوية',
        'captured_local_fulfillment_blocked' => 'راجع سبب تعطل التسليم في الطلب',
        'provider_reversal_requires_review' => 'راجع الاسترداد وأثره المالي',
        'provider_failed_local_settled' => 'راجع دليل الدفع قبل تسجيل القرار',
        'provider_pending_after_local_expiry' => 'انتظر نتيجة نهائية أو تحقق لدى المزود',
        'provider_local_status_mismatch' => 'طابق حالة الطلب مع دليل المزود',
        'late_capture_overlaps_newer_payment' => 'راجع المحاولتين قبل أي تدخل',
        'capture_after_account_deletion' => 'صعّد العملية للدعم المالي',
        'reconciliation_exception' => 'راجع اتصال المزود ثم أعد الفحص',
    ];
    $totalFindings = collect($stateCounts)->sum();
@endphp

<div class="admin-page">
    @include('admin.payments.partials.navigation')

    @include('admin.partials.page-header', [
        'pageTitle' => 'مراجعة تسوية المدفوعات',
        'pageDescription' => 'طابور بشري لمراجعة اختلافات بوابة الدفع. القرارات هنا توثّق المراجعة فقط ولا تغيّر الطلب أو الرصيد.',
        'pageIcon' => 'fa-balance-scale',
    ])

    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            @include('admin.partials.metric-card', [
                'metricValue' => number_format($stateCounts['open'] ?? 0),
                'metricLabel' => 'مفتوحة للمراجعة',
                'metricIcon' => 'fa-exclamation-circle',
                'metricHref' => route('admin.payment-reconciliation-findings.index', ['state' => 'open']),
            ])
        </div>
        <div class="col-md-3 mb-3">
            @include('admin.partials.metric-card', [
                'metricValue' => number_format($stateCounts['resolved'] ?? 0),
                'metricLabel' => 'محلولة',
                'metricIcon' => 'fa-check-circle',
                'metricHref' => route('admin.payment-reconciliation-findings.index', ['state' => 'resolved']),
            ])
        </div>
        <div class="col-md-3 mb-3">
            @include('admin.partials.metric-card', [
                'metricValue' => number_format($stateCounts['ignored'] ?? 0),
                'metricLabel' => 'متجاهلة',
                'metricIcon' => 'fa-ban',
                'metricHref' => route('admin.payment-reconciliation-findings.index', ['state' => 'ignored']),
            ])
        </div>
        <div class="col-md-3 mb-3">
            @include('admin.partials.metric-card', [
                'metricValue' => number_format($totalFindings),
                'metricLabel' => 'إجمالي النتائج',
                'metricIcon' => 'fa-list-alt',
                'metricHref' => route('admin.payment-reconciliation-findings.index', ['state' => '']),
            ])
        </div>
    </div>

    <div class="card admin-card mb-4">
        <div class="card-header"><strong>تصفية طابور المراجعة</strong></div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.payment-reconciliation-findings.index') }}">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="state">الحالة</label>
                        <select id="state" name="state" class="form-control">
                            <option value="" @selected(array_key_exists('state', $filters) && $filters['state'] === null)>كل الحالات</option>
                            @foreach($stateLabels as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['state'] ?? null) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="kind">نوع النتيجة</label>
                        <select id="kind" name="kind" class="form-control">
                            <option value="">كل الأنواع</option>
                            @foreach($kinds as $kind)
                                <option value="{{ $kind }}" @selected(($filters['kind'] ?? '') === $kind)>{{ $kindLabels[$kind] ?? 'نتيجة تحتاج مراجعة' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label for="order-ref">مرجع الطلب</label>
                        <input id="order-ref" name="order_ref" class="form-control admin-value--ltr" value="{{ $filters['order_ref'] ?? '' }}" maxlength="191" placeholder="ابحث بجزء من مرجع الطلب">
                    </div>
                </div>
                <div class="admin-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> تطبيق</button>
                    <a href="{{ route('admin.payment-reconciliation-findings.index') }}" class="btn btn-light">مسح</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card admin-card">
        <div class="table-responsive">
            <table class="table table-hover admin-table mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>آخر رصد</th>
                        <th>الطلب</th>
                        <th>النتيجة</th>
                        <th>لقطة الحالات</th>
                        <th>المحاولات</th>
                        <th>حالة المراجعة</th>
                        <th class="admin-table__wide-action">القرار البشري</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($findings as $finding)
                    <tr>
                        <td>
                            <span class="admin-value--ltr">{{ optional($finding->last_seen_at)->format('Y-m-d H:i') ?: '—' }}</span>
                            <br><small class="text-muted">أول رصد: {{ optional($finding->first_seen_at)->format('Y-m-d H:i') ?: '—' }}</small>
                        </td>
                        <td>
                            @if($finding->order)
                                <a class="admin-code" href="{{ route('admin.orders.show', $finding->order) }}">{{ $finding->order_ref }}</a>
                            @else
                                <span class="admin-code">{{ $finding->order_ref }}</span>
                            @endif
                            <br><small class="text-muted">{{ $finding->provider }} · {{ $finding->provider_transaction_id ?: 'بلا رقم معاملة' }}</small>
                        </td>
                        <td>
                            <strong>{{ $kindLabels[$finding->kind] ?? 'نتيجة تحتاج مراجعة' }}</strong>
                            <div class="mt-1 text-muted">{{ $kindActions[$finding->kind] ?? 'راجع الطلب ودليل المزود قبل تسجيل القرار' }}</div>
                            @if(!empty($finding->evidence))
                                <details class="mt-2">
                                    <summary>تفاصيل المطابقة</summary>
                                    <pre class="admin-copy admin-value--ltr mb-0">{{ json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </td>
                        <td>
                            <div class="admin-detail-label">محلي</div>
                            <div class="admin-detail-value admin-code">{{ $finding->local_status ?: '—' }} / {{ $finding->local_financial_status ?: '—' }}</div>
                            <div class="admin-detail-label mt-2">البوابة</div>
                            <div class="admin-detail-value admin-code">{{ $finding->provider_status ?: '—' }}</div>
                        </td>
                        <td>{{ number_format($finding->attempts) }}</td>
                        <td>
                            @include('admin.partials.status-badge', [
                                'badgeStatus' => $finding->state,
                                'badgeLabel' => $stateLabels[$finding->state] ?? $finding->state,
                                'badgeTone' => $stateTones[$finding->state] ?? 'muted',
                            ])
                            @if($finding->resolution_note)
                                <div class="mt-2">{{ $finding->resolution_note }}</div>
                                <small class="text-muted">{{ optional($finding->resolver)->name ?: 'آخر قرار مسجل' }}</small>
                            @endif
                        </td>
                        <td>
                            @if($finding->state === 'open')
                                @include('admin.payment-reconciliation-findings.partials.action-form', [
                                    'actionRoute' => route('admin.payment-reconciliation-findings.resolve', $finding),
                                    'noteId' => 'resolve-note-' . $finding->id,
                                    'noteLabel' => 'ملاحظة الحل',
                                    'notePlaceholder' => 'ما الذي تحققت منه قبل اعتبار النتيجة محلولة؟',
                                    'actionClass' => 'btn-success',
                                    'actionIcon' => 'fa-check',
                                    'actionLabel' => 'تعليم كمحلولة',
                                ])
                                @include('admin.payment-reconciliation-findings.partials.action-form', [
                                    'actionRoute' => route('admin.payment-reconciliation-findings.ignore', $finding),
                                    'noteId' => 'ignore-note-' . $finding->id,
                                    'noteLabel' => 'سبب التجاهل',
                                    'notePlaceholder' => 'لماذا لا تستدعي هذه النتيجة إجراءً؟',
                                    'actionClass' => 'btn-outline-secondary',
                                    'actionIcon' => 'fa-ban',
                                    'actionLabel' => 'تجاهل',
                                ])
                            @else
                                @include('admin.payment-reconciliation-findings.partials.action-form', [
                                    'actionRoute' => route('admin.payment-reconciliation-findings.reopen', $finding),
                                    'noteId' => 'reopen-note-' . $finding->id,
                                    'noteLabel' => 'سبب إعادة الفتح',
                                    'notePlaceholder' => 'ما سبب الحاجة إلى مراجعة جديدة؟',
                                    'actionClass' => 'btn-warning',
                                    'actionIcon' => 'fa-repeat',
                                    'actionLabel' => 'إعادة الفتح',
                                ])
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-5">لا توجد نتائج مطابقة للفلاتر الحالية.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $findings->links() }}</div>
</div>
@endsection
