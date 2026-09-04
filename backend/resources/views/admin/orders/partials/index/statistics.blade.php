    @if(collect($filters)->filter(static fn ($value) => $value !== null && $value !== '')->isNotEmpty())
        <div class="alert alert-info">الأرقام التالية تخص نتائج الفلاتر الحالية</div>
    @endif
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--total mx-auto">
                        <i class="ti-receipt text-white"></i>
                    </div>
                    <div class="stat-title">إجمالي الطلبات</div>
                    <h3 class="stat-value">{{ number_format($stats['total']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--pending mx-auto">
                        <i class="ti-time text-white"></i>
                    </div>
                    <div class="stat-title">مفتوحة محليًا</div>
                    <h3 class="stat-value">{{ number_format($stats['pending']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--approved mx-auto">
                        <i class="ti-check text-white"></i>
                    </div>
                    <div class="stat-title">مسجلة كمدفوعة</div>
                    <h3 class="stat-value">{{ number_format($stats['approved']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--rejected mx-auto">
                        <i class="ti-close text-white"></i>
                    </div>
                    <div class="stat-title">فشلت أو رُفضت</div>
                    <h3 class="stat-value">{{ number_format($stats['rejected']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--cancelled mx-auto">
                        <i class="ti-minus text-dark"></i>
                    </div>
                    <div class="stat-title">مغلقة</div>
                    <h3 class="stat-value">{{ number_format($stats['cancelled']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--revenue mx-auto">
                        <i class="ti-money text-white"></i>
                    </div>
                    <div class="stat-title">إجمالي التحصيل المؤكد</div>
                    <h3 class="stat-value stat-value--amount">{{ number_format($stats['total_amount'], 2) }}</h3>
                    <small class="text-muted">جنيه</small>
                    <br><small class="text-muted">دون الاختبارات أو العمليات المرتجعة</small>
                    @if($stats['catalog_estimated_amount'] > 0)
                        <br><small class="text-warning">{{ number_format($stats['catalog_estimated_amount'], 2) }} تقدير كتالوج خارج الإجمالي</small>
                    @endif
                </div>
            </div>
        </div>
    </div>
