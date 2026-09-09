@extends('admin.layouts.app')
@section('page.title', 'اقتصاديات التشغيل')
@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-reports.css') }}">
@endsection
@section('content')
<div class="admin-page admin-report">
    @include('admin.partials.page-header', [
        'pageTitle' => 'اقتصاديات التشغيل والتسعير',
        'pageDescription' => 'من دفع ماذا، واستهلك ماذا، وما تكلفته الفعلية حتى الآن — على مستوى المنصة والكورس والباقة والطالب.',
        'pageIcon' => 'fa-line-chart',
    ])

    <div class="admin-report__toolbar">
        <div class="admin-report__context">
            <span class="admin-report__period">{{ $report['period']->label() }}</span>
            <span>{{ $report['period']->description() }}</span>
            <p>مبالغ OpenRouter مؤكدة من المزود بالدولار؛ الفواتير المسجلة تُحتسب مرة واحدة دون توزيع على الطلاب.</p>
        </div>
        <div class="admin-report__actions">
            <a class="btn btn-light" href="{{ route('admin.operating-costs.index') }}">إدارة فواتير الخدمات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.operating-costs.report.export', request()->except(['page', 'per_page'])) }}">
                <i class="fa fa-download ml-1"></i> تصدير كل الطلاب CSV
            </a>
        </div>
    </div>

    <div class="card admin-card admin-report__filter-card mb-4"><div class="card-body">
        <form method="GET" action="{{ route('admin.operating-costs.report') }}" class="admin-report__filters" aria-label="تصفية التقرير المالي">
            <div class="form-group">@include('admin.reports.period-fields', ['period' => $report['period']])</div>
            <div class="form-group admin-report__search"><label for="report-search">بحث عن طالب أو كورس</label><input id="report-search" name="q" maxlength="160" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="الاسم أو البريد أو الكورس"></div>
            <div class="form-group"><label for="report-course">الكورس</label><select id="report-course" name="course_id" class="form-control"><option value="">كل الكورسات</option>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((string) ($filters['course_id'] ?? '') === (string) $course->id)>{{ $course->name_ar }}</option>@endforeach</select></div>
            <div class="form-group"><label for="report-plan">الفئة السعرية</label><select id="report-plan" name="plan" class="form-control"><option value="">كل الفئات</option>@foreach($report['filter_options']['plans'] as $plan)@php($planValue = $plan['code'] !== '' ? $plan['code'] : $plan['name'])<option value="{{ $planValue }}" @selected(($filters['plan'] ?? '') === $planValue)>{{ $plan['name'] }}</option>@endforeach</select></div>
            <div class="form-group"><label for="report-source">مصدر الإتاحة</label><select id="report-source" name="source" class="form-control"><option value="">كل المصادر</option>@foreach($report['filter_options']['sources'] as $source)<option value="{{ $source['code'] }}" @selected(($filters['source'] ?? '') === $source['code'])>{{ $source['name'] }}</option>@endforeach</select></div>
            <div class="form-group"><label for="report-page-size">صفوف</label><select id="report-page-size" name="per_page" class="form-control">@foreach([20,30,50,100] as $size)<option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 30) === $size)>{{ $size }}</option>@endforeach</select></div>
            <div class="admin-report__filter-actions"><button type="submit" class="btn btn-primary">تطبيق</button><a class="btn btn-light" href="{{ route('admin.operating-costs.report') }}">مسح</a></div>
        </form>
    </div></div>

    <h2 class="admin-report__section-title">ملخص الفترة</h2>
    <div class="statistics-grid admin-report__kpis">
        <div class="stat-card">
            <span class="stat-label">نقد منسوب للكورسات</span>
            <strong class="stat-counter">{{ number_format($report['gross_egp'], 2) }} <small>ج.م</small></strong>
            @include('admin.reports.growth', ['change' => $report['comparisons']['gross_egp'], 'neutral' => true])
        </div>
        <div class="stat-card">
            <span class="stat-label">صافي بوابة الدفع</span>
            <strong @class(['stat-counter', 'stat-counter--status' => $report['net_egp'] === null])>{{ $report['net_egp'] === null ? 'بانتظار التسوية' : number_format($report['net_egp'], 2).' ج.م' }}</strong>
            @include('admin.reports.growth', ['change' => $report['comparisons']['net_egp'], 'neutral' => true])
            <dl class="admin-report__details"><div><dt>متوسط الصافي لكل طالب</dt><dd>{{ $report['average_net_per_student_egp'] === null ? '—' : number_format($report['average_net_per_student_egp'], 2).' ج.م' }}</dd></div></dl>
        </div>
        <div class="stat-card">
            <span class="stat-label">تكلفة تشغيل فعلية</span>
            <strong @class(['stat-counter', 'stat-counter--status' => $report['service_cost_egp'] === null])>{{ $report['service_cost_egp'] === null ? 'بيانات ناقصة' : number_format($report['service_cost_egp'], 2).' ج.م' }}</strong>
            @include('admin.reports.growth', ['change' => $report['comparisons']['service_cost_egp'], 'neutral' => true])
            <dl class="admin-report__details">
                <div><dt>التكلفة من صافي السعر</dt><dd>{{ $report['cost_to_net_revenue_percentage'] === null ? '—' : number_format($report['cost_to_net_revenue_percentage'], 2).'%' }}</dd></div>
                <div><dt>متوسط التكلفة لكل طالب</dt><dd>{{ $report['average_cost_per_student_egp'] === null ? '—' : number_format($report['average_cost_per_student_egp'], 2).' ج.م' }}</dd></div>
            </dl>
        </div>
        <div class="stat-card">
            <span class="stat-label">هامش المساهمة</span>
            <strong @class(['stat-counter', 'stat-counter--status' => $report['margin_egp'] === null])>{{ $report['margin_egp'] === null ? 'غير مكتمل' : number_format($report['margin_egp'], 2).' ج.م' }}</strong>
            @include('admin.reports.growth', ['change' => $report['comparisons']['margin_egp'], 'neutral' => true])
            <dl class="admin-report__details"><div><dt>نسبة هامش المساهمة</dt><dd>{{ $report['contribution_margin_percentage'] === null ? '—' : number_format($report['contribution_margin_percentage'], 2).'%' }}</dd></div></dl>
        </div>
        <div class="stat-card"><span class="stat-label">طلاب مختلفون</span><strong class="stat-counter">{{ number_format($report['unique_students']) }}</strong></div>
        <div class="stat-card"><span class="stat-label">تسجيلات نشطة</span><strong class="stat-counter">{{ number_format($report['active_enrollments']) }}</strong></div>
        <div class="stat-card">
            <span class="stat-label">OpenRouter مؤكد</span>
            <strong @class(['stat-counter', 'stat-counter--status' => !($report['ai_measurement_available'] ?? true) || !$report['ai_cost_complete']])>
                @if($report['ai_measurement_available'] ?? true)
                    @include('admin.reports.confirmed-usd', ['amount' => $report['ai_cost_usd'], 'complete' => $report['ai_cost_complete']])
                @else
                    غير متاح
                @endif
            </strong>
            <span class="admin-report__caption">{{ number_format($report['ai_requests']) }} ناجح · {{ number_format($report['ai_failed_requests']) }} فاشل · {{ number_format($report['ai_unanswered_requests']) }} بلا نتيجة</span>
            @include('admin.reports.growth', ['change' => $report['comparisons']['ai_cost_usd'], 'neutral' => true])
            @if(($report['ai_estimated_requests'] ?? 0) > 0)<small class="text-warning">{{ number_format($report['ai_estimated_requests']) }} طلبًا بانتظار تكلفة المزود</small>@endif
        </div>
        <div class="stat-card"><span class="stat-label">دقيقة فيديو مقاسة</span><strong class="stat-counter">{{ number_format($report['playback_minutes'], 0) }}</strong></div>
    </div>
    @if($report['provider_invoice_report'] !== null)
        @include('admin.reports.provider-invoices', ['invoiceReport' => $report['provider_invoice_report']])
    @else
        <div class="alert alert-info mt-3">الفلاتر تمثل الطلاب وفئاتهم ومصادر إتاحتهم الحالية؛ لا تُنسب فواتير المنصة إلى هذا التجمع.</div>
    @endif

    @if($report['net_egp'] === null || $report['service_cost_egp'] === null)
        <div class="alert alert-warning mt-3">
            لن يعرض النظام ربحًا أو نسبة تكلفة نهائية قبل اكتمال صافي تسويات كاشير وفواتير التشغيل وسعر تحويل تكلفة OpenRouter.
            @foreach($report['cost_warnings'] as $warning)<div>• {{ $warning }}</div>@endforeach
        </div>
    @endif
    @if(!$report['coin_allocation_complete'])
        <div class="alert alert-warning mt-3">
            بعض عمليات الكورسات غير مرتبطة بدفتر العملات
            حُجبت قيمها من الدخل بدل الاعتماد على حقول غير قابلة للمراجعة
        </div>
    @endif

    <div class="card admin-card mt-4"><div class="card-header"><strong>الخدمات المدفوعة</strong></div><div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>الخدمة</th><th>الاستهلاك المقاس</th><th>تكلفة مؤكدة بالجنيه</th><th>من إجمالي التكلفة</th><th>ملاحظة القرار</th></tr></thead>
        <tbody>@foreach($report['service_breakdown'] as $service)<tr>
            <td>{{ $service['label'] }}</td>
            <td>
                @if($service['key'] === 'openrouter')
                    {{ number_format($service['requests']) }} ناجح · {{ number_format($service['failed_requests']) }} فاشل · {{ number_format($report['ai_unanswered_requests']) }} بلا نتيجة · {{ number_format($service['units']) }} توكن<br>
                    <small>
                        @include('admin.reports.confirmed-usd', ['amount' => $service['cost_usd'], 'complete' => $report['ai_cost_complete']])
                        @if($report['ai_cost_per_1000_tokens_usd'] !== null) · ${{ number_format($report['ai_cost_per_1000_tokens_usd'], 6) }}/1000 توكن@endif
                        @if($report['ai_failure_rate_percentage'] !== null) · لم تكتمل {{ number_format($report['ai_failure_rate_percentage'], 2) }}٪ من الطلبات@endif
                    </small>
                @elseif($service['key'] === 'bunny_delivery')
                    {{ number_format($service['minutes'], 0) }} دقيقة
                @elseif($service['key'] === 'notifications')
                    {{ number_format($service['in_app_notifications']) }} داخل التطبيق · {{ number_format($service['push_attempts']) }} محاولة Push · {{ number_format($service['push_provider_accepted']) }} قبله المزود
                    @if($service['push_provider_acceptance_rate_percentage'] !== null) · {{ number_format($service['push_provider_acceptance_rate_percentage'], 2) }}٪@endif
                @else
                    <span class="text-muted">بحسب الفواتير النهائية المسجلة دون توزيع على الطلاب</span>
                @endif
            </td>
            <td>{{ $service['actual_egp'] === null ? 'غير مكتملة' : number_format($service['actual_egp'], 2).' ج.م' }}</td>
            <td>{{ $service['share_of_actual_cost_percentage'] === null ? '—' : number_format($service['share_of_actual_cost_percentage'], 2).'%' }}</td>
            <td>@if($service['actual_egp'] === null)<span class="text-warning">أكمل فاتورتها/سعر تحويلها</span>@elseif((float) $service['actual_egp'] === 0.0)<span class="text-muted">مبلغ صفري مؤكد</span>@else<span class="text-success">مبلغ مسجل مؤكد</span>@endif</td>
        </tr>@endforeach</tbody>
    </table></div></div>
    <div class="alert alert-light border mt-2 py-2">
        رسوم كاشير لا تُضاف مرة ثانية هنا؛ هي مخصومة أصلًا عند استخدام «صافي بوابة الدفع». أدخل فقط تكلفة لم تدخل في الصافي حتى لا تُحسب مرتين.
    </div>

    <div class="row mt-4">
        <div class="col-xl-6 mb-4"><div class="card admin-card h-100"><div class="card-header"><strong>الفئة وقت العملية</strong><small class="d-block text-muted">العقد المسجل وقت الشراء أو الاستخدام؛ التكلفة التشغيلية لكل فئة غير منسوبة.</small></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الفئة</th><th>صافي الدخل ج.م</th><th>OpenRouter مؤكد USD</th><th>مقارنة الفترة السابقة</th></tr></thead><tbody>@forelse($report['plan_breakdown'] as $code => $row)<tr><td>{{ $row['plan_name'] }}</td><td>{{ $row['period_metrics']['cash_net_egp'] === null ? 'غير متاح' : number_format($row['period_metrics']['cash_net_egp'], 2) }}</td><td>{{ $row['period_metrics']['ai_cost_usd'] === null ? 'غير متاح' : '$'.number_format($row['period_metrics']['ai_cost_usd'], 6) }}</td><td>@include('admin.reports.growth', ['change' => $row['comparisons']['cash_net_egp']])</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">لا توجد بيانات.</td></tr>@endforelse</tbody></table></div></div></div>
        <div class="col-xl-6 mb-4"><div class="card admin-card h-100"><div class="card-header"><strong>حسب الكورس</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الكورس</th><th>طلاب فريدون</th><th>الصافي</th><th>التكلفة</th><th>النسبة</th><th></th></tr></thead><tbody>@forelse($report['course_breakdown'] as $row)<tr><td>{{ $row['course_name'] }} @if($row['course_archived'])<small class="badge badge-secondary">مؤرشف</small>@endif</td><td>{{ number_format($row['students']) }}</td><td>{{ $row['net_egp'] === null ? '—' : number_format($row['net_egp'], 2) }}@if(isset($row['comparisons']))@include('admin.reports.growth', ['change' => $row['comparisons']['cash_net_egp']])@endif</td><td>{{ $row['service_cost_egp'] === null ? '—' : number_format($row['service_cost_egp'], 2) }}</td><td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td><td>@if($row['course_archived'])<a href="{{ route('admin.courses.index', ['state' => 'archived', 'search' => $row['course_name']]) }}">الأرشيف</a>@else<a href="{{ route('admin.courses.show', ['course' => $row['course_id'], 'tab' => 'commercial-report', 'period' => $report['period']->key]) }}#commercial-report">التفاصيل</a>@endif</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">لا توجد بيانات.</td></tr>@endforelse</tbody></table></div></div></div>
    </div>

    <div class="card admin-card mb-4"><div class="card-header"><strong>حسب مصدر الإتاحة الحالي</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>المصدر</th><th>طلاب فريدون</th><th>تسجيلات</th><th>الصافي</th><th>التكلفة</th><th>الهامش</th><th>التكلفة من الصافي</th></tr></thead><tbody>@forelse($report['source_breakdown'] as $name => $row)<tr><td>{{ $name }}</td><td>{{ number_format($row['students']) }}</td><td>{{ number_format($row['enrollments']) }}</td><td>{{ $row['net_egp'] === null ? '—' : number_format($row['net_egp'], 2).' ج.م' }}</td><td>{{ $row['service_cost_egp'] === null ? '—' : number_format($row['service_cost_egp'], 2).' ج.م' }}</td><td>{{ $row['margin_egp'] === null ? '—' : number_format($row['margin_egp'], 2).' ج.م' }}</td><td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">لا توجد بيانات.</td></tr>@endforelse</tbody></table></div></div>

    <div class="card admin-card"><div class="card-header"><strong>استهلاك ودخل الطلاب خلال الفترة</strong></div><div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>الطالب</th><th>الكورسات والفئات</th><th>الاستهلاك</th><th>صافي الدخل</th><th>تكلفة الخدمات</th><th>النسبة</th><th>الهامش</th><th>تفصيل الخدمات</th></tr></thead>
        <tbody>@forelse($students as $row)<tr>
            <td><strong>{{ $row['user']?->name ?? 'مستخدم محذوف' }}</strong><br><small class="text-muted">{{ $row['user']?->email }}</small></td>
            <td>
                {{ $row['courses']->implode('، ') }}
                <br><small>{{ $row['plans']->implode('، ') }} · {{ $row['sources']->implode('، ') }}</small>
                @if($row['payment_channels']->isNotEmpty())<br><small>{{ $row['payment_channels']->implode('، ') }}</small>@endif
                @if(!$row['coin_allocation_complete'])<br><small class="text-warning">ربط الدفتر غير مكتمل</small>@endif
            </td>
            <td>{{ number_format($row['ai_requests']) }} AI ناجح · {{ number_format($row['ai_failed_requests']) }} فاشل · {{ number_format($row['ai_unanswered_requests']) }} بلا نتيجة@if($row['ai_failure_rate_percentage'] !== null) (لم تكتمل {{ number_format($row['ai_failure_rate_percentage'], 2) }}٪)@endif · {{ number_format($row['ai_tokens']) }} توكن<br>{{ number_format($row['playback_minutes'], 0) }} دقيقة<br>{{ number_format($row['in_app_notifications']) }} إشعار · {{ number_format($row['push_attempts']) }} Push / {{ number_format($row['push_provider_accepted']) }} قبله المزود@if($row['push_provider_acceptance_rate_percentage'] !== null) ({{ number_format($row['push_provider_acceptance_rate_percentage'], 2) }}%)@endif</td>
            <td>{{ $row['net_egp'] === null ? 'غير مكتمل' : number_format($row['net_egp'], 2).' ج.م' }}</td>
            <td>{{ $row['service_cost_egp'] === null ? 'غير مكتملة' : number_format($row['service_cost_egp'], 2).' ج.م' }}</td>
            <td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td>
            <td>{{ $row['margin_egp'] === null ? '—' : number_format($row['margin_egp'], 2).' ج.م' }}</td>
            <td><details><summary>عرض</summary>@foreach(\App\Services\CourseCostReportService::serviceLabels() as $key => $label)<div><small>{{ $label }}: {{ $row['actual_cost_by_service_egp']->get($key) === null ? 'ناقص' : number_format($row['actual_cost_by_service_egp']->get($key), 2).' ج.م' }}</small></div>@endforeach</details></td>
        </tr>@empty<tr><td colspan="8" class="text-center text-muted py-4">لا توجد نتائج مطابقة.</td></tr>@endforelse</tbody>
    </table></div>@if($students->hasPages())<div class="card-footer">{{ $students->links() }}</div>@endif</div>
</div>
@endsection
