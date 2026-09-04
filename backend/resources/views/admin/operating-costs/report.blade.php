@extends('admin.layouts.app')
@section('page.title', 'اقتصاديات التشغيل')
@section('content')
<div class="admin-page animated fadeIn">
    @include('admin.partials.page-header', [
        'pageTitle' => 'اقتصاديات التشغيل والتسعير',
        'pageDescription' => 'من دفع ماذا، واستهلك ماذا، وما تكلفته الفعلية حتى الآن — على مستوى المنصة والكورس والباقة والطالب.',
        'pageIcon' => 'fa-line-chart',
    ])

    <div class="d-flex flex-wrap justify-content-between mb-3">
        <div class="alert alert-info mb-2 py-2">
            الفترة: كل التاريخ المسجل. الفواتير النهائية فعلية، والتقديرات ظاهرة منفصلة ولا تتحول إلى ربح فعلي.
        </div>
        <div>
            <a class="btn btn-light" href="{{ route('admin.operating-costs.index') }}">إدارة فواتير الخدمات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.operating-costs.report.export', request()->except(['page', 'per_page'])) }}">
                <i class="fa fa-download ml-1"></i> تصدير كل الطلاب CSV
            </a>
        </div>
    </div>

    <div class="card admin-card mb-4"><div class="card-body">
        <form method="GET" action="{{ route('admin.operating-costs.report') }}" class="form-row">
            <div class="form-group col-lg-3 col-md-6"><label>بحث عن طالب أو كورس</label><input name="q" maxlength="160" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="الاسم أو البريد أو الكورس"></div>
            <div class="form-group col-lg-2 col-md-6"><label>الكورس</label><select name="course_id" class="form-control"><option value="">كل الكورسات</option>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((string) ($filters['course_id'] ?? '') === (string) $course->id)>{{ $course->name_ar }}</option>@endforeach</select></div>
            <div class="form-group col-lg-2 col-md-6"><label>الفئة السعرية</label><select name="plan" class="form-control"><option value="">كل الفئات</option>@foreach($report['filter_options']['plans'] as $plan)@php($planValue = $plan['code'] !== '' ? $plan['code'] : $plan['name'])<option value="{{ $planValue }}" @selected(($filters['plan'] ?? '') === $planValue)>{{ $plan['name'] }}</option>@endforeach</select></div>
            <div class="form-group col-lg-2 col-md-6"><label>مصدر الإتاحة</label><select name="source" class="form-control"><option value="">كل المصادر</option>@foreach($report['filter_options']['sources'] as $source)<option value="{{ $source['code'] }}" @selected(($filters['source'] ?? '') === $source['code'])>{{ $source['name'] }}</option>@endforeach</select></div>
            <div class="form-group col-lg-1 col-md-3"><label>صفوف</label><select name="per_page" class="form-control">@foreach([20,30,50,100] as $size)<option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 30) === $size)>{{ $size }}</option>@endforeach</select></div>
            <div class="form-group col-lg-2 col-md-9 d-flex align-items-end"><button class="btn btn-primary ml-2">تطبيق</button><a class="btn btn-light" href="{{ route('admin.operating-costs.report') }}">مسح</a></div>
        </form>
    </div></div>

    <div class="statistics-grid">
        <div class="stat-card"><span class="stat-counter">{{ number_format($report['unique_students']) }}</span><span class="stat-label">طلاب مختلفون</span></div>
        <div class="stat-card"><span class="stat-counter">{{ number_format($report['active_enrollments']) }}</span><span class="stat-label">تسجيلات نشطة</span></div>
        <div class="stat-card"><span class="stat-counter">{{ number_format($report['gross_egp'], 2) }} ج.م</span><span class="stat-label">نقد منسوب للكورسات</span></div>
        <div class="stat-card"><span class="stat-counter">{{ $report['net_egp'] === null ? 'بانتظار التسوية' : number_format($report['net_egp'], 2).' ج.م' }}</span><span class="stat-label">صافي بوابة الدفع</span></div>
        <div class="stat-card"><span class="stat-counter">{{ $report['service_cost_egp'] === null ? 'بيانات ناقصة' : number_format($report['service_cost_egp'], 2).' ج.م' }}</span><span class="stat-label">تكلفة تشغيل فعلية</span></div>
        <div class="stat-card"><span class="stat-counter">{{ $report['margin_egp'] === null ? 'غير مكتمل' : number_format($report['margin_egp'], 2).' ج.م' }}</span><span class="stat-label">هامش المساهمة</span></div>
        <div class="stat-card"><span class="stat-counter">{{ $report['cost_to_net_revenue_percentage'] === null ? '—' : number_format($report['cost_to_net_revenue_percentage'], 2).'%' }}</span><span class="stat-label">التكلفة من صافي السعر</span></div>
        <div class="stat-card"><span class="stat-counter">{{ $report['contribution_margin_percentage'] === null ? '—' : number_format($report['contribution_margin_percentage'], 2).'%' }}</span><span class="stat-label">نسبة هامش المساهمة</span></div>
        <div class="stat-card"><span class="stat-counter">{{ $report['average_net_per_student_egp'] === null ? '—' : number_format($report['average_net_per_student_egp'], 2).' ج.م' }}</span><span class="stat-label">متوسط الصافي لكل طالب</span></div>
        <div class="stat-card"><span class="stat-counter">{{ $report['average_cost_per_student_egp'] === null ? '—' : number_format($report['average_cost_per_student_egp'], 2).' ج.م' }}</span><span class="stat-label">متوسط التكلفة لكل طالب</span></div>
        <div class="stat-card"><span class="stat-counter">{{ ($report['ai_measurement_available'] ?? true) ? '$'.number_format($report['ai_cost_usd'], 6) : 'غير متاح' }}</span><span class="stat-label">OpenRouter · {{ number_format($report['ai_requests']) }} ناجح · {{ number_format($report['ai_failed_requests']) }} فاشل</span>@if(($report['ai_estimated_requests'] ?? 0) > 0)<small class="text-warning">يتضمن {{ number_format($report['ai_estimated_requests']) }} ردًا بتكلفة تقديرية</small>@endif</div>
        <div class="stat-card"><span class="stat-counter">{{ number_format($report['playback_gb_estimated'], 3) }} GB</span><span class="stat-label">{{ number_format($report['playback_minutes'], 0) }} دقيقة فيديو</span></div>
    </div>

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
        <thead><tr><th>الخدمة</th><th>الاستهلاك المقاس</th><th>تكلفة فعلية</th><th>من إجمالي التكلفة</th><th>شاملة التقديرات</th><th>ملاحظة القرار</th></tr></thead>
        <tbody>@foreach($report['service_breakdown'] as $service)<tr>
            <td>{{ $service['label'] }}</td>
            <td>@if($service['key'] === 'openrouter'){{ number_format($service['requests']) }} ناجح · {{ number_format($service['failed_requests']) }} فاشل · {{ number_format($service['units']) }} توكن<br><small>${{ number_format($service['cost_usd'], 6) }}@if($report['ai_cost_per_1000_tokens_usd'] !== null) · ${{ number_format($report['ai_cost_per_1000_tokens_usd'], 6) }}/1000 توكن@endif @if($report['ai_failure_rate_percentage'] !== null)· فشل {{ number_format($report['ai_failure_rate_percentage'], 2) }}%@endif</small>@elseif($service['key'] === 'bunny_delivery'){{ number_format($service['minutes'], 0) }} دقيقة · {{ number_format($service['units'], 3) }} GB@elseif($service['key'] === 'notifications'){{ number_format($service['in_app_notifications']) }} داخل التطبيق · {{ number_format($service['push_attempts']) }} محاولة Push · {{ number_format($service['push_provider_accepted']) }} قبله المزود@if($service['push_provider_acceptance_rate_percentage'] !== null) · {{ number_format($service['push_provider_acceptance_rate_percentage'], 2) }}%@endif @else<span class="text-muted">توزيع الفاتورة حسب مسبب التكلفة</span>@endif</td>
            <td>{{ $service['actual_egp'] === null ? 'غير مكتملة' : number_format($service['actual_egp'], 2).' ج.م' }}</td>
            <td>{{ $service['share_of_actual_cost_percentage'] === null ? '—' : number_format($service['share_of_actual_cost_percentage'], 2).'%' }}</td>
            <td>{{ $service['with_estimates_egp'] === null ? 'غير مكتملة' : number_format($service['with_estimates_egp'], 2).' ج.م' }}</td>
            <td>@if($service['actual_egp'] === null)<span class="text-warning">أكمل فاتورتها/سعر تحويلها</span>@elseif((float) $service['actual_egp'] === 0.0)<span class="text-muted">لا توجد تكلفة مسجلة بعد</span>@else<span class="text-success">داخلة في الهامش</span>@endif</td>
        </tr>@endforeach</tbody>
    </table></div></div>
    <div class="alert alert-light border mt-2 py-2">
        رسوم كاشير لا تُضاف مرة ثانية هنا؛ هي مخصومة أصلًا عند استخدام «صافي بوابة الدفع». أدخل فقط تكلفة لم تدخل في الصافي حتى لا تُحسب مرتين.
    </div>

    <div class="row mt-4">
        <div class="col-xl-6 mb-4"><div class="card admin-card h-100"><div class="card-header"><strong>حسب الفئة السعرية</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الفئة</th><th>طلاب فريدون</th><th>تسجيلات</th><th>صافي/طالب</th><th>تكلفة/طالب</th><th>التكلفة من الصافي</th><th>الهامش</th></tr></thead><tbody>@forelse($report['plan_breakdown'] as $code => $row)<tr><td>{{ $row['plan_name'] }}@if($code)<br><small class="text-muted">{{ $code }}</small>@endif</td><td>{{ number_format($row['students']) }}</td><td>{{ number_format($row['enrollments']) }}</td><td>{{ $row['average_net_per_student_egp'] === null ? '—' : number_format($row['average_net_per_student_egp'], 2) }}</td><td>{{ $row['average_cost_per_student_egp'] === null ? '—' : number_format($row['average_cost_per_student_egp'], 2) }}</td><td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td><td>{{ $row['margin_egp'] === null ? '—' : number_format($row['margin_egp'], 2).' ج.م' }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">لا توجد بيانات.</td></tr>@endforelse</tbody></table></div></div></div>
        <div class="col-xl-6 mb-4"><div class="card admin-card h-100"><div class="card-header"><strong>حسب الكورس</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الكورس</th><th>طلاب فريدون</th><th>الصافي</th><th>التكلفة</th><th>النسبة</th><th></th></tr></thead><tbody>@forelse($report['course_breakdown'] as $row)<tr><td>{{ $row['course_name'] }} @if($row['course_archived'])<small class="badge badge-secondary">مؤرشف</small>@endif</td><td>{{ number_format($row['students']) }}</td><td>{{ $row['net_egp'] === null ? '—' : number_format($row['net_egp'], 2) }}</td><td>{{ $row['service_cost_egp'] === null ? '—' : number_format($row['service_cost_egp'], 2) }}</td><td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td><td>@if($row['course_archived'])<a href="{{ route('admin.courses.index', ['state' => 'archived', 'search' => $row['course_name']]) }}">الأرشيف</a>@else<a href="{{ route('admin.courses.show', ['course' => $row['course_id'], 'tab' => 'commercial-report']) }}#commercial-report">التفاصيل</a>@endif</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">لا توجد بيانات.</td></tr>@endforelse</tbody></table></div></div></div>
    </div>

    <div class="card admin-card mb-4"><div class="card-header"><strong>حسب مصدر الإتاحة</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>المصدر</th><th>طلاب فريدون</th><th>تسجيلات</th><th>الصافي</th><th>التكلفة</th><th>الهامش</th><th>التكلفة من الصافي</th></tr></thead><tbody>@forelse($report['source_breakdown'] as $name => $row)<tr><td>{{ $name }}</td><td>{{ number_format($row['students']) }}</td><td>{{ number_format($row['enrollments']) }}</td><td>{{ $row['net_egp'] === null ? '—' : number_format($row['net_egp'], 2).' ج.م' }}</td><td>{{ $row['service_cost_egp'] === null ? '—' : number_format($row['service_cost_egp'], 2).' ج.م' }}</td><td>{{ $row['margin_egp'] === null ? '—' : number_format($row['margin_egp'], 2).' ج.م' }}</td><td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">لا توجد بيانات.</td></tr>@endforelse</tbody></table></div></div>

    <div class="card admin-card"><div class="card-header"><strong>كل طالب حتى الآن</strong></div><div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>الطالب</th><th>الكورسات والفئات</th><th>الاستهلاك</th><th>صافي الدخل</th><th>تكلفة الخدمات</th><th>النسبة</th><th>الهامش</th><th>تفصيل الخدمات</th></tr></thead>
        <tbody>@forelse($students as $row)<tr>
            <td><strong>{{ $row['user']?->name ?? 'مستخدم محذوف' }}</strong><br><small class="text-muted">{{ $row['user']?->email }}</small></td>
            <td>
                {{ $row['courses']->implode('، ') }}
                <br><small>{{ $row['plans']->implode('، ') }} · {{ $row['sources']->implode('، ') }}</small>
                @if($row['payment_channels']->isNotEmpty())<br><small>{{ $row['payment_channels']->implode('، ') }}</small>@endif
                @if(!$row['coin_allocation_complete'])<br><small class="text-warning">ربط الدفتر غير مكتمل</small>@endif
            </td>
            <td>{{ number_format($row['ai_requests']) }} AI ناجح · {{ number_format($row['ai_failed_requests']) }} فاشل@if($row['ai_failure_rate_percentage'] !== null) ({{ number_format($row['ai_failure_rate_percentage'], 2) }}%)@endif · {{ number_format($row['ai_tokens']) }} توكن<br>{{ number_format($row['playback_minutes'], 0) }} دقيقة · {{ number_format($row['playback_gb_estimated'], 3) }} GB<br>{{ number_format($row['in_app_notifications']) }} إشعار · {{ number_format($row['push_attempts']) }} Push / {{ number_format($row['push_provider_accepted']) }} قبله المزود@if($row['push_provider_acceptance_rate_percentage'] !== null) ({{ number_format($row['push_provider_acceptance_rate_percentage'], 2) }}%)@endif</td>
            <td>{{ $row['net_egp'] === null ? 'غير مكتمل' : number_format($row['net_egp'], 2).' ج.م' }}</td>
            <td>{{ $row['service_cost_egp'] === null ? 'غير مكتملة' : number_format($row['service_cost_egp'], 2).' ج.م' }}</td>
            <td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td>
            <td>{{ $row['margin_egp'] === null ? '—' : number_format($row['margin_egp'], 2).' ج.م' }}</td>
            <td><details><summary>عرض</summary>@foreach(\App\Services\CourseCostReportService::serviceLabels() as $key => $label)<div><small>{{ $label }}: {{ $row['actual_cost_by_service_egp']->get($key) === null ? 'ناقص' : number_format($row['actual_cost_by_service_egp']->get($key), 2).' ج.م' }}</small></div>@endforeach</details></td>
        </tr>@empty<tr><td colspan="8" class="text-center text-muted py-4">لا توجد نتائج مطابقة.</td></tr>@endforelse</tbody>
    </table></div>@if($students->hasPages())<div class="card-footer">{{ $students->links() }}</div>@endif</div>
</div>
@endsection
