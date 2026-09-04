@extends('admin.layouts.app')
@section('page.title', 'مراكز التكلفة')
@section('content')
<div class="admin-page animated fadeIn">
    @include('admin.partials.page-header', [
        'pageTitle' => 'مراكز التكلفة',
        'pageDescription' => 'أدخل الفاتورة الفعلية وفترتها؛ النظام يوزعها بمسبب التكلفة، لا بقسمة عشوائية.',
        'pageIcon' => 'fa-calculator',
    ])
    <div class="text-left mb-3">
        <a class="btn btn-primary" href="{{ route('admin.operating-costs.report') }}">
            <i class="fa fa-line-chart ml-1"></i> تقرير كل الطلاب والتسعير
        </a>
    </div>

    <div class="row mb-4">
        <div class="col-md-4"><div class="card admin-card h-100"><div class="card-body"><div class="text-muted">فواتير نهائية ضمن الفلتر</div><div class="h3 mb-0">{{ number_format($totals['actual_egp'], 2) }} ج.م</div></div></div></div>
        <div class="col-md-4"><div class="card admin-card h-100"><div class="card-body"><div class="text-muted">تقديرات ضمن الفلتر</div><div class="h3 mb-0">{{ number_format($totals['estimated_egp'], 2) }} ج.م</div></div></div></div>
        <div class="col-md-4"><div class="card admin-card h-100"><div class="card-body"><div class="text-muted">فواتير ينقصها سعر تحويل</div><div class="h3 mb-0">{{ number_format($totals['missing_fx']) }}</div></div></div></div>
    </div>

    <div class="card admin-card mb-4"><div class="card-header"><strong>فلترة ومراجعة الفواتير</strong></div><div class="card-body">
        <form method="GET" action="{{ route('admin.operating-costs.index') }}" class="form-row">
            <div class="form-group col-md-3"><label>الخدمة</label><select name="service_key" class="form-control"><option value="">كل الخدمات</option>@foreach(\App\Models\OperatingCostPool::SERVICES as $key => $label)<option value="{{ $key }}" @selected(($filters['service_key'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="form-group col-md-3"><label>الكورس</label><select name="course_id" class="form-control"><option value="">كل النطاقات</option>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((string) ($filters['course_id'] ?? '') === (string) $course->id)>{{ $course->name_ar }}</option>@endforeach</select></div>
            <div class="form-group col-md-2"><label>من</label><input name="from" type="date" class="form-control" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="form-group col-md-2"><label>إلى</label><input name="to" type="date" class="form-control" value="{{ $filters['to'] ?? '' }}"></div>
            <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-primary ml-2">تطبيق</button><a class="btn btn-light" href="{{ route('admin.operating-costs.index') }}">مسح</a></div>
        </form>
        @if($serviceSummary->isNotEmpty())
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الخدمة</th><th>نهائي</th><th>تقديري</th></tr></thead><tbody>@foreach($serviceSummary as $service => $summary)<tr><td>{{ \App\Models\OperatingCostPool::SERVICES[$service] ?? $service }}</td><td>{{ number_format($summary['actual_egp'], 2) }} ج.م</td><td>{{ number_format($summary['estimated_egp'], 2) }} ج.م</td></tr>@endforeach</tbody></table></div>
        @endif
    </div></div>

    <div class="card admin-card mb-4"><div class="card-body">
        <form method="POST" action="{{ route('admin.operating-costs.exchange-rate') }}" class="form-inline">
            @csrf
            <label class="ml-2" for="openrouter_usd_to_egp_rate">دولار OpenRouter =</label>
            <input id="openrouter_usd_to_egp_rate" name="openrouter_usd_to_egp_rate" type="number" min="0.0001" step="0.0001" required class="form-control ml-2" value="{{ old('openrouter_usd_to_egp_rate', $settings->openrouter_usd_to_egp_rate) }}">
            <span class="ml-2">جنيه</span><button class="btn btn-primary" type="submit">حفظ سعر التحويل</button>
        </form>
    </div></div>

    <div class="card admin-card mb-4"><div class="card-header"><strong>{{ $editPool ? 'تعديل فاتورة التشغيل' : 'إضافة فاتورة تشغيل' }}</strong></div><div class="card-body">
        <form method="POST" action="{{ $editPool ? route('admin.operating-costs.update', $editPool) : route('admin.operating-costs.store') }}">
            @csrf
            <input type="hidden" name="editor_version" value="{{ $exchangeRateEditorVersion }}">
            @if($editPool) @method('PUT') @endif
            @if($editPool)<input type="hidden" name="editor_version" value="{{ $editPoolEditorVersion }}">@endif
            @unless($editPool)<input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">@endunless
            <div class="form-row">
                <div class="form-group col-md-4"><label>اسم الفاتورة</label><input name="name" class="form-control" required maxlength="160" value="{{ old('name', $editPool?->name) }}"></div>
                <div class="form-group col-md-4"><label>الخدمة</label><select name="service_key" class="form-control" required>@foreach(\App\Models\OperatingCostPool::SERVICES as $key => $label)<option value="{{ $key }}" @selected(old('service_key', $editPool?->service_key) === $key)>{{ $label }}</option>@endforeach</select></div>
                <div class="form-group col-md-4"><label>الكورس (فارغ = كل المنصة)</label><select name="course_id" class="form-control"><option value="">كل المنصة</option>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((string) old('course_id', $editPool?->course_id) === (string) $course->id)>{{ $course->name_ar }}</option>@endforeach</select></div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-2"><label>من</label><input name="period_start" type="date" class="form-control" required value="{{ old('period_start', $editPool?->period_start?->format('Y-m-d')) }}"></div>
                <div class="form-group col-md-2"><label>إلى</label><input name="period_end" type="date" class="form-control" required value="{{ old('period_end', $editPool?->period_end?->format('Y-m-d')) }}"></div>
                <div class="form-group col-md-2"><label>المبلغ</label><input name="amount" type="number" min="0" step="0.0001" class="form-control" required value="{{ old('amount', $editPool?->amount) }}"></div>
                <div class="form-group col-md-2"><label>العملة</label><select name="currency" class="form-control"><option @selected(old('currency', $editPool?->currency ?? 'EGP') === 'EGP')>EGP</option><option @selected(old('currency', $editPool?->currency) === 'USD')>USD</option></select></div>
                <div class="form-group col-md-2"><label>سعر الدولار للفاتورة</label><input name="fx_rate_to_egp" type="number" min="0.0001" step="0.0001" class="form-control" value="{{ old('fx_rate_to_egp', $editPool?->fx_rate_to_egp) }}"></div>
                <div class="form-group col-md-2"><label>طريقة التوزيع</label><select name="allocation_driver" class="form-control" required>@foreach(\App\Models\OperatingCostPool::DRIVERS as $key => $label)<option value="{{ $key }}" @selected(old('allocation_driver', $editPool?->allocation_driver) === $key)>{{ $label }}</option>@endforeach</select></div>
            </div>
            <input type="hidden" name="is_final" value="0"><label><input type="checkbox" name="is_final" value="1" {{ old('is_final', $editPool?->is_final ?? true) ? 'checked' : '' }}> فاتورة نهائية وليست تقديرًا</label>
            <div class="form-group mt-2"><label>ملاحظات</label><textarea name="notes" class="form-control" maxlength="2000">{{ old('notes', $editPool?->notes) }}</textarea></div>
            <button class="btn btn-primary" type="submit">{{ $editPool ? 'حفظ التعديل' : 'إضافة وحساب أثرها' }}</button>
            @if($editPool)<a class="btn btn-light" href="{{ route('admin.operating-costs.index') }}">إلغاء التعديل</a>@endif
        </form>
        @if($errors->any())<div class="alert alert-danger mt-3"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    </div></div>

    <div class="card admin-card"><div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>الفاتورة</th><th>الفترة</th><th>النطاق</th><th>المبلغ</th><th>التوزيع</th><th>الحالة</th><th></th></tr></thead>
        <tbody>@forelse($pools as $pool)<tr>
            <td>{{ $pool->name }}<br><small>{{ \App\Models\OperatingCostPool::SERVICES[$pool->service_key] ?? $pool->service_key }}</small></td>
            <td>{{ $pool->period_start->format('Y-m-d') }} — {{ $pool->period_end->format('Y-m-d') }}</td>
            <td>{{ $pool->course?->name_ar ?? 'كل المنصة' }}</td>
            <td>{{ number_format((float) $pool->amount, 4) }} {{ $pool->currency }}@if($pool->currency === 'USD')<br><small>× {{ $pool->fx_rate_to_egp }}</small>@endif</td>
            <td>{{ \App\Models\OperatingCostPool::DRIVERS[$pool->allocation_driver] ?? $pool->allocation_driver }}</td>
            <td>{{ $pool->is_final ? 'نهائية' : 'تقديرية' }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.operating-costs.index', ['edit_cost' => $pool->id]) }}">تعديل</a><form class="d-inline" method="POST" action="{{ route('admin.operating-costs.destroy', $pool) }}" onsubmit="return confirm('حذف بند التكلفة؟')">@csrf @method('DELETE')<input type="hidden" name="editor_version" value="{{ $poolEditorVersions->get($pool->id) }}"><button class="btn btn-sm btn-outline-danger">حذف</button></form></td>
        </tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">لا توجد فواتير تشغيل بعد.</td></tr>@endforelse</tbody>
    </table></div>@if($pools->hasPages())<div class="card-footer">{{ $pools->links() }}</div>@endif</div>

    <div class="card admin-card mt-4"><div class="card-header"><strong>تكلفة وربحية الطلاب حسب الكورس</strong></div><div class="card-body"><p class="text-muted">داخل تقرير كل كورس ستجد الاستهلاك والتكلفة الفعلية والهامش لكل طالب ولكل فئة سعرية، مع تصدير CSV.</p><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>الكورس</th><th>الطلاب النشطون</th><th></th></tr></thead><tbody>@forelse($courses as $course)<tr><td>{{ $course->name_ar }}</td><td>{{ number_format($course->active_enrollments_count) }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.courses.show', [$course, 'tab' => 'commercial-report']) }}#commercial-report">فتح تقرير الربحية</a></td></tr>@empty<tr><td colspan="3" class="text-center text-muted">لا توجد كورسات.</td></tr>@endforelse</tbody></table></div></div></div>
</div>
@endsection
