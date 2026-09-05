@php
    $isAdministrator = $canViewCommercialReport;
    $accessPlansByCode = $course->accessPlans->keyBy('code');
    $planLabels = [
        'basic' => 'التعلّم',
        'guided' => 'التعلّم بإرشاد',
        'mentor' => 'التعلّم بمتابعة',
    ];
@endphp
<div class="form-section" id="course-editor-plans">
    @include('admin.courses.partials.publishing-area-issues', ['area' => 'plans'])
    <h2 class="section-title"><div class="section-icon"><i class="fa fa-layer-group"></i></div>فئات الكورس</h2>
    <div class="form-help course-editor__section-help">
        عدّل الاسم والسعر والإتاحة هنا
        مزايا الذكاء الاصطناعي لكل فئة يديرها الأدمن مرة واحدة من إعدادات Rokn AI
        المشتريات السابقة لا تتغير
    </div>
    <div class="course-editor__plan-grid">
        @foreach($planLabels as $code => $label)
            @php
                $plan = $accessPlansByCode->get($code);
                $features = ['الكورس والمشروعات والعبور'];
                if ($plan?->chat_enabled) $features[] = 'Rokn AI';
                if (in_array((string) $plan?->project_feedback_level, ['report', 'enhanced'], true)) {
                    $features[] = 'تقرير المشروع';
                }
                if ((string) $plan?->project_feedback_level === 'enhanced') {
                    $features[] = 'محادثة التقرير';
                }
                if ($plan?->certificate_enabled) $features[] = 'الشهادة';
                $description = implode(' · ', $features);
            @endphp
            <div class="course-editor__plan-card">
                <div class="course-editor__plan-title">{{ $label }}</div>
                <div class="course-editor__plan-description">{{ $description }}</div>
                @if($isAdministrator && $plan && $planStats->has($code))
                    @php $stats = $planStats->get($code); @endphp
                    <div class="course-editor__plan-stats">
                        <span>عمليات الشراء <strong>{{ number_format($stats['sales_count']) }}</strong></span>
                        <span>إجمالي العملات <strong>{{ number_format($stats['total_coins']) }}</strong></span>
                        <span>مدفوعة <strong>{{ number_format($stats['paid_coins']) }}</strong></span>
                        <span>مكافآت <strong>{{ number_format($stats['reward_coins']) }}</strong></span>
                        <span>طلبات الشات <strong>{{ number_format($stats['chat_requests']) }}</strong></span>
                        <span>مراجعات المشاريع <strong>{{ number_format($stats['project_requests']) }}</strong></span>
                        <span>رسائل المتابعة <strong>{{ number_format($stats['followup_requests']) }}</strong></span>
                        <span class="course-editor__plan-stats-total">تكلفة OpenRouter <strong>${{ number_format($stats['chat_cost_usd'] + $stats['project_cost_usd'] + $stats['followup_cost_usd'], 6) }}</strong></span>
                        @if($stats['incomplete_orders'])
                            <span class="course-editor__plan-stats-total text-warning">عمليات تحتاج ربط الدفتر <strong>{{ number_format($stats['incomplete_orders']) }}</strong></span>
                        @endif
                        @if($stats['total_unanswered_requests'])
                            <span class="course-editor__plan-stats-total text-warning">طلبات بلا نتيجة مؤكدة <strong>{{ number_format($stats['total_unanswered_requests']) }}</strong></span>
                        @endif
                    </div>
                @endif
                <label class="form-label-modern">اسم الفئة الظاهر للطالب</label>
                <input class="form-control-modern" type="text" maxlength="120" name="access_plans[{{ $code }}][name_ar]" value="{{ old("access_plans.$code.name_ar", $plan?->name_ar ?? $label) }}" required>
                @if($enableEnglish)
                    <label class="form-label-modern">اسم الفئة بالإنجليزية</label>
                    <input class="form-control-modern" type="text" maxlength="120" name="access_plans[{{ $code }}][name_en]" value="{{ old("access_plans.$code.name_en", $plan?->name_en) }}">
                @else
                    <input type="hidden" name="access_plans[{{ $code }}][name_en]" value="{{ $plan?->name_en }}">
                @endif
                {{-- Rokn publishes exactly three purchasable tiers. Offering a
                     disable switch here was a false operation: the same save
                     then failed readiness because all three must be active. --}}
                <input type="hidden" name="access_plans[{{ $code }}][is_active]" value="1">
                <label class="form-label-modern">السعر بعملات ركن</label>
                <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][price_coins]" value="{{ old("access_plans.$code.price_coins", $plan?->price_coins ?? 0) }}" required>
                <label class="form-label-modern">الحد الأدنى من العملات المدفوعة</label>
                <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][minimum_paid_coins]" value="{{ old("access_plans.$code.minimum_paid_coins", $plan?->minimum_paid_coins ?? 0) }}" required>
                <input type="hidden" name="access_plans[{{ $code }}][certificate_enabled]" value="0">
                <label class="course-editor__inline-check course-editor__inline-check--top">
                    <input type="checkbox" name="access_plans[{{ $code }}][certificate_enabled]" value="1" {{ old("access_plans.$code.certificate_enabled", $plan?->certificate_enabled ?? true) ? 'checked' : '' }}> إصدار شهادة عند إتمام الكورس
                </label>
            </div>
        @endforeach
    </div>
</div>
