@extends('admin.layouts.app')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.home.partials._dynamic_styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/home-dashboard.css') }}">
@endsection
@section('page.title', 'لوحة التحكم')
@section('content')

<div class="admin-page dashboard-container">
    <div class="welcome-header">
        <h2>التشغيل اليومي</h2>
        <p>حالة ركن الآن</p>
    </div>

    <form method="GET" action="{{ route('admin.dashboard') }}" class="card modern-card mb-4">
        <div class="card-body d-flex flex-wrap align-items-end">
            @include('admin.reports.period-fields', ['period' => $period])
            <button type="submit" class="btn btn-primary mr-3 mb-3">تطبيق</button>
            <a class="btn btn-light mr-3 mb-3" href="{{ route('admin.product-analytics.index', ['period' => $period->key]) }}">تحليلات المنتج</a>
        </div>
        <div class="card-footer text-muted">{{ $period->description() }}</div>
    </form>

    <nav class="dashboard-priority-nav" aria-label="ما يحتاج متابعة">
        <a href="{{ route('admin.product-operations.index') }}"><strong>حالة النشر</strong><span>فحوص المنتج والكورسات</span></a>
        <a href="{{ route('admin.playback-operations.index') }}"><strong>الوسائط</strong><span>مشكلات تشغيل الفيديو</span></a>
        <a href="{{ route('admin.project-submissions.index') }}"><strong>المشاريع</strong><span>المراجعات المنتظرة</span></a>
        <a href="{{ route('admin.orders.index') }}"><strong>{{ number_format($revenueStats['pending_bills_count']) }} مدفوعات معلقة</strong><span>راجع مسار عمليات الدفع</span></a>
    </nav>

    <!-- Statistics Cards Row -->
    <div class="row mb-4">
        <div class="col-xl-4 col-lg-4 col-md-4 col-sm-6 mb-4">
            <div class="stats-card primary fade-in-up dashboard-delay-1">
                <div class="stats-card-body">
                    <div class="stats-icon primary">
                        <i class="fa fa-graduation-cap"></i>
                    </div>
                    <div class="stats-info">
                        <h3 class="count">{{ $platformStats['courses'] }}</h3>
                        <p>إجمالي الكورسات الآن</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-lg-4 col-md-4 col-sm-6 mb-4">
            <div class="stats-card warning fade-in-up dashboard-delay-3">
                <div class="stats-card-body">
                    <div class="stats-icon warning">
                        <i class="fa fa-book"></i>
                    </div>
                    <div class="stats-info">
                        <h3 class="count">{{ $platformStats['lessons'] }}</h3>
                        <p>إجمالي الدروس الآن</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-4 col-md-4 col-sm-6 mb-4">
            <div class="stats-card info fade-in-up dashboard-delay-4">
                <div class="stats-card-body">
                    <div class="stats-icon info">
                        <i class="fa fa-users"></i>
                    </div>
                    <div class="stats-info">
                        <h3 class="count">
                            {{ $platformStats['students'] }}
                        </h3>
                        <p>إجمالي الطلاب الآن</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Statistics Section -->
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-4 dashboard-section-title">
                <i class="fa fa-money dashboard-section-title__icon"></i>
                الأموال الفعلية واستهلاك العملات
            </h3>
        </div>
    </div>

    <!-- Revenue Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stats-card primary fade-in-up dashboard-delay-1">
                <div class="stats-card-body">
                    <div class="stats-icon primary">
                        <i class="fa fa-dollar"></i>
                    </div>
                    <div class="stats-info">
                        <h3>{{ !$revenueStats['gross_complete'] && $revenueStats['confirmed_gross_count'] === 0 ? 'بانتظار تأكيد التحصيل' : number_format($revenueStats['total_revenue'], 0) }}</h3>
                        <p>تحصيل الفترة المؤكد بكل القنوات (جنيه)</p>
                        @if($revenueStats['catalog_estimated_revenue'] > 0)
                            <small class="text-warning">{{ number_format($revenueStats['catalog_estimated_revenue'], 0) }} تقدير كتالوج خارج الإجمالي</small><br>
                        @endif
                        @include('admin.reports.growth', ['change' => $revenueStats['revenue_change']])
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stats-card success fade-in-up dashboard-delay-2">
                <div class="stats-card-body">
                    <div class="stats-icon success"><i class="fa fa-check"></i></div>
                    <div class="stats-info">
                        <h3>{{ $revenueStats['provider_settlement_pending_count'] > 0 && $revenueStats['confirmed_net_count'] === 0 ? 'بانتظار التسوية' : number_format($revenueStats['confirmed_net_revenue'], 0) }}</h3>
                        <p>الصافي المؤكد من كشوف المزودين</p>
                        @if($revenueStats['provider_settlement_pending_count'] > 0)
                            <small class="text-warning">جزئي · {{ number_format($revenueStats['provider_settlement_pending_count']) }} عملية بانتظار كشف التسوية</small>
                        @else
                            <small class="text-muted">بعد الرسوم والاستقطاعات</small>
                        @endif
                        <br>@include('admin.reports.growth', ['change' => $revenueStats['net_change']])
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stats-card warning fade-in-up dashboard-delay-4">
                <div class="stats-card-body">
                    <div class="stats-icon warning">
                        <i class="fa fa-clock-o"></i>
                    </div>
                    <div class="stats-info">
                        <h3 class="revenue-count">{{ number_format($revenueStats['pending_payments'], 0) }}</h3>
                        <p>قيمة EGP معلقة الآن</p>
                        <small class="text-muted">{{ $revenueStats['pending_bills_count'] }} عملية بكل القنوات</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stats-card info h-100"><div class="stats-card-body"><div class="stats-info">
                <h3>{{ $ai['cost_usd'] === null || (!$ai['cost_complete'] && $ai['provider_cost_requests'] === 0 && $ai['cost_usd'] == 0) ? 'غير متاح' : number_format($ai['cost_usd'], 6) }}</h3>
                <p>استهلاك الذكاء الاصطناعي المؤكد (USD)</p>
                @if(!$ai['cost_complete'])<small class="text-warning">قياس جزئي · {{ number_format($ai['estimated_cost_requests']) }} طلبًا بلا تكلفة مؤكدة</small><br>@endif
                @include('admin.reports.growth', ['change' => $aiChange, 'neutral' => true])
            </div></div></div>
        </div>
    </div>

    @include('admin.orders.partials.index.payment-channel-report')
    @include('admin.reports.provider-invoices', ['invoiceReport' => $invoiceReport])

    <!-- Revenue Charts Section -->
    <div class="row mb-4">
        <!-- Monthly Revenue Trend Chart -->
        <div class="col-lg-8 mb-4">
            <div class="chart-card fade-in-left">
                <div class="chart-card-header">
                    <h4 class="chart-card-title">
                        <i class="fa fa-line-chart"></i>
                        تحصيل الفترة حسب الشهر
                    </h4>
                    <p class="chart-card-subtitle">Kashier وGoogle Play وApp Store؛ التحصيل المؤكد فقط</p>
                </div>
                <div class="chart-card-body">
                    <div class="chart-container dashboard-chart--large">
                        <canvas id="monthlyRevenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Additional Stats -->
    <div class="row mb-4">
        <!-- Revenue Summary Cards -->
        <div class="col-lg-6">
            <div class="row">
                <!-- Current Month Total Revenue -->
                <div class="col-12 mb-3">
                    <div class="summary-card primary fade-in-right dashboard-delay-1">
                        <div class="summary-card-content">
                            <div class="summary-card-info">
                                <h3>{{ !$revenueStats['gross_complete'] && $revenueStats['confirmed_gross_count'] === 0 ? 'بانتظار تأكيد التحصيل' : number_format($revenueStats['total_revenue'], 0) }}</h3>
                                <p>المحصل المؤكد عبر قنوات الدفع خلال الفترة</p>
                                <small class="text-muted">{{ $period->label() }}</small>
                            </div>
                            <div class="summary-card-icon">
                                <i class="fa fa-calendar"></i>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Previous Month Total Revenue -->
                <div class="col-12 mb-3">
                    <div class="summary-card primary fade-in-right dashboard-delay-3">
                        <div class="summary-card-content">
                            <div class="summary-card-info">
                                <h3>{{ $revenueStats['previous_period_revenue'] === null ? 'لا توجد فترة مقارنة' : ($revenueStats['previous_gross_unknown'] ? 'بانتظار تأكيد التحصيل' : number_format($revenueStats['previous_period_revenue'], 0)) }}</h3>
                                <p>المحصل المؤكد في الفترة السابقة المماثلة</p>
                                <small class="text-muted">{{ $period->previous()?->description() }}</small>
                            </div>
                            <div class="summary-card-icon">
                                <i class="fa fa-history"></i>
                            </div>
                        </div>
                    </div>
                </div>



            </div>
        </div>
    </div>

    <!-- Course Statistics Section -->
    @if($courseStats->count() > 0)
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-4 dashboard-section-title">
                <i class="fa fa-graduation-cap dashboard-section-title__icon"></i>
                إحصائيات الكورسات
            </h3>
        </div>
    </div>

    <div class="row mb-4">
        <!-- Course Revenue Chart -->
        <div class="col-lg-8 mb-4">
            <div class="chart-card fade-in-left">
                <div class="chart-card-header">
                    <h4 class="chart-card-title">
                        <i class="fa fa-bar-chart"></i>
                        مصدر العملات المصروفة على الكورسات
                    </h4>
                    <p class="chart-card-subtitle">العملات منذ البداية · مشتراة بمال مقابل مكافآت وليست إيرادًا نقديًا</p>
                </div>
                <div class="chart-card-body">
                    <div class="chart-container dashboard-chart--large">
                        <canvas id="courseRevenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Course Statistics Table -->
        <div class="col-lg-4 mb-4">
            <div class="chart-card fade-in-right dashboard-delay-1">
                <div class="chart-card-header">
                    <h5 class="chart-card-title">
                        <i class="fa fa-list"></i>
                        ملخص فتح الكورسات
                    </h5>
                    <p class="chart-card-subtitle">إجمالي الفتح والعملات منذ البداية مع عدد الفتح خلال الفترة</p>
                </div>
                <div class="chart-card-body dashboard-table-scroll">
                    <table class="table table-sm course-stats-table dashboard-course-table">
                        <thead class="dashboard-course-table__head">
                            <tr>
                                <th class="dashboard-course-table__heading">الكورس</th>
                                <th class="text-center dashboard-course-table__heading">الفتح</th>
                                <th class="text-end dashboard-course-table__heading">مشتراة / مكافآت</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($courseStats as $course)
                            <tr class="dashboard-course-table__row">
                                <td class="dashboard-course-table__cell">
                                    <div class="dashboard-course-name" title="{{ $course['name'] }}">
                                        {{ $course['name'] }}
                                    </div>
                                    <small class="dashboard-secondary-text">
                                        خلال الفترة: {{ $course['current_period_buy_count'] }}
                                    </small>
                                    @if($course['incomplete_orders'])
                                        <br><small class="text-warning">{{ number_format($course['incomplete_orders']) }} عملية تحتاج ربط الدفتر</small>
                                    @endif
                                </td>
                                <td class="text-center dashboard-course-table__cell">
                                    <strong>{{ $course['total_buy_count'] }}</strong>
                                </td>
                                <td class="text-end dashboard-course-table__cell">
                                    <strong>{{ number_format($course['paid_coins'], 0) }}</strong>
                                    <br>
                                    <small class="dashboard-secondary-text">
                                        {{ number_format($course['reward_coins'], 0) }}
                                    </small>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif



</div>

    {{-- Legacy e-commerce widgets removed --}}

@endsection
@section('scripts')
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>

    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            // Counter Animation
            function animateCounters() {
                const counters = document.querySelectorAll('.count, .revenue-count');
                counters.forEach(counter => {
                    const isRevenue = counter.classList.contains('revenue-count');
                    const target = parseFloat(counter.textContent.replace(/,/g, ''));
                    const increment = target / 50;
                    let current = 0;

                    const updateCounter = () => {
                        if (current < target) {
                            current += increment;
                            const value = isRevenue ? Math.round(current) : Math.ceil(current);
                            counter.textContent = isRevenue
                                ? value.toLocaleString('en-US')
                                : value;
                            setTimeout(updateCounter, 20);
                        } else {
                            counter.textContent = isRevenue
                                ? Math.round(target).toLocaleString('en-US')
                                : target;
                        }
                    };

                    setTimeout(updateCounter, 500);
                });
            }

            // Start counter animation
            setTimeout(animateCounters, 600);

            // The dashboard remains usable if the optional chart CDN is unavailable.
            if (typeof window.Chart !== 'function') {
                return;
            }


            // ============================================
            // REVENUE CHARTS
            // ============================================

            // Monthly Revenue Trend Chart
            const monthlyRevenueCtx = document.getElementById('monthlyRevenueChart');
            if (monthlyRevenueCtx) {
                const monthlyRevenueChart = new Chart(monthlyRevenueCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: {!! json_encode(array_column($monthlyRevenue, 'month')) !!},
                        datasets: [
                            {
                                label: 'شحن رصيد عبر قنوات الدفع (جنيه)',
                                data: {!! json_encode(array_column($monthlyRevenue, 'course_revenue')) !!},
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: '#2563eb',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 8
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: '#6c757d',
                                    padding: 15,
                                    usePointStyle: true,
                                    font: {
                                        size: 12,
                                        family: 'Arial'
                                    }
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(255, 255, 255, 0.95)',
                                titleColor: '#2c3e50',
                                bodyColor: '#2c3e50',
                                borderColor: '#e9ecef',
                                borderWidth: 1,
                                cornerRadius: 8,
                                padding: 12,
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y.toFixed(2);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(102, 126, 234, 0.1)'
                                },
                                ticks: {
                                    color: '#8e9bae',
                                    callback: function(value) {
                                        return value.toFixed(0);
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: '#8e9bae'
                                }
                            }
                        }
                    }
                });
            }


            // ============================================
            // COURSE STATISTICS CHART
            // ============================================

            // Course Revenue Chart
            const courseRevenueCtx = document.getElementById('courseRevenueChart');
            if (courseRevenueCtx && {!! json_encode($courseStats->count()) !!} > 0) {
                const courseRevenueChart = new Chart(courseRevenueCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: {!! json_encode($courseStats->pluck('name')) !!},
                        datasets: [
                            {
                                label: 'عملات مشتراة بمال',
                                data: {!! json_encode($courseStats->pluck('paid_coins')) !!},
                                backgroundColor: 'rgba(102, 126, 234, 0.8)',
                                borderColor: '#2563eb',
                                borderWidth: 2,
                                borderRadius: 6,
                                barThickness: 'flex',
                                maxBarThickness: 40
                            },
                            {
                                label: 'عملات مكافآت',
                                data: {!! json_encode($courseStats->pluck('reward_coins')) !!},
                                backgroundColor: 'rgba(72, 187, 120, 0.8)',
                                borderColor: '#48bb78',
                                borderWidth: 2,
                                borderRadius: 6,
                                barThickness: 'flex',
                                maxBarThickness: 40
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: '#6c757d',
                                    padding: 15,
                                    usePointStyle: true,
                                    font: {
                                        size: 12,
                                        family: 'Arial'
                                    }
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(255, 255, 255, 0.95)',
                                titleColor: '#2c3e50',
                                bodyColor: '#2c3e50',
                                borderColor: '#e9ecef',
                                borderWidth: 1,
                                cornerRadius: 8,
                                padding: 12,
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + Math.round(context.parsed.y).toLocaleString('en-US');
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(102, 126, 234, 0.1)'
                                },
                                ticks: {
                                    color: '#8e9bae',
                                    callback: function(value) {
                                        return Math.round(value).toLocaleString('en-US');
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: '#8e9bae',
                                    maxRotation: 45,
                                    minRotation: 45,
                                    font: {
                                        size: 10
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Add hover effects to cards
            const cards = document.querySelectorAll('.stats-card, .summary-card, .chart-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
    </script>
@endsection
