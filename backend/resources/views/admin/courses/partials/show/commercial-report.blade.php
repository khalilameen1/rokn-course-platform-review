<div class="course-report-content">
    <form method="GET" action="{{ route('admin.courses.show', $reportCourse) }}#commercial-report" class="form-row align-items-end mb-3">
        <input type="hidden" name="tab" value="commercial-report">
        <div class="form-group col-md-4">@include('admin.reports.period-fields', ['period' => $reportPeriod])</div>
        <div class="form-group col-md-2"><button class="btn btn-primary" type="submit">تطبيق</button></div>
    </form>
    <p class="text-muted">{{ $reportPeriod->description() }}</p>
    @if($reportPeriod->previous())<p class="text-muted">مقارنة بـ {{ $reportPeriod->previous()->description() }}</p>@endif
    <div class="text-left mb-3">
        <a class="btn btn-outline-primary" href="{{ route('admin.courses.commercial-report.export', [$reportCourse, 'period' => $reportPeriod->key]) }}">
            <i class="fa fa-download ml-1"></i> تصدير كشف الطلاب والتكلفة CSV
        </a>
    </div>
    <div class="alert alert-info mb-3">
        المبلغ النقدي منسوب للكورس بنسبة العملات المدفوعة التي خرجت من كل باقة شحن فعلية.
        العملات الترحيبية والمكتسبة تظهر منفصلة ولا تُحسب دخلًا نقديًا.
    </div>
    @if(!$commercialReport['coin_allocation_complete'])
        <div class="alert alert-warning mb-3">
            توجد عمليات قديمة غير مرتبطة بدفتر العملات
            حُجبت قيمها بدل احتساب حقول غير قابلة للمراجعة
        </div>
    @endif

    <div class="statistics-grid">
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['active_students']) }}</span>
            <span class="stat-label">طلاب نشطون حاليًا</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['historical_students']) }}</span>
            <span class="stat-label">إجمالي من التحقوا بكل الأوقات</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['new_students']) }}</span>
            <span class="stat-label">طلاب جدد في الفترة</span>
            @include('admin.reports.growth', ['change' => $commercialReport['comparisons']['new_students']])
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['grant_students']) }}</span>
            <span class="stat-label">استفادوا من منحة</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['code_students']) }}</span>
            <span class="stat-label">استفادوا من كود إتاحة</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['paid_students']) }}</span>
            <span class="stat-label">استخدموا عملات مشتراة</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['paid_coins']) }}</span>
            <span class="stat-label">عملات مشتراة صُرفت</span>
            @include('admin.reports.growth', ['change' => $commercialReport['comparisons']['paid_coins']])
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['reward_coins']) }}</span>
            <span class="stat-label">عملات مكافآت صُرفت</span>
            @include('admin.reports.growth', ['change' => $commercialReport['comparisons']['reward_coins'], 'neutral' => true])
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['cash_gross_egp'], 2) }} ج.م</span>
            <span class="stat-label">إجمالي نقدي مؤكد منسوب للكورس</span>
            @include('admin.reports.growth', ['change' => $commercialReport['comparisons']['cash_gross_egp']])
            @if($commercialReport['cash_estimated_gross_egp'] > 0)<small class="text-warning">+ {{ number_format($commercialReport['cash_estimated_gross_egp'], 2) }} ج.م بسعر الكتالوج بانتظار كشف المزود</small>@endif
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                @if($commercialReport['cash_net_complete'])
                    {{ number_format($commercialReport['cash_net_egp'], 2) }} ج.م
                @elseif($commercialReport['cash_net_known_egp'] > 0)
                    {{ number_format($commercialReport['cash_net_known_egp'], 2) }} ج.م مؤكدة جزئيًا
                @else
                    بانتظار التسوية
                @endif
            </span>
            <span class="stat-label">صافي بوابة الدفع الفعلي</span>
            @include('admin.reports.growth', ['change' => $commercialReport['comparisons']['cash_net_egp']])
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ $commercialReport['ai_cost_usd'] == 0 && !$commercialReport['ai_cost_complete'] ? 'بانتظار التأكيد' : '$'.number_format($commercialReport['ai_cost_usd'], 6) }}</span>
            <span class="stat-label">OpenRouter مؤكد من المزود</span>
            @if(($commercialReport['ai_estimated_requests'] ?? 0) > 0)<small class="text-warning">{{ number_format($commercialReport['ai_estimated_requests']) }} طلب ينتظر تكلفة المزود وغير محسوب في الإجمالي</small>@endif
            @include('admin.reports.growth', ['change' => $commercialReport['comparisons']['ai_cost_usd'], 'lowerIsBetter' => true])
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                {{ $commercialReport['service_cost_complete'] ? number_format($commercialReport['service_cost_actual_egp'], 2).' ج.م' : 'بيانات ناقصة' }}
            </span>
            <span class="stat-label">الخدمات والتشغيل الفعلي</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                {{ $commercialReport['contribution_margin_egp'] === null ? 'بانتظار الاكتمال' : number_format($commercialReport['contribution_margin_egp'], 2).' ج.م' }}
            </span>
            <span class="stat-label">هامش المساهمة</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['playback_minutes'], 0) }} دقيقة</span>
            <span class="stat-label">دقائق مشاهدة مسجلة</span>
            @include('admin.reports.growth', ['change' => $commercialReport['comparisons']['playback_minutes']])
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ $commercialReport['cost_to_net_revenue_percentage'] === null ? '—' : number_format($commercialReport['cost_to_net_revenue_percentage'], 2).'%' }}</span>
            <span class="stat-label">التكلفة من صافي سعر الكورس</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ $commercialReport['contribution_margin_percentage'] === null ? '—' : number_format($commercialReport['contribution_margin_percentage'], 2).'%' }}</span>
            <span class="stat-label">نسبة هامش المساهمة</span>
        </div>
    </div>

    @if(!$commercialReport['cash_net_complete'] || !$commercialReport['cash_gross_complete'])
        <div class="alert alert-warning mt-3">
            لم تكتمل رسوم وصافي كل عمليات الشحن بعد
            ولن يخلط النظام العملات الأجنبية بالجنيه أو يحوّلها بسعر تخميني
            @foreach($commercialReport['cash_foreign_currency_exposure'] as $currency => $amount)
                <div>{{ $currency }} {{ number_format($amount, 2) }} بانتظار سعر تسوية موثق</div>
            @endforeach
        </div>
    @endif

    <div class="info-section mt-4">
        <h3 class="section-title"><i class="fa fa-credit-card ml-2"></i> النقد المنسوب حسب قناة الشحن</h3>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th>القناة</th><th>عملات صُرفت</th><th>الإجمالي المؤكد</th><th>تقدير الكتالوج</th><th>الصافي المؤكد</th><th>بانتظار التسوية</th></tr></thead><tbody>
        @forelse($commercialReport['cash_channel_breakdown'] as $channel)
            <tr>
                <td>{{ $channel['label'] }}</td>
                <td>{{ number_format($channel['paid_coins']) }}</td>
                <td>{{ number_format($channel['gross_egp'], 2) }} ج.م</td>
                <td>{{ number_format($channel['estimated_gross_egp'], 2) }} ج.م</td>
                <td>
                    @if($channel['net_complete'])
                        {{ number_format($channel['net_known_egp'], 2) }} ج.م
                    @elseif($channel['net_known_egp'] > 0)
                        {{ number_format($channel['net_known_egp'], 2) }} ج.م مؤكد جزئيًا
                    @else
                        <span class="text-warning">بانتظار التسوية</span>
                    @endif
                </td>
                <td>
                    {{ number_format($channel['pending_settlement_egp'], 2) }} ج.م
                    @foreach($channel['foreign_currency_amounts'] as $currency => $amount)<br><small>{{ $currency }} {{ number_format($amount, 2) }}</small>@endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted">لا يوجد تحصيل نقدي منسوب لهذا الكورس بعد.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <div class="info-section mt-4">
        <h3 class="section-title"><i class="fa fa-server ml-2"></i> تكلفة كل خدمة</h3>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th>الخدمة</th><th>القيمة المؤكدة بالجنيه</th></tr></thead><tbody>
        @foreach($commercialReport['service_breakdown'] as $service)<tr><td>{{ $service['label'] }}</td><td>{{ $service['actual_egp'] === null ? 'غير مكتملة' : number_format($service['actual_egp'], 2).' ج.م' }}</td></tr>@endforeach
        </tbody></table></div>
    </div>

    @if(!$commercialReport['service_cost_complete'])
        <div class="alert alert-warning mt-3">
            تكلفة الخدمات غير مكتملة لذلك لا يمكن عرض هامش نهائي
            <a href="{{ route('admin.operating-costs.index') }}">فواتير التشغيل</a>
            @foreach($commercialReport['cost_warnings'] as $warning)<div>• {{ $warning }}</div>@endforeach
        </div>
    @endif

    <div class="info-section mt-4">
        <h3 class="section-title"><i class="fa fa-tags ml-2"></i> الفئات خلال الفترة</h3>
        <p class="text-muted">المبيعات واستهلاك الاستفسارات حسب الفئة وقت العملية وليس الفئة التي انتقل إليها الطالب لاحقًا</p>
        <div class="table-responsive"><table class="table table-striped">
            <thead><tr><th>الفئة</th><th>نشطون حاليًا</th><th>اشتروا في الفترة</th><th>نقد منسوب</th><th>صافي مؤكد</th><th>ردود مكتملة</th><th>تكلفة OpenRouter</th></tr></thead>
            <tbody>
            @forelse($commercialReport['plan_breakdown'] as $plan)
                @php($metrics = $plan['period_metrics'])
                <tr>
                    <td>{{ $plan['plan_name'] }}
                        @if(!$plan['historical_attribution_complete'])<small class="d-block text-muted">بعض البيانات التاريخية غير مكتملة</small>@endif
                    </td>
                    <td>{{ number_format($plan['active_students']) }}</td>
                    <td>{{ $metrics['students'] === null ? 'غير متاح' : number_format($metrics['students']) }}
                        @include('admin.reports.growth', ['change' => $plan['comparisons']['students']])
                    </td>
                    <td>{{ $metrics['cash_gross_egp'] === null ? 'غير متاح' : number_format($metrics['cash_gross_egp'], 2).' ج.م' }}
                        @include('admin.reports.growth', ['change' => $plan['comparisons']['cash_gross_egp']])
                        <small class="d-block text-muted">{{ $metrics['paid_coins'] === null ? '—' : number_format($metrics['paid_coins']) }} عملة مشتراة</small>
                        <small class="d-block text-muted">{{ $metrics['reward_coins'] === null ? '—' : number_format($metrics['reward_coins']) }} عملة مكافآت</small>
                    </td>
                    <td>{{ $metrics['cash_net_egp'] === null ? 'بانتظار الاكتمال' : number_format($metrics['cash_net_egp'], 2).' ج.م' }}
                        @include('admin.reports.growth', ['change' => $plan['comparisons']['cash_net_egp']])
                    </td>
                    <td>{{ $metrics['ai_requests'] === null ? 'غير متاح' : number_format($metrics['ai_requests']) }}
                        @include('admin.reports.growth', ['change' => $plan['comparisons']['ai_requests']])
                    </td>
                    <td>{{ $metrics['ai_cost_usd'] === null ? 'بانتظار الاكتمال' : '$'.number_format($metrics['ai_cost_usd'], 6) }}
                        @include('admin.reports.growth', ['change' => $plan['comparisons']['ai_cost_usd'], 'lowerIsBetter' => true])
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted">لا توجد عمليات في هذه الفترة</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>

    <div class="info-section mt-4">
        <h3 class="section-title"><i class="fa fa-users ml-2"></i> كشف الطلاب</h3>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                <tr>
                    <th>الطالب</th><th>الحالة</th><th>المصدر</th><th>الفئة الحالية</th>
                    <th>سعر العقد</th><th>المدفوع فعليًا</th><th>التوزيع</th><th>قناة الشحن والإجمالي</th><th>الصافي</th><th>الاستهلاك</th><th>تكلفة الخدمات</th><th>نسبة التكلفة</th><th>الهامش</th>
                </tr>
                </thead>
                <tbody>
                @forelse($commercialReport['student_rows'] as $row)
                    <tr>
                        <td>
                            <strong>{{ $row['user']?->name ?? 'مستخدم محذوف' }}</strong><br>
                            <small class="text-muted">{{ $row['user']?->email }}</small>
                        </td>
                        <td>{{ $row['is_active'] ? 'نشط' : 'غير نشط' }}</td>
                        <td>
                            {{ $row['source_label'] }}
                            @if($row['access_codes'])
                                <br><small class="text-muted">{{ implode(' · ', $row['access_codes']) }}</small>
                            @endif
                        </td>
                        <td>{{ $row['plan_name'] }}</td>
                        <td>{{ $row['contract_price_coins'] === null ? 'قديم' : number_format($row['contract_price_coins']).' عملة' }}</td>
                        <td>
                            {{ number_format($row['total_coins']) }} عملة
                            @if($row['discount_coins'] > 0)
                                <br><small class="text-success">خصم {{ number_format($row['discount_coins']) }}
                                @if($row['coupon_codes']) · {{ implode(' · ', $row['coupon_codes']) }}@endif</small>
                            @endif
                        </td>
                        <td>
                            {{ number_format($row['paid_coins']) }} مشتراة<br>
                            {{ number_format($row['reward_coins']) }} مكافآت
                            @if(!$row['coin_allocation_complete'])
                                <br><small class="text-warning">ربط الدفتر غير مكتمل</small>
                            @endif
                        </td>
                        <td>
                            {{ number_format($row['cash_gross_egp'], 2) }} ج.م
                            @if($row['cash_estimated_gross_egp'] > 0)<br><small class="text-warning">{{ number_format($row['cash_estimated_gross_egp'], 2) }} ج.م تقديري</small>@endif
                            @foreach($row['cash_channels'] as $channel)
                                <br><small>{{ $channel['label'] }} · {{ number_format($channel['paid_coins']) }} عملة</small>
                            @endforeach
                        </td>
                        <td>
                            @if($row['cash_net_complete'])
                                {{ number_format($row['cash_net_known_egp'], 2) }} ج.م
                            @elseif($row['cash_net_known_egp'] > 0)
                                {{ number_format($row['cash_net_known_egp'], 2) }} ج.م مؤكدة جزئيًا
                            @else
                                <span class="text-warning">بانتظار التسوية</span>
                            @endif
                        </td>
                        <td>
                            {{ number_format($row['ai_requests']) }} طلب AI · {{ number_format($row['ai_tokens']) }} توكن<br>
                            @foreach(($row['ai_by_feature'] ?? []) as $feature => $featureUsage)
                                <small>{{ \App\Services\CourseCostReportService::aiFeatureLabels()[$feature] ?? $feature }} · {{ number_format($featureUsage['delivered_requests']) }} مكتمل · {{ number_format($featureUsage['unanswered_requests']) }} بلا نتيجة ·
                                    @if($featureUsage['cost_complete'])
                                        ${{ number_format($featureUsage['cost_usd'], 6) }}
                                    @elseif($featureUsage['cost_usd'] > 0)
                                        ${{ number_format($featureUsage['cost_usd'], 6) }} مؤكد جزئيًا
                                    @else
                                        <span class="text-warning">بانتظار التأكيد</span>
                                    @endif
                                </small><br>
                            @endforeach
                            @if($row['ai_cost_complete'])
                                ${{ number_format($row['ai_cost_usd'], 6) }}
                            @elseif($row['ai_cost_usd'] > 0)
                                ${{ number_format($row['ai_cost_usd'], 6) }} مؤكد جزئيًا
                            @else
                                <span class="text-warning">بانتظار التأكيد</span>
                            @endif
                            · {{ number_format($row['playback_minutes'], 0) }} دقيقة
                        </td>
                        <td>
                            {{ $row['service_cost_actual_egp'] === null ? 'غير مكتملة' : number_format($row['service_cost_actual_egp'], 2).' ج.م' }}
                            @if($row['ai_failed_requests'])<br><small class="text-warning">{{ number_format($row['ai_failed_requests']) }} طلب AI فاشل</small>@endif
                            <details><summary><small>تفصيل الخدمات</small></summary>@foreach(\App\Services\CourseCostReportService::serviceLabels() as $key => $label)<div><small>{{ $label }}: {{ ($row['actual_cost_by_service_egp'][$key] ?? null) === null ? 'ناقص' : number_format($row['actual_cost_by_service_egp'][$key], 2).' ج.م' }}</small></div>@endforeach</details>
                        </td>
                        <td>{{ $row['cost_to_net_revenue_percentage'] === null ? '—' : number_format($row['cost_to_net_revenue_percentage'], 2).'%' }}</td>
                        <td>{{ $row['contribution_margin_egp'] === null ? '—' : number_format($row['contribution_margin_egp'], 2).' ج.م' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="text-center text-muted">لا يوجد طلاب في الكورس بعد</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($commercialReport['student_rows']->hasPages())
            <div class="mt-3 d-flex justify-content-center">
                {{ $commercialReport['student_rows']->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</div>
