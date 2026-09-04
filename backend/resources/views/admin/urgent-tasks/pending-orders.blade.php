@extends('admin.layouts.app')

@section('page.title', 'الطلبات المعلقة')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.urgent-tasks.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/urgent-tasks-shared.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/urgent-tasks-pending-orders.css') }}">
@endsection

@section('content')
<div class="admin-page urgent-subpage-container">
    <!-- Header Section -->
    <div class="subpage-header">
        <div class="subpage-title">
            <h2><i class="fa fa-shopping-cart"></i> الطلبات المعلقة</h2>
            <span class="badge">{{ $pendingOrders->total() }} طلب</span>
        </div>
        <a href="{{ route('admin.urgent-tasks.index') }}" class="back-button btn-cancel-modern">
            <i class="fa fa-arrow-left"></i> العودة للمهام العاجلة
        </a>
    </div>

    <!-- Main Content -->
    <div class="row">
        <div class="col-12">
            <div class="modern-data-card">
                <div class="data-card-body">
                    @if($pendingOrders->count() > 0)
                        <div class="table-responsive">
                            <table class="modern-table-enhanced table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>الطالب</th>
                                        <th>الكورس</th>
                                        <th>كود الكورس</th>
                                        <th>المبلغ</th>
                                        <th>تاريخ الطلب</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pendingOrders as $order)
                                    <tr id="order-row-{{ $order->id }}">
                                        <td>
                                            <span class="urgent-row-number">
                                                {{ $pendingOrders->firstItem() + $loop->index }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="user-info-enhanced">
                                                <div class="user-avatar-enhanced">
                                                    {{ substr($order->user->name, 0, 1) }}
                                                </div>
                                                <div class="user-details">
                                                    <a href="{{ route('admin.users.show', $order->user->id) }}" class="user-name urgent-entity-link">{{ $order->user->name }}</a>
                                                    <div class="user-contact">
                                                        <i class="fa fa-phone"></i> {{ $order->user->phone }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="course-info">
                                                @if($order->course)
                                                    <a href="{{ route('admin.courses.show', $order->course->id) }}" class="course-name urgent-entity-link">{{ $order->course->title }}</a>
                                                @else
                                                    <div class="course-name">غير محدد</div>
                                                @endif
                                                @if($order->course && $order->course->grade)
                                                    <div class="course-meta">
                                                        <i class="fa fa-graduation-cap"></i> <a href="{{ route('admin.grades.index') }}" class="urgent-muted-link">{{ $order->course->grade->name }}</a>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            @if($order->courseCode)
                                                <span class="status-badge-enhanced primary">{{ $order->courseCode->code }}</span>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="status-badge-enhanced amount">{{ number_format($order->amount, 2) }} ج.م</span>
                                        </td>
                                        <td>
                                            <div class="urgent-date">
                                                {{ $order->created_at->format('Y-m-d') }}
                                            </div>
                                            <div class="urgent-date-meta">
                                                {{ \App\Support\BusinessClock::display($order->created_at, 'H:i') }} · {{ \App\Support\BusinessClock::relative($order->created_at) }}
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons-enhanced">
                                                <form action="{{ route('admin.urgent-tasks.approve-order', $order->id) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="action-btn-enhanced btn-success-center">
                                                        <i class="fa fa-check"></i> قبول
                                                    </button>
                                                </form>
                                                <form action="{{ route('admin.urgent-tasks.reject-order', $order->id) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="action-btn-enhanced btn-danger-center">
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

                        <!-- Enhanced Pagination -->
                        <div class="d-flex justify-content-center">
                            {{ $pendingOrders->links() }}
                        </div>
                    @else
                        <div class="empty-state-enhanced">
                            <i class="fa fa-check-circle fa-5x text-success"></i>
                            <h3>ممتاز! لا توجد طلبات معلقة</h3>
                            <p>جميع الطلبات تمت معالجتها بنجاح</p>
                            <a href="{{ route('admin.urgent-tasks.index') }}" class="action-btn-enhanced btn-cancel-modern urgent-empty-action">
                                <i class="fa fa-arrow-left"></i> العودة للمهام العاجلة
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Enhanced form submission with better UX
    const actionForms = document.querySelectorAll('form[action*="approve-order"], form[action*="reject-order"]');
    actionForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const button = this.querySelector('button[type="submit"]');
            const originalText = button.innerHTML;
            const action = this.action.includes('approve') ? 'قبول' : 'رفض';

            if (confirm(`هل أنت متأكد من ${action} هذا الطلب؟`)) {
                // Show loading state
                button.innerHTML = '<i class="fa fa-spinner fa-spin"></i> جاري المعالجة...';
                button.disabled = true;

                // Submit form
                this.submit();
            }
        });
    });

    // Add smooth hover effects
    const rows = document.querySelectorAll('.modern-table-enhanced tbody tr');
    rows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
            this.style.transform = 'scale(1.002)';
        });

        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
            this.style.transform = 'scale(1)';
        });
    });
});
</script>
@endsection

@endsection
