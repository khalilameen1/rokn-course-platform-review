@extends('admin.layouts.app')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/home-dashboard.css') }}">
@endsection
@section('page.title', 'لوحة التحكم')
@section('content')
@php
    $grossPending = !$revenueStats['gross_complete'] && $revenueStats['confirmed_gross_count'] === 0;
    $netPending = $revenueStats['provider_settlement_pending_count'] > 0 && $revenueStats['confirmed_net_count'] === 0;
    $aiUnknown = $ai['cost_usd'] === null || (!$ai['cost_complete'] && $ai['provider_cost_requests'] === 0 && $ai['cost_usd'] == 0);
@endphp
<div class="admin-page dashboard-container">
    <header class="dashboard-heading">
        <h2>التشغيل اليومي</h2>
        <p>الأداء المالي وحالة المنصة في مكان واحد</p>
    </header>

    <form method="GET" action="{{ route('admin.dashboard') }}" class="dashboard-toolbar" aria-label="فترة التقرير">
        <div class="dashboard-period-field">
            @include('admin.reports.period-fields', ['period' => $period])
        </div>
        <button type="submit" class="dashboard-button">تطبيق</button>
        <p class="dashboard-period-description">{{ $period->description() }}</p>
        <a class="dashboard-text-link" href="{{ route('admin.product-analytics.index', ['period' => $period->key]) }}">تحليلات المنتج</a>
    </form>

    <section class="dashboard-financials" aria-labelledby="dashboardFinancialTitle">
        <div class="dashboard-section-heading">
            <h3 id="dashboardFinancialTitle">الأداء المالي</h3>
            <span>{{ $period->label() }}</span>
        </div>
        <div class="dashboard-metrics">
            <article class="dashboard-metric dashboard-metric--primary">
                <h4>تحصيل الفترة المؤكد بكل القنوات (جنيه)</h4>
                <strong class="dashboard-metric-value {{ $grossPending ? 'is-pending' : '' }}">{{ $grossPending ? 'بانتظار تأكيد التحصيل' : number_format($revenueStats['total_revenue'], 2) }}</strong>
                <div class="dashboard-metric-detail">
                    @include('admin.reports.growth', ['change' => $revenueStats['revenue_change']])
                    @if($revenueStats['catalog_estimated_revenue'] > 0)
                        <small class="text-warning">{{ number_format($revenueStats['catalog_estimated_revenue'], 2) }} تقدير كتالوج خارج الإجمالي</small>
                    @endif
                </div>
            </article>
            <article class="dashboard-metric">
                <h4>الصافي المؤكد من كشوف المزودين</h4>
                <strong class="dashboard-metric-value {{ $netPending ? 'is-pending' : '' }}">{{ $netPending ? 'بانتظار التسوية' : number_format($revenueStats['confirmed_net_revenue'], 2) }}</strong>
                <div class="dashboard-metric-detail">
                    @include('admin.reports.growth', ['change' => $revenueStats['net_change']])
                    @if($revenueStats['provider_settlement_pending_count'] > 0)
                        <small class="text-warning">جزئي · {{ number_format($revenueStats['provider_settlement_pending_count']) }} عملية بانتظار كشف التسوية</small>
                    @else
                        <small>جنيه بعد الرسوم والاستقطاعات</small>
                    @endif
                </div>
            </article>
            <article class="dashboard-metric">
                <h4>استهلاك الذكاء الاصطناعي المؤكد (USD)</h4>
                <strong class="dashboard-metric-value {{ $aiUnknown ? 'is-pending' : '' }}">{{ $aiUnknown ? 'غير متاح' : number_format($ai['cost_usd'], 6) }}</strong>
                <div class="dashboard-metric-detail">
                    @include('admin.reports.growth', ['change' => $aiChange, 'neutral' => true])
                    @if(!$ai['cost_complete'])
                        <small class="text-warning">قياس جزئي · {{ number_format($ai['estimated_cost_requests']) }} طلبًا بلا تكلفة مؤكدة</small>
                    @endif
                </div>
            </article>
        </div>
    </section>

    <section class="dashboard-platform-summary" aria-label="حالة المنصة الآن">
        <div><strong>{{ number_format($platformStats['courses']) }}</strong><span>إجمالي الكورسات الآن</span></div>
        <div><strong>{{ number_format($platformStats['lessons']) }}</strong><span>إجمالي الدروس الآن</span></div>
        <div><strong>{{ number_format($platformStats['students']) }}</strong><span>إجمالي الطلاب الآن</span></div>
        <div><strong>{{ number_format($revenueStats['pending_payments'], 2) }} <small>EGP</small></strong><span>قيمة معلقة الآن · {{ $revenueStats['pending_bills_count'] }} عملية بكل القنوات</span></div>
    </section>

    <nav class="dashboard-priority-nav" aria-label="ما يحتاج متابعة">
        <a href="{{ route('admin.product-operations.index') }}"><strong>حالة النشر</strong><span>فحوص المنتج والكورسات</span></a>
        <a href="{{ route('admin.playback-operations.index') }}"><strong>الوسائط</strong><span>مشكلات تشغيل الفيديو</span></a>
        <a href="{{ route('admin.project-submissions.index') }}"><strong>المشاريع</strong><span>المراجعات المنتظرة</span></a>
        <a href="{{ route('admin.orders.index') }}"><strong>{{ number_format($revenueStats['pending_bills_count']) }} مدفوعات معلقة</strong><span>راجع مسار عمليات الدفع</span></a>
    </nav>

    <div class="dashboard-analysis-grid">
        <section class="dashboard-panel" aria-labelledby="dashboardRevenueTitle">
            <div class="dashboard-panel-heading">
                <h3 id="dashboardRevenueTitle">تحصيل الفترة حسب الشهر</h3>
                <p>Kashier وGoogle Play وApp Store · التحصيل المؤكد فقط</p>
            </div>
            <div class="dashboard-chart"><canvas id="monthlyRevenueChart" role="img" aria-label="تحصيل الفترة المؤكد حسب الشهر"></canvas></div>
        </section>
        <section class="dashboard-panel dashboard-comparison" aria-labelledby="dashboardComparisonTitle">
            <div class="dashboard-panel-heading"><h3 id="dashboardComparisonTitle">مقارنة التحصيل</h3></div>
            <dl>
                <div>
                    <dt>المحصل المؤكد عبر قنوات الدفع خلال الفترة</dt>
                    <dd>{{ $grossPending ? 'بانتظار تأكيد التحصيل' : number_format($revenueStats['total_revenue'], 2) }}</dd>
                    <small>{{ $period->label() }}</small>
                </div>
                <div>
                    <dt>المحصل المؤكد في الفترة السابقة المماثلة</dt>
                    <dd>{{ $revenueStats['previous_period_revenue'] === null ? 'لا توجد فترة مقارنة' : ($revenueStats['previous_gross_unknown'] ? 'بانتظار تأكيد التحصيل' : number_format($revenueStats['previous_period_revenue'], 2)) }}</dd>
                    <small>{{ $period->previous()?->description() }}</small>
                </div>
            </dl>
        </section>
    </div>

    @include('admin.orders.partials.index.payment-channel-report')
    @include('admin.reports.provider-invoices', ['invoiceReport' => $invoiceReport])

    @if($courseStats->count() > 0)
    <section aria-labelledby="dashboardCoursesTitle">
        <div class="dashboard-section-heading"><h3 id="dashboardCoursesTitle">إحصائيات الكورسات</h3><span>العملات منذ البداية</span></div>
        <div class="dashboard-analysis-grid dashboard-analysis-grid--courses">
            <div class="dashboard-panel">
                <div class="dashboard-panel-heading">
                    <h4>مصدر العملات المصروفة على الكورسات</h4>
                    <p>مشتراة بمال مقابل مكافآت وليست إيرادًا نقديًا</p>
                </div>
                <div class="dashboard-chart"><canvas id="courseRevenueChart" role="img" aria-label="العملات المشتراة والمكافآت المصروفة على الكورسات"></canvas></div>
            </div>
            <div class="dashboard-panel">
                <div class="dashboard-panel-heading"><h4>ملخص فتح الكورسات</h4><p>إجمالي الفتح والعملات منذ البداية مع عدد الفتح خلال الفترة</p></div>
                <div class="dashboard-table-scroll">
                    <table class="table table-sm dashboard-course-table">
                        <thead><tr><th>الكورس</th><th>الفتح</th><th>مشتراة / مكافآت</th></tr></thead>
                        <tbody>
                        @foreach($courseStats as $course)
                            <tr>
                                <td>
                                    <strong class="dashboard-course-name" title="{{ $course['name'] }}">{{ $course['name'] }}</strong>
                                    <small class="dashboard-secondary-text">خلال الفترة: {{ $course['current_period_buy_count'] }}</small>
                                    @if($course['incomplete_orders'])<small class="text-warning">{{ number_format($course['incomplete_orders']) }} عملية تحتاج ربط الدفتر</small>@endif
                                </td>
                                <td>{{ $course['total_buy_count'] }}</td>
                                <td><strong>{{ number_format($course['paid_coins'], 0) }}</strong><small class="dashboard-secondary-text">{{ number_format($course['reward_coins'], 0) }}</small></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
    @endif
</div>
@endsection

@section('scripts')
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>

    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            // The dashboard remains usable if the optional chart CDN is unavailable.
            if (typeof window.Chart !== 'function') {
                return;
            }


            const theme = getComputedStyle(document.body);
            const primary = theme.getPropertyValue('--rokn-admin-primary').trim() || '#2c69db';
            const muted = theme.getPropertyValue('--rokn-admin-muted').trim() || '#64748b';
            const ink = theme.getPropertyValue('--rokn-admin-text').trim() || '#18233b';
            const surface = theme.getPropertyValue('--rokn-admin-surface').trim() || '#fff';
            const border = theme.getPropertyValue('--rokn-admin-border').trim() || '#e2e8f0';

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
                                borderColor: primary,
                                backgroundColor: border,
                                borderWidth: 3,
                                fill: false,
                                tension: 0.4,
                                pointBackgroundColor: primary,
                                pointBorderColor: surface,
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 8
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        animation: false,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: muted,
                                    padding: 15,
                                    usePointStyle: true,
                                    font: {
                                        size: 12,
                                        family: theme.fontFamily
                                    }
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: surface,
                                titleColor: ink,
                                bodyColor: ink,
                                borderColor: border,
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
                                    color: border
                                },
                                ticks: {
                                    color: muted,
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
                                    color: muted
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
                                backgroundColor: primary,
                                borderColor: primary,
                                borderWidth: 2,
                                borderRadius: 6,
                                barThickness: 'flex',
                                maxBarThickness: 40
                            },
                            {
                                label: 'عملات مكافآت',
                                data: {!! json_encode($courseStats->pluck('reward_coins')) !!},
                                backgroundColor: muted,
                                borderColor: muted,
                                borderWidth: 2,
                                borderRadius: 6,
                                barThickness: 'flex',
                                maxBarThickness: 40
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        animation: false,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: muted,
                                    padding: 15,
                                    usePointStyle: true,
                                    font: {
                                        size: 12,
                                        family: theme.fontFamily
                                    }
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: surface,
                                titleColor: ink,
                                bodyColor: ink,
                                borderColor: border,
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
                                    color: border
                                },
                                ticks: {
                                    color: muted,
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
                                    color: muted,
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

        });
    </script>
@endsection
