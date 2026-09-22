@php
    $isAdministrator = $canViewCommercialReport;
    $accessPlansByCode = $course->accessPlans->keyBy('code');
    $planLabels = [
        'basic' => 'Basic',
        'guided' => 'Plus',
        'mentor' => 'Pro',
    ];
    $economicsService = app(\App\Services\CoursePlanEconomicsService::class);
    $planService = app(\App\Services\CourseAccessPlanService::class);
    $promotionPercent = $economicsService->promotionPercent();
@endphp
<div class="form-section" id="course-editor-plans">
    @include('admin.courses.partials.publishing-area-issues', ['area' => 'plans'])
    <h2 class="section-title"><div class="section-icon"><i class="fa fa-layer-group"></i></div>اشتراكات الكورس</h2>
    <div class="form-help course-editor__section-help">
        التغييرات للمشتريات الجديدة فقط
        المكافآت حتى {{ $promotionPercent }}٪ عند الشراء والترقية بفرق السعر بدون مكافآت
    </div>
    <div class="course-editor__plan-grid">
        @foreach($planLabels as $code => $label)
            @php
                $plan = $accessPlansByCode->get($code);
                $capabilities = $plan ? $planService->publicPayload($plan) : [];
                $features = $code === 'basic'
                    ? ['مشاهدة الكورس كاملًا', 'بدون شات أو مشاريع أو تقييم أو شهادة', 'كود المنحة متاح مع Basic فقط']
                    : [];
                if ($code !== 'basic' && ($capabilities['chat_enabled'] ?? false)) $features[] = number_format($capabilities['chat_message_limit']) . ' رسالة لمناقشة محتوى الكورس';
                if ($code !== 'basic' && ($capabilities['projects_enabled'] ?? false) && $course->sections->contains('section_type', 'project')) {
                    $features[] = ($capabilities['project_report_enabled'] ?? false)
                        ? 'تنفيذ مشاريع عملية والحصول على تقييم لتحسين مستواك'
                        : 'تنفيذ مشاريع عبور عملية';
                    if (($capabilities['project_thread_reply_enabled'] ?? false)) $features[] = number_format($capabilities['project_message_limit']) . ' رسالة لمناقشة المشاريع';
                }
                if ($code !== 'basic' && ($capabilities['certificate_enabled'] ?? false)) $features[] = 'شهادة بعد اجتياز الكورس';
                $description = implode(' · ', $features);
                $financialTerms = $plan?->getAttributes() ?? [];
                $financialTerms['delivery_cost_usd'] = old("access_plans.$code.delivery_cost_usd", $plan?->delivery_cost_usd);
                $financialTerms['price_coins'] = old("access_plans.$code.price_coins", $plan?->price_coins ?? 0);
                $financialTerms['minimum_paid_coins'] = old("access_plans.$code.minimum_paid_coins", $plan?->minimum_paid_coins ?? 0);
                if ($code === 'basic') {
                    $financialTerms['chat_enabled'] = false;
                    $financialTerms['project_feedback_level'] = 'pass_only';
                }
                $economics = $economicsService->evaluate($financialTerms, $promotionPercent);
            @endphp
            <div class="course-editor__plan-card">
                <div class="course-editor__plan-title">{{ $label }}</div>
                <div class="course-editor__plan-description">{{ $description }}</div>
                @if($code === 'basic' && $plan?->exists && ($plan->projects_enabled || $plan->certificate_enabled))
                    <div class="course-editor__plan-note">العرض الحالي يتضمن مزايا قديمة وسيصبح Basic للمشاهدة فقط عند نشر هذه التعديلات دون تغيير حقوق المشترين السابقين</div>
                @endif
                @if($isAdministrator && $plan && $planStats->has($code))
                    @php $stats = $planStats->get($code); @endphp
                    <details class="course-editor__plan-history">
                    <summary>المبيعات والاستخدام</summary>
                    <div class="course-editor__plan-stats">
                        <span>عمليات الشراء <strong>{{ number_format($stats['sales_count']) }}</strong></span>
                        <span>إجمالي العملات <strong>{{ number_format($stats['total_coins']) }}</strong></span>
                        <span>مدفوعة <strong>{{ number_format($stats['paid_coins']) }}</strong></span>
                        <span>مكافآت <strong>{{ number_format($stats['reward_coins']) }}</strong></span>
                        <span>طلبات الشات <strong>{{ number_format($stats['chat_requests']) }}</strong></span>
                        <span>مراجعة المشروع <strong>{{ number_format($stats['review_requests']) }}</strong></span>
                        <span>تقارير المشاريع <strong>{{ number_format($stats['project_requests']) }}</strong></span>
                        <span>رسائل المتابعة <strong>{{ number_format($stats['followup_requests']) }}</strong></span>
                        <span class="course-editor__plan-stats-total">تكلفة OpenRouter <strong>${{ number_format($stats['chat_cost_usd'] + $stats['review_cost_usd'] + $stats['project_cost_usd'] + $stats['followup_cost_usd'], 6) }}</strong></span>
                        @if($stats['incomplete_orders'])
                            <span class="course-editor__plan-stats-total text-warning">عمليات تحتاج ربط الدفتر <strong>{{ number_format($stats['incomplete_orders']) }}</strong></span>
                        @endif
                        @if($stats['total_unanswered_requests'])
                            <span class="course-editor__plan-stats-total text-warning">طلبات بلا نتيجة مؤكدة <strong>{{ number_format($stats['total_unanswered_requests']) }}</strong></span>
                        @endif
                    </div>
                    </details>
                @endif
                <label class="form-label-modern">اسم الاشتراك</label>
                <input class="form-control-modern" type="text" maxlength="120" name="access_plans[{{ $code }}][name_ar]" value="{{ old("access_plans.$code.name_ar", $plan?->name_ar ?? $label) }}" required>
                @if($enableEnglish)
                    <label class="form-label-modern">الاسم بالإنجليزية</label>
                    <input class="form-control-modern" type="text" maxlength="120" name="access_plans[{{ $code }}][name_en]" value="{{ old("access_plans.$code.name_en", $plan?->name_en) }}">
                @else
                    <input type="hidden" name="access_plans[{{ $code }}][name_en]" value="{{ $plan?->name_en }}">
                @endif
                {{-- Rokn publishes exactly three purchasable tiers. Offering a
                     disable switch here was a false operation: the same save
                     then failed readiness because all three must be active. --}}
                <input type="hidden" name="access_plans[{{ $code }}][is_active]" value="1">
                <label class="form-label-modern">السعر بعملات رُكن</label>
                <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][price_coins]" value="{{ old("access_plans.$code.price_coins", $plan?->price_coins ?? 0) }}" required>
                <label class="form-label-modern">الحد الأدنى من العملات المدفوعة</label>
                <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][minimum_paid_coins]" value="{{ old("access_plans.$code.minimum_paid_coins", $plan?->minimum_paid_coins ?? 0) }}" required>
                <input type="hidden" name="access_plans[{{ $code }}][certificate_enabled]" value="0">
                @if($code !== 'basic')
                <label class="course-editor__inline-check course-editor__inline-check--top">
                    <input type="checkbox" name="access_plans[{{ $code }}][certificate_enabled]" value="1" {{ old("access_plans.$code.certificate_enabled", $plan?->certificate_enabled ?? true) ? 'checked' : '' }}> إصدار شهادة عند إتمام الكورس
                </label>
                @endif
                @if($isAdministrator)
                    <details class="course-editor__plan-economics" @if(!$economics['configured'] || !$economics['meets_floor']) open @endif>
                        <summary>التكلفة وحد السعر</summary>
                        <label class="form-label-modern" for="delivery-cost-{{ $code }}">تكلفة تقديم الاشتراك بالدولار</label>
                        <input class="form-control-modern" id="delivery-cost-{{ $code }}" type="number" min="0" max="999999.999999" step="0.000001"
                            name="access_plans[{{ $code }}][delivery_cost_usd]" value="{{ $financialTerms['delivery_cost_usd'] }}">
                        <div class="form-help">نصيب البيعة من الإنتاج والتشغيل والدعم وفحص المشاريع ولا يشمل ميزانيات التدريب المضافة تلقائيًا</div>
                        @if($economics['configured'])
                            <div class="course-editor__plan-note">
                                الحد المحسوب {{ number_format($economics['required_price_coins']) }} عملة
                                والحد المدفوع {{ number_format($economics['required_paid_coins']) }}
                                بهامش مستهدف {{ $economics['target_margin_percent'] }}٪ قبل ضريبة الدخل
                                @if(!$economics['meets_floor'])<strong>السعر أو الحد المدفوع أقل من المطلوب</strong>@endif
                            </div>
                        @else
                            <div class="course-editor__plan-note">التسعير غير معتمد<br>{{ implode(' · ', $economics['problems']) }}</div>
                        @endif
                        <div class="form-help">الحساب حسب القيم المحفوظة ويُراجع عند الحفظ ولا يُخصم من صافي العملة أي عمولات أو ضرائب مرة أخرى</div>
                    </details>
                @endif
            </div>
        @endforeach
    </div>
    <div class="form-help course-editor__section-help">التدرج المقترح Basic أقل من Plus بـ٤٠٪ وPro أعلى من Plus بـ٥٠٪ بشرط تغطية تكلفة كل اشتراك ولا تُغيّر الأسعار تلقائيًا</div>
</div>
