@extends('admin.layouts.app')

@section('page.title', 'المهام العاجلة')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.urgent-tasks.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/urgent-tasks-shared.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/urgent-tasks-index.css') }}">
@endsection

@section('content')
<div class="admin-page urgent-tasks-container">
    <!-- Header Section -->
    <div class="urgent-header fade-in-up-urgent">
        <h2><i class="fa fa-exclamation-triangle urgent-header-icon"></i>المهام العاجلة</h2>
        <p>مراقبة وإدارة المهام التي تتطلب اهتماماً فورياً</p>
    </div>

    <!-- System Warnings -->
    @if(!$hasGrades || !$hasCourses)
    <div class="system-warnings-card fade-in-up-urgent urgent-delay-005">
        <div class="system-warnings-header">
            <i class="fa fa-exclamation-circle"></i>
            <h5>تنبيهات النظام</h5>
        </div>
        <p class="system-warnings-description">يرجى إضافة البيانات الأساسية التالية:</p>

        @if(!$hasGrades)
        <div class="warning-item">
            <div class="warning-content">
                <strong>
                    <i class="fa fa-graduation-cap"></i>
                    لا توجد مراحل دراسية
                </strong>
                <p>يجب إضافة مرحلة دراسية واحدة على الأقل لتنظيم الكورسات والطلاب</p>
            </div>
            <a href="{{ route('admin.grades.create') }}" class="warning-action-btn">
                <i class="fa fa-plus"></i> إضافة مرحلة دراسية
            </a>
        </div>
        @endif

        @if(!$hasCourses)
        <div class="warning-item">
            <div class="warning-content">
                <strong>
                    <i class="fa fa-book"></i>
                    لا توجد كورسات
                </strong>
                <p>يجب إضافة كورس واحد على الأقل لبدء التدريس ونشر المحتوى التعليمي</p>
            </div>
            <a href="{{ route('admin.courses.create') }}" class="warning-action-btn">
                <i class="fa fa-plus"></i> إضافة كورس
            </a>
        </div>
        @endif
    </div>
    @endif

    <!-- Statistics Cards -->
    <div class="row mb-5">
        <div class="col-lg-6 col-md-6 mb-4">
            <a href="{{ route('admin.urgent-tasks.pending-orders') }}" class="text-decoration-none">
                <div class="urgent-stat-card danger fade-in-up-urgent urgent-delay-01">
                    <div class="urgent-stat-content">
                        <div class="urgent-stat-info">
                            <h3 class="count">{{ $stats['pending_orders_count'] }}</h3>
                            <p>طلبات الشراء المعلقة</p>
                            <small>تحتاج لمراجعة وموافقة</small>
                        </div>
                        <div class="urgent-stat-icon danger">
                            <i class="fa fa-shopping-cart"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-lg-6 col-md-6 mb-4">
            <a href="{{ route('admin.urgent-tasks.inactive-students') }}" class="text-decoration-none">
                <div class="urgent-stat-card warning fade-in-up-urgent urgent-delay-02">
                    <div class="urgent-stat-content">
                        <div class="urgent-stat-info">
                            <h3 class="count">{{ $stats['inactive_students_count'] }}</h3>
                            <p>الطلاب غير المفعلين</p>
                            <small>طلاب متوقفون عن الدراسة</small>
                        </div>
                        <div class="urgent-stat-icon warning">
                            <i class="fa fa-user-times"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>

    </div>

    <!-- Quick Actions and Details -->
    <div class="row">
        <!-- Pending Orders Section -->
        @if($pendingOrders->count() > 0)
        <div class="col-lg-6 mb-4">
            <div class="modern-section-card fade-in-up-urgent urgent-delay-04">
                <div class="modern-card-header">
                    <h5 class="modern-card-title">
                        <i class="fa fa-shopping-cart text-danger"></i>
                        طلبات الشراء المعلقة
                        <span class="badge badge-danger">{{ $stats['pending_orders_count'] }}</span>
                    </h5>
                    <a href="{{ route('admin.urgent-tasks.pending-orders') }}" class="modern-btn btn-primary-center">
                        <i class="fa fa-eye"></i>
                        عرض الكل
                    </a>
                </div>
                <div class="modern-table-container">
                    <div class="table-responsive">
                        <table class="modern-table table mb-0">
                            <thead>
                                <tr>
                                    <th>الطالب</th>
                                    <th>الكورس</th>
                                    <th>المبلغ</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pendingOrders->take(5) as $order)
                                <tr id="order-row-{{ $order->id }}">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar">
                                                {{ substr($order->user->name, 0, 2) }}
                                            </div>
                                            <div>
                                                <a href="{{ route('admin.users.show', $order->user->id) }}" class="d-block urgent-entity-link">{{ $order->user->name }}</a>
                                                <small class="text-muted">{{ $order->user->phone }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @if($order->course)
                                            <a href="{{ route('admin.courses.show', $order->course->id) }}" class="d-block urgent-entity-link">{{ $order->course->title }}</a>
                                        @else
                                            <strong class="d-block">غير محدد</strong>
                                        @endif
                                        <small class="text-muted">{{ \App\Support\BusinessClock::relative($order->created_at) }}</small>
                                    </td>
                                    <td>
                                        <span class="status-badge amount">{{ number_format($order->amount, 2) }}</span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <form action="{{ route('admin.urgent-tasks.approve-order', $order->id) }}" method="POST" class="urgent-inline-form">
                                                @csrf
                                                <button type="submit" class="action-btn btn-success-center">
                                                    <i class="fa fa-check"></i> قبول
                                                </button>
                                            </form>
                                            <form action="{{ route('admin.urgent-tasks.reject-order', $order->id) }}" method="POST" class="urgent-inline-form">
                                                @csrf
                                                <button type="submit" class="action-btn btn-danger-center">
                                                    <i class="fa fa-times"></i> رفض
                                                </button>
                                            </form>
                                        </div>
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

        <!-- Inactive Students Section -->
        @if($inactiveStudents->count() > 0)
        <div class="col-lg-6 mb-4">
            <div class="modern-section-card fade-in-up-urgent urgent-delay-05">
                <div class="modern-card-header">
                    <h5 class="modern-card-title">
                        <i class="fa fa-user-times text-warning"></i>
                        الطلاب غير المفعلين
                        <span class="badge badge-warning">{{ $stats['inactive_students_count'] }}</span>
                    </h5>
                    <a href="{{ route('admin.urgent-tasks.inactive-students') }}" class="modern-btn btn-primary-center">
                        <i class="fa fa-eye"></i>
                        عرض الكل
                    </a>
                </div>
                <div class="modern-table-container">
                    <div class="table-responsive">
                        <table class="modern-table table mb-0">
                            <thead>
                                <tr>
                                    <th>الطالب</th>
                                    <th>السبب</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($inactiveStudents->take(5) as $student)
                                <tr id="student-row-{{ $student->id }}">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar">
                                                {{ substr($student->name, 0, 2) }}
                                            </div>
                                            <div>
                                                <a href="{{ route('admin.users.show', $student->id) }}" class="d-block urgent-entity-link">{{ $student->name }}</a>
                                                <small class="text-muted">{{ $student->phone }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <small class="text-muted">غير مفعل</small>
                                    </td>
                                    <td>
                                        <form action="{{ route('admin.urgent-tasks.activate-student', $student->id) }}" method="POST" class="urgent-inline-form">
                                            @csrf
                                            <input type="hidden" name="expected_active" value="0">
                                            <input type="hidden" name="state_version" value="{{ $accountStateVersions[$student->id] }}">
                                            <button type="submit" class="action-btn btn-success-center">
                                                <i class="fa fa-check"></i> تفعيل
                                            </button>
                                        </form>
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

    <!-- Empty State -->
    @if($stats['total_urgent_tasks'] == 0)
    <div class="row">
        <div class="col-12">
            <div class="empty-state fade-in-up-urgent urgent-delay-07">
                <i class="fa fa-check-circle fa-5x text-success"></i>
                <h3>ممتاز! لا توجد مهام عاجلة</h3>
                <p>جميع المهام الأساسية مكتملة ولا توجد عناصر تحتاج لاهتمام فوري</p>
                <div class="mt-4">
                    <a href="{{ route('admin.dashboard') }}" class="modern-btn btn-success urgent-empty-action">
                        <i class="fa fa-dashboard"></i>
                        العودة لللوحة الرئيسية
                    </a>
                    <a href="{{ route('admin.courses.index') }}" class="modern-btn btn-outline-primary urgent-empty-action">
                        <i class="fa fa-graduation-cap"></i>
                        إدارة الكورسات
                    </a>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Counter Animation
    function animateCounters() {
        const counters = document.querySelectorAll('.count');
        counters.forEach(counter => {
            const target = parseInt(counter.textContent);
            const increment = target / 30;
            let current = 0;

            const updateCounter = () => {
                if (current < target) {
                    current += increment;
                    counter.textContent = Math.ceil(current);
                    setTimeout(updateCounter, 30);
                } else {
                    counter.textContent = target;
                }
            };

            setTimeout(updateCounter, 500);
        });
    }

    // Start counter animation
    setTimeout(animateCounters, 800);

    // Enhanced hover effects
    const cards = document.querySelectorAll('.urgent-stat-card, .modern-section-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = this.classList.contains('urgent-stat-card') ? 'translateY(-8px) scale(1.02)' : 'translateY(-3px)';
        });

        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Action button confirmations with modern styling
    const actionForms = document.querySelectorAll('form[action*="approve-order"], form[action*="reject-order"], form[action*="activate-student"]');
    actionForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const action = this.action.includes('approve') ? 'قبول' :
                          this.action.includes('reject') ? 'رفض' : 'تفعيل';
            const type = this.action.includes('order') ? 'الطلب' : 'الطالب';

            if (confirm(`هل أنت متأكد من ${action} هذا ${type}؟`)) {
                this.submit();
            }
        });
    });
});
</script>
@endsection

@endsection
