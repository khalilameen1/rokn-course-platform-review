@extends('admin.layouts.app')

@section('page.title', 'تحليلات المنتج')

@section('content')
@php
    $eventLabels = [
        'course_impression' => 'ظهر الكورس', 'course_opened' => 'فتح التفاصيل',
        'sample_started' => 'بدأ العينة', 'sample_completed' => 'أكمل العينة',
        'paywall_viewed' => 'فتح نافذة الاشتراك', 'earn_tasks_opened' => 'فتح مهام العملات',
        'purchase_started' => 'بدأ الشراء', 'purchase_completed' => 'اكتمل الشراء',
        'checkout_quoted' => 'طلب تفاصيل الاشتراك',
        'project_submitted' => 'سلّم مشروعًا', 'project_passed' => 'اجتاز مشروعًا',
        'certificate_issued' => 'صدرت شهادة',
    ];
    $ai = $analytics['ai'];
    $quality = $analytics['quality'];
@endphp
<div class="admin-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1">تحليلات المنتج</h2>
            <p class="text-muted mb-0">سلوك الاستخدام والتحصيل والتكلفة من مصادرها الفعلية</p>
        </div>
        <small class="text-muted">التوقيت: {{ \App\Support\BusinessClock::timezoneName() }}</small>
    </div>

    <div class="card modern-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.product-analytics.index') }}" class="form-row align-items-end">
                <div class="form-group col-md-5">
                    <label for="analyticsCourse">الكورس</label>
                    <select id="analyticsCourse" name="course_id" class="form-control">
                        <option value="">كل الكورسات</option>
                        @foreach($courses as $course)
                            <option value="{{ $course->id }}" @selected((int) $filters['course_id'] === (int) $course->id)>
                                {{ $course->name_ar ?: $course->name_en }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-3">
                    @include('admin.reports.period-fields', ['period' => $period])
                </div>
                <div class="form-group col-md-4">
                    <button class="btn btn-primary">تطبيق</button>
                    <a href="{{ route('admin.product-analytics.index', ['period' => $period->key]) }}" class="btn btn-light">كل الكورسات</a>
                </div>
            </form>
            <p class="text-muted mb-0">{{ $period->description() }}</p>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-3 col-sm-6 mb-3"><div class="card modern-card h-100"><div class="card-body"><small class="text-muted">مستخدمون أو زوار مميزون</small><h3 class="mb-0">{{ number_format($quality['actors']) }}</h3>@include('admin.reports.growth', ['change' => $analytics['changes']['actors']])</div></div></div>
        <div class="col-lg-3 col-sm-6 mb-3"><div class="card modern-card h-100"><div class="card-body"><small class="text-muted">جلسات</small><h3 class="mb-0">{{ number_format($quality['sessions']) }}</h3>@include('admin.reports.growth', ['change' => $analytics['changes']['sessions']])</div></div></div>
        @if($paymentChannelReport !== null)
        <div class="col-lg-3 col-sm-6 mb-3"><div class="card modern-card h-100"><div class="card-body"><small class="text-muted">تحصيل مؤكد لباقات العملات (جنيه)</small><h3 class="mb-0">{{ $paymentChannelReport['egp']['catalog_estimated_gross_count'] > 0 && $paymentChannelReport['egp']['confirmed_gross_count'] === 0 ? 'بانتظار تأكيد التحصيل' : number_format($paymentChannelReport['egp']['confirmed_gross_amount'], 2) }}</h3><small class="text-muted">لا يشمل الاختبار أو المرتجع</small>@if($paymentChannelReport['egp']['catalog_estimated_gross_amount'] > 0)<br><small class="text-warning">{{ number_format($paymentChannelReport['egp']['catalog_estimated_gross_amount'], 2) }} تقديري خارج الإجمالي</small>@endif @include('admin.reports.growth', ['change' => $paymentChanges['gross']])</div></div></div>
        <div class="col-lg-3 col-sm-6 mb-3"><div class="card modern-card h-100"><div class="card-body"><small class="text-muted">صافي مؤكد (جنيه)</small><h3 class="mb-0">{{ $paymentChannelReport['egp']['pending_settlement_count'] > 0 && $paymentChannelReport['rows']->where('currency', 'EGP')->sum('confirmed_net_count') === 0 ? 'بانتظار التسوية' : number_format($paymentChannelReport['egp']['confirmed_net_amount'], 2) }}</h3>@if($paymentChannelReport['egp']['pending_settlement_count'] > 0)<small class="text-warning">جزئي · {{ $paymentChannelReport['egp']['pending_settlement_count'] }} بانتظار التسوية</small>@else<small class="text-muted">مكتمل للفترة</small>@endif @include('admin.reports.growth', ['change' => $paymentChanges['net']])</div></div></div>
        @endif
    </div>

    @if($paymentChannelReport !== null)
        @include('admin.orders.partials.index.payment-channel-report')
        @include('admin.reports.provider-invoices', ['invoiceReport' => $invoiceReport])
    @else
        <p class="text-muted">تحصيل باقات العملات على مستوى المنصة لا يُنسب إلى كورس بعينه</p>
    @endif

    <div class="card modern-card mb-4">
        <div class="card-header-modern"><h4 class="mb-0">مسار شراء الكورس المرصود</h4></div>
        <div class="card-body pb-0">
            <p class="text-muted">طلبوا تفاصيل الاشتراك خلال الفترة المختارة ثم أكدوا الشراء وأكملوا الاشتراك لنفس الكورس خلال {{ $analytics['purchase_funnel']['window_days'] }} يومًا</p>
            <p class="text-muted">المراحل الثلاث تعتمد على الطلب المسجّل في السيرفر ولا تشمل ترقية الاشتراك أو أحداث الشراء القديمة المرسلة من التطبيق</p>
            <p class="text-muted">طلب التفاصيل يعني تجهيز عرض اشتراك صالح وليس إثبات مشاهدة النافذة أو قراءة تفاصيلها</p>
        </div>
        <div class="table-responsive"><table class="table table-modern mb-0">
            <thead><tr><th>الخطوة</th><th>هويات مميزة</th><th>من الخطوة السابقة</th><th>من بداية المسار</th><th>لم يصلوا بعد</th></tr></thead>
            <tbody>
            @foreach($analytics['purchase_funnel']['steps'] as $step)
                <tr>
                    <td>{{ $eventLabels[$step['event']] ?? $step['event'] }}</td>
                    <td>{{ number_format($step['actors']) }}</td>
                    <td>{{ $step['conversion_from_previous'] === null ? '—' : number_format($step['conversion_from_previous'], 1).'%' }}</td>
                    <td>{{ $step['conversion_from_entry'] === null ? '—' : number_format($step['conversion_from_entry'], 1).'%' }}</td>
                    <td>{{ $step['not_reached_yet'] === null ? '—' : number_format($step['not_reached_yet']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="card-body">
            @if($analytics['purchase_funnel']['pending_actors'] > 0)
                <p class="text-muted mb-2">{{ number_format($analytics['purchase_funnel']['pending_actors']) }} لم يكملوا المسار وما زال وقت التحويل متاحًا لهم</p>
            @endif
            <small class="text-muted d-block">كل حساب هوية واحدة وكل جلسة زائر هوية مستقلة ولا نربط جلسة الزائر بحساب دون دليل</small>
            <small class="text-muted d-block">الأحداث المتزامنة في نفس الثانية لا تحدد ترتيبًا أدق والتحصيل المؤكد يُقرأ من تقرير المدفوعات لا من هذه الأحداث</small>
        </div>
    </div>

    <div class="card modern-card mb-4">
        <div class="card-header-modern"><h4 class="mb-0">إكمال الدروس المرصود</h4></div>
        <div class="card-body pb-0">
            <p class="text-muted">طلاب بدأوا متابعة الدرس خلال الفترة المختارة وبلغوا حد الإكمال خلال {{ $analytics['lesson_completion']['window_days'] }} يومًا</p>
            <p class="text-muted">كل طالب يُحسب مرة واحدة لكل درس من وقت مشاهدة يقبله الباك وليس مجرد فتح المشغّل أو الوصول إلى آخر الفيديو</p>
        </div>
        <div class="table-responsive"><table class="table table-modern mb-0">
            <thead><tr><th>الكورس والدرس</th><th>بدأوا المتابعة</th><th>أكملوا</th><th>نسبة الإكمال</th><th>لم يكملوا بعد</th><th>ما زال الوقت متاحًا</th></tr></thead>
            <tbody>
            @forelse($analytics['lesson_completion']['rows'] as $lesson)
                <tr>
                    <td>
                        <small class="text-muted d-block">{{ $lesson['course_title'] ?: 'كورس '.$lesson['course_id'] }} @if($lesson['course_archived']) · مؤرشف @endif</small>
                        {{ $lesson['lesson_title'] ?: 'درس '.$lesson['lesson_id'] }}
                        @if($lesson['lesson_archived'])<small class="text-muted d-block">نسخة سابقة أو درس لم يعد متاحًا</small>@endif
                    </td>
                    <td>{{ number_format($lesson['starts']) }}</td>
                    <td>{{ number_format($lesson['completions']) }}</td>
                    <td>{{ $lesson['completion_rate'] === null ? '—' : number_format($lesson['completion_rate'], 1).'%' }}</td>
                    <td>{{ number_format($lesson['not_completed']) }}</td>
                    <td>{{ number_format($lesson['pending']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center">لا توجد بدايات متابعة مرصودة في هذه الفترة</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="card-body">
            <small class="text-muted d-block">مرتب حسب أكبر عدد لم يكمل بعد ولا يعني أنهم تركوا الكورس نهائيًا</small>
            <small class="text-muted d-block">يشمل البدايات المسجلة بعد تفعيل هذا القياس ولا يستنتج بدايات قديمة من سجل التقدم ولا يضم مشاهدة العينة دون اشتراك</small>
            @if($analytics['lesson_completion']['total_lessons'] > $analytics['lesson_completion']['row_limit'])
                <small class="text-muted d-block">عرض أكثر {{ $analytics['lesson_completion']['row_limit'] }} درسًا من أصل {{ number_format($analytics['lesson_completion']['total_lessons']) }} درس مرصود</small>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-xl-7 mb-4">
            <div class="card modern-card h-100">
                <div class="card-header-modern"><h4 class="mb-0">نشاط الاستخدام</h4></div>
                <div class="table-responsive"><table class="table table-modern mb-0">
                    <thead><tr><th>الخطوة</th><th>أحداث</th><th>أشخاص</th><th>تغير الأحداث</th></tr></thead>
                    <tbody>
                    @foreach($analytics['activity'] as $step)
                        <tr><td>{{ $eventLabels[$step['event']] ?? $step['event'] }}</td><td>{{ number_format($step['total']) }}</td><td>{{ number_format($step['unique_actors']) }}</td><td>@include('admin.reports.growth', ['change' => $step['change']])</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        </div>
        <div class="col-xl-5 mb-4">
            <div class="card modern-card h-100">
                <div class="card-header-modern"><h4 class="mb-0">تكلفة الذكاء الاصطناعي</h4></div>
                <div class="card-body">
                    @if(!$ai['available'])
                        <div class="alert alert-warning mb-0">القياس غير متاح في قاعدة البيانات الحالية</div>
                    @else
                        <dl class="row mb-0">
                            <dt class="col-7">ردود مكتملة</dt><dd class="col-5 text-left">{{ number_format($ai['completed_requests']) }}</dd>
                            <dt class="col-7">محاولات بلا إجابة مسلّمة</dt><dd class="col-5 text-left">{{ number_format($ai['unanswered_requests']) }}</dd>
                            <dt class="col-7">محاولات فاشلة أو ملغاة</dt><dd class="col-5 text-left">{{ number_format($ai['failed_requests']) }}</dd>
                            <dt class="col-7">التوكنز</dt><dd class="col-5 text-left">{{ number_format($ai['tokens']) }}</dd>
                            <dt class="col-7">الاستهلاك المؤكد بالدولار</dt><dd class="col-5 text-left">{{ $ai['cost_usd'] === null || (!$ai['cost_complete'] && $ai['provider_cost_requests'] === 0 && $ai['cost_usd'] == 0) ? 'غير متاح' : number_format($ai['cost_usd'], 6) }}</dd>
                        </dl>
                        @include('admin.reports.growth', ['change' => $analytics['changes']['cost_usd'], 'neutral' => true])
                        @if(!$ai['cost_complete'])
                            <div class="alert alert-warning mt-3 mb-0">قياس جزئي · {{ number_format($ai['estimated_cost_requests']) }} طلبًا بلا تكلفة مؤكدة ولا يدخل تقديره في المبلغ</div>
                        @else
                            <div class="text-muted mt-3">القياس المتاح مؤكد من المزود أو من إعادة استخدام إجابة محفوظة بلا تكلفة</div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6 mb-4"><div class="card modern-card h-100"><div class="card-header-modern"><h4 class="mb-0">مصدر الوصول والشراء</h4></div><div class="table-responsive"><table class="table table-modern mb-0"><thead><tr><th>المصدر</th><th>الحملة</th><th>الحدث</th><th>أشخاص</th></tr></thead><tbody>@forelse($analytics['attribution'] as $row)<tr><td>{{ $row['source'] }}</td><td>{{ $row['campaign'] ?: 'غير منسوب' }}</td><td>{{ $eventLabels[$row['event']] ?? $row['event'] }}</td><td>{{ number_format($row['actors']) }}</td></tr>@empty<tr><td colspan="4" class="text-muted text-center">لا توجد أحداث منسوبة في هذه الفترة</td></tr>@endforelse</tbody></table></div></div></div>
        <div class="col-xl-6 mb-4"><div class="card modern-card h-100"><div class="card-header-modern"><h4 class="mb-0">دفعات الاكتساب</h4></div><div class="table-responsive"><table class="table table-modern mb-0"><thead><tr><th>أول ظهور</th><th>أشخاص جدد</th></tr></thead><tbody>@forelse($analytics['cohorts']->reverse()->take(31) as $cohort)<tr><td>{{ $cohort['date'] }}</td><td>{{ number_format($cohort['actors']) }}</td></tr>@empty<tr><td colspan="2" class="text-muted text-center">لا توجد بيانات</td></tr>@endforelse</tbody></table></div></div></div>
    </div>

    <div class="card modern-card mb-4">
        <div class="card-header-modern"><h4 class="mb-0">جودة القياس</h4></div>
        <div class="card-body d-flex flex-wrap">
            <span class="ml-4 mb-2">الأحداث <strong>{{ number_format($quality['events']) }}</strong></span>
            <span class="ml-4 mb-2">أحداث قبل تسجيل الدخول <strong>{{ number_format($quality['anonymous_events']) }}</strong></span>
            <span class="ml-4 mb-2">أحداث منسوبة لحملة <strong>{{ number_format($quality['campaign_events']) }}</strong></span>
            <span class="mb-2">آخر وصول <strong>{{ $quality['last_received_at'] ? \App\Support\BusinessClock::format($quality['last_received_at']) : 'غير متاح' }}</strong></span>
        </div>
    </div>
</div>
@endsection
