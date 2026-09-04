@extends('admin.layouts.app')

@section('page.title', 'كورسات المرحلة الدراسية')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.grades.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/grades-courses.css') }}">

@endsection

@section('content')
    <div class="container-fluid grades-module admin-page">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card border-0 shadow-lg fade-in">
                    <!-- Enhanced Header -->
                    <div class="courses-header">
                        <div class="d-flex justify-content-between align-items-start flex-wrap">
                            <div>
                                <h1 class="mb-2">
                                    <i class="fa fa-book ml-2"></i>
                                    كورسات المرحلة الدراسية
                                </h1>
                                <p class="mb-0 opacity-75">إدارة ومراقبة الكورسات المرتبطة بالمرحلة الدراسية</p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="{{ route('admin.courses.create') }}?grade_id={{ $grade->id }}" class="btn btn-secondary btn-course">
                                    <i class="fa fa-plus"></i>
                                    إضافة كورس جديد
                                </a>
                                <a href="{{ route('admin.grades.index') }}" class="btn btn-outline-light btn-course">
                                    <i class="fa fa-arrow-right"></i>
                                    العودة للمراحل
                                </a>
                            </div>
                        </div>

                        <!-- Grade Information -->
                        <div class="grade-info-banner">
                            <div class="grade-details">
                                <div class="grade-detail-item">
                                    <i class="fa fa-graduation-cap"></i>
                                    <span>{{ $grade->name_ar }}</span>
                                </div>
                                @if($grade->type)
                                    <div class="grade-detail-item">
                                        <i class="fa fa-tag"></i>
                                        <span>
                                            @if($grade->type == 'primary')
                                                المرحلة الابتدائية
                                            @elseif($grade->type == 'preparatory')
                                                المرحلة الإعدادية
                                            @elseif($grade->type == 'secondary')
                                                المرحلة الثانوية
                                            @elseif($grade->type == 'university')
                                                المرحلة الجامعية
                                            @elseif($grade->type == 'general')
                                                المرحلة العامة
                                            @endif
                                        </span>
                                    </div>
                                @endif
                                @if($grade->country)
                                    <div class="grade-detail-item">
                                        <i class="fa fa-globe"></i>
                                        <span>{{ $grade->country }}</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Statistics -->
                            <div class="courses-stats">
                                <div class="stat-card">
                                    <span class="stat-number">{{ $courses->count() }}</span>
                                    <span class="stat-label">إجمالي الكورسات</span>
                                </div>
                                <div class="stat-card">
                                    <span class="stat-number">{{ $courses->where('is_coming_soon', false)->count() }}</span>
                                    <span class="stat-label">منشورة</span>
                                </div>
                                <div class="stat-card">
                                    <span class="stat-number">{{ $courses->where('is_coming_soon', true)->count() }}</span>
                                    <span class="stat-label">قيد الإعداد</span>
                                </div>
                                <div class="stat-card">
                                    <span class="stat-number">{{ $courses->filter(fn ($course) => $course->classifications->isNotEmpty())->count() }}</span>
                                    <span class="stat-label">مصنفة</span>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Courses Content -->
                    <div class="courses-container">
                        @if($courses->count() > 0)
                            <!-- Filters -->
                            <div class="courses-filters">
                                <div class="filter-group">
                                    <input type="text" class="search-input" id="courseSearch" placeholder="البحث في الكورسات...">
                                    <select class="filter-select" id="statusFilter">
                                        <option value="">جميع الحالات</option>
                                        <option value="published">منشور</option>
                                        <option value="draft">قيد الإعداد</option>
                                    </select>
                                    <select class="filter-select" id="classificationFilter">
                                        <option value="">جميع التصنيفات</option>
                                        @foreach($classifications as $classification)
                                                <option value="{{ $classification->id }}">{{ $classification->name_ar }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <!-- Courses List -->
                            <div class="p-4" id="coursesContainer">
                                @foreach($courses as $course)
                                    <div class="course-card"
                                         data-course="{{ json_encode([
                                            'title' => $course->title,
                                            'description' => $course->description,
                                            'status' => $course->is_coming_soon ? 'draft' : 'published',
                                            'classifications' => $course->classifications->pluck('id')->map(fn ($id) => (string) $id)->values()
                                         ]) }}">

                                        <div class="course-header">
                                            <h3 class="course-title">{{ $course->title }}</h3>
                                            <span class="course-status-badge {{ $course->is_coming_soon ? 'status-closed' : 'status-opened' }}">
                                                {{ $course->is_coming_soon ? 'قيد الإعداد' : 'منشور' }}
                                            </span>
                                        </div>

                                        <div class="course-meta">
                                            @if($course->classifications->isNotEmpty())
                                                <div class="course-meta-item">
                                                    <i class="fa fa-folder"></i>
                                                    <span>{{ $course->classifications->pluck('name_ar')->filter()->join(' · ') }}</span>
                                                </div>
                                            @else
                                                <div class="course-meta-item">
                                                    <i class="fa fa-folder-o"></i>
                                                    <span class="text-muted">بدون قسم</span>
                                                </div>
                                            @endif

                                            @if($course->created_at)
                                                <div class="course-meta-item">
                                                    <i class="fa fa-calendar"></i>
                                                    <span>أُنشئ {{ \App\Support\BusinessClock::relative($course->created_at) }}</span>
                                                </div>
                                            @endif

                                            @if($canViewEnrollmentCounts)
                                                <div class="course-meta-item">
                                                    <i class="fa fa-users"></i>
                                                    <span>{{ number_format($course->active_enrollments_count) }} طالب</span>
                                                </div>
                                            @endif
                                        </div>

                                        @if($course->description)
                                            <div class="course-description">
                                                {{ Str::limit($course->description, 150) }}
                                            </div>
                                        @endif

                                        <div class="course-actions">
                                            <a href="{{ route('admin.courses.show', $course->id) }}"
                                               class="btn-course btn-primary-custom"
                                               title="عرض تفاصيل الكورس">
                                                <i class="fa fa-eye"></i>
                                                عرض
                                            </a>

                                            <a href="{{ route('admin.courses.show', $course->id) }}"
                                               class="btn-course btn-secondary-custom"
                                               title="تعديل الكورس">
                                                <i class="fa fa-edit"></i>
                                                تعديل
                                            </a>

                                        </div>
                                    </div>
                                @endforeach
                            </div>

                        @else
                            <!-- Empty State -->
                            <div class="empty-state">
                                <i class="fa fa-book"></i>
                                <h3>لا توجد كورسات مرتبطة</h3>
                                <p>لم يتم ربط أي كورسات بهذه المرحلة الدراسية حتى الآن</p>
                                <div class="mt-4">
                                    <a href="{{ route('admin.courses.create') }}?grade_id={{ $grade->id }}"
                                       class="btn btn-secondary btn-course">
                                        <i class="fa fa-plus"></i>
                                        إضافة كورس جديد
                                    </a>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const coursesContainer = document.getElementById('coursesContainer');
    const searchInput = document.getElementById('courseSearch');
    const statusFilter = document.getElementById('statusFilter');
    const classificationFilter = document.getElementById('classificationFilter');

    if (!coursesContainer) return;

    function filterCourses() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
        const statusValue = statusFilter ? statusFilter.value : '';
        const classificationValue = classificationFilter ? classificationFilter.value : '';

        const courseCards = coursesContainer.querySelectorAll('.course-card');
        let visibleCount = 0;

        courseCards.forEach(card => {
            const courseData = JSON.parse(card.dataset.course || '{}');

            // Search filter
            const matchesSearch = !searchTerm ||
                (courseData.title && courseData.title.toLowerCase().includes(searchTerm)) ||
                (courseData.description && courseData.description.toLowerCase().includes(searchTerm));

            // Status filter
            const matchesStatus = !statusValue || courseData.status === statusValue;

            // Category filter
            const classifications = Array.isArray(courseData.classifications)
                ? courseData.classifications.map(String)
                : [];
            const matchesClassification = !classificationValue ||
                classifications.includes(String(classificationValue));

            const shouldShow = matchesSearch && matchesStatus && matchesClassification;

            card.hidden = !shouldShow;
            if (shouldShow) visibleCount++;
        });

        // Show/hide no results message
        let noResultsMessage = coursesContainer.querySelector('.no-results');
        if (visibleCount === 0 && courseCards.length > 0) {
            if (!noResultsMessage) {
                noResultsMessage = document.createElement('div');
                noResultsMessage.className = 'no-results empty-state';
                noResultsMessage.innerHTML = `
                    <i class="fa fa-search"></i>
                    <h3>لا توجد نتائج</h3>
                    <p>لم نجد كورسات تطابق معايير البحث المحددة</p>
                `;
                coursesContainer.appendChild(noResultsMessage);
            }
            noResultsMessage.hidden = false;
        } else if (noResultsMessage) {
            noResultsMessage.hidden = true;
        }
    }

    // Add event listeners for filters
    if (searchInput) {
        searchInput.addEventListener('input', filterCourses);
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', filterCourses);
    }

    if (classificationFilter) {
        classificationFilter.addEventListener('change', filterCourses);
    }

    // Add loading states for action buttons
    document.querySelectorAll('.btn-course').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (this.href && this.href.includes('edit')) {
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="fa fa-spinner fa-spin ml-1"></i> جاري التحميل...';

                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 2000);
            }
        });
    });
});

// Add smooth scroll to top function
function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// Show scroll to top button when scrolling down
window.addEventListener('scroll', function() {
    let scrollButton = document.getElementById('scrollToTop');

    if (window.pageYOffset > 300) {
        if (!scrollButton) {
            scrollButton = document.createElement('button');
            scrollButton.type = 'button';
            scrollButton.id = 'scrollToTop';
            scrollButton.className = 'scroll-to-top-btn';
            scrollButton.setAttribute('aria-label', 'العودة إلى أعلى الصفحة');
            scrollButton.setAttribute('title', 'العودة إلى أعلى الصفحة');
            scrollButton.innerHTML = '<i class="fa fa-arrow-up"></i>';
            scrollButton.onclick = scrollToTop;
            document.body.appendChild(scrollButton);
        }
        scrollButton.classList.add('is-visible');
    } else if (scrollButton) {
        scrollButton.classList.remove('is-visible');
    }
});

// View students function
function viewStudents(courseId) {
    Swal.fire({
        title: 'طلاب الكورس',
        html: '<div class="text-center"><i class="fa fa-spinner fa-spin fa-2x"></i><br><br>جاري تحميل بيانات الطلاب...</div>',
        showConfirmButton: false,
        allowOutsideClick: false
    });

    // Simulate API call (replace with actual endpoint)
    setTimeout(() => {
        Swal.fire({
            title: 'طلاب الكورس',
            html: `
                <div class="students-list">
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle ml-1"></i>
                        هذه الميزة قيد التطوير. سيتم عرض قائمة الطلاب المسجلين في الكورس هنا.
                    </div>
                    <div class="text-center">
                        <i class="fa fa-users fa-3x text-muted mb-3"></i>
                        <p>يمكنك إدارة الطلاب من صفحة تفاصيل الكورس</p>
                        <button class="btn btn-primary" onclick="window.location.href='/admin/courses/${courseId}'">
                            <i class="fa fa-external-link ml-1"></i>
                            انتقال لصفحة الكورس
                        </button>
                    </div>
                </div>
            `,
            confirmButtonText: 'إغلاق',
            customClass: {
                popup: 'students-popup'
            }
        });
    }, 1500);
}

// Enhanced course card interactions
document.addEventListener('DOMContentLoaded', function() {
    // Add staggered animation to course cards
    document.querySelectorAll('.course-card').forEach((card, index) => {
        card.classList.add('course-card--entering');

        setTimeout(() => {
            card.classList.add('is-visible');
        }, index * 100);
    });

    // Add pulse animation to statistics
    document.querySelectorAll('.stat-number').forEach((stat, index) => {
        setTimeout(() => {
            stat.classList.add('stat-number--pulse');
            setTimeout(() => stat.classList.remove('stat-number--pulse'), 1000);
        }, index * 200);
    });
});
</script>
@endsection
