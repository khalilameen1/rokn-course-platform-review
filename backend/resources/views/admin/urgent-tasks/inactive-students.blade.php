@extends('admin.layouts.app')

@section('page.title', 'الطلاب غير المفعلين')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.urgent-tasks.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/urgent-tasks-shared.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/urgent-tasks-inactive-students.css') }}">
@endsection

@section('content')
<div class="admin-page urgent-subpage-container">
    <!-- Header Section -->
    <div class="subpage-header warning">
        <div class="subpage-title">
            <h2><i class="fa fa-user-times"></i> الطلاب غير المفعلين</h2>
            <span class="badge">{{ $inactiveStudents->total() }} طالب</span>
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
                    @if($inactiveStudents->count() > 0)
                        <div class="table-responsive">
                            <table class="modern-table-enhanced table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>الطالب</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($inactiveStudents as $student)
                                    <tr id="student-row-{{ $student->id }}">
                                        <td>
                                            <span class="urgent-row-number">
                                                {{ $inactiveStudents->firstItem() + $loop->index }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="user-info-enhanced">
                                                <div class="user-avatar-enhanced">
                                                    {{ substr($student->name, 0, 1) }}
                                                </div>
                                                <div class="user-details">
                                                    <a href="{{ route('admin.users.show', $student->id) }}" class="user-name urgent-entity-link">{{ $student->name }}</a>
                                                    <div class="user-contact">
                                                        <i class="fa fa-phone"></i> {{ $student->phone }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-muted urgent-muted-copy">
                                                <i class="fa fa-info-circle"></i> غير مفعل
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons-enhanced">
                                                <form action="{{ route('admin.urgent-tasks.activate-student', $student->id) }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="expected_active" value="0">
                                                    <input type="hidden" name="state_version" value="{{ $accountStateVersions[$student->id] }}">
                                                    <button type="submit" class="action-btn-enhanced btn-success-center">
                                                        <i class="fa fa-check"></i> تفعيل
                                                    </button>
                                                </form>
                                                <a href="{{ route('admin.users.show', $student->id) }}" class="action-btn-enhanced btn-primary-center">
                                                    <i class="fa fa-eye"></i> عرض الملف
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Enhanced Pagination -->
                        <div class="d-flex justify-content-center">
                            {{ $inactiveStudents->links() }}
                        </div>
                    @else
                        <div class="empty-state-enhanced">
                            <i class="fa fa-check-circle fa-5x text-success"></i>
                            <h3>ممتاز! جميع الطلاب مفعلون</h3>
                            <p>لا يوجد طلاب غير مفعلين حاليًا</p>
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
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Enhanced form submission
    const activateForms = document.querySelectorAll('form[action*="activate-student"]');
    activateForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const button = this.querySelector('button[type="submit"]');
            const originalText = button.innerHTML;

            if (confirm('هل أنت متأكد من تفعيل هذا الطالب؟')) {
                // Show loading state
                button.innerHTML = '<i class="fa fa-spinner fa-spin"></i> جاري التفعيل...';
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
