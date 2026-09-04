@extends('admin.layouts.app')

@section('page.title', 'تتبع تقدم الطلاب')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/student-progress-index.css') }}">
@endsection

@section('content')
<div class="animated fadeIn">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-3">
                <i class="fa fa-bar-chart-o"></i> تتبع تقدم الطلاب
            </h2>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" action="{{ route('admin.student-progress.index') }}">
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label for="search" class="form-label">البحث</label>
                    <input type="text"
                           name="search"
                           id="search"
                           class="form-control search-box"
                           placeholder="🔍 ابحث بالاسم أو رقم الجوال"
                           value="{{ request('search') }}">
                </div>

                <div class="col-md-2">
                    <label for="course_id" class="form-label">الدورة</label>
                    <select name="course_id" id="course_id" class="form-control">
                        <option value="">جميع الدورات</option>
                        @foreach($courses as $course)
                            <option value="{{ $course->id }}" {{ request('course_id') == $course->id ? 'selected' : '' }}>
                                {{ $course->name_ar ?? $course->name_en }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3.5">
                    <button type="submit" class="btn btn-primary-center btn-modern progress-filter-submit">
                        <i class="fa fa-search"></i> بحث
                    </button>
                    <a href="{{ route('admin.student-progress.index') }}" class="btn btn-cancel-modern btn-modern progress-filter-reset">
                        <i class="fa fa-redo"></i> إعادة تعيين
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Progress Cards -->
    <div class="row">
        @forelse($usersWithProgress as $userProgress)
            <div class="col-md-12">
                <div class="progress-card card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <!-- User Info -->
                            <div class="col-md-4">
                                <div class="d-flex align-items-center">
                                    <div class="user-avatar">
                                        {{ mb_substr($userProgress['user']->name, 0, 1) }}
                                    </div>
                                    <div class="ms-3 student-summary">
                                        <h5 class="mb-1 student-summary-name">{{ $userProgress['user']->name }}</h5>
                                        <small class="text-muted student-summary-meta">
                                            <i class="fa fa-phone"></i> {{ $userProgress['user']->phone ?? 'لا يوجد' }}
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- Course & Progress Info -->
                            <div class="col-md-5">
                                @if($userProgress['has_enrollment'])
                                    <div class="mb-2">
                                        <div class="course-title">
                                            📚 {{ $userProgress['course']->name_ar ?? $userProgress['course']->name_en }}
                                        </div>
                                        <small class="text-muted enrollment-date">
                                            تاريخ الالتحاق: {{ \App\Support\BusinessClock::format($userProgress['enrolled_at'], 'Y-m-d') }}
                                        </small>
                                    </div>

                                    <div class="progress-bar-modern mb-2">
                                        <div class="progress-bar-fill" data-progress="{{ $userProgress['progress']['progress_percentage'] }}">
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="progress-percentage">
                                            {{ $userProgress['progress']['progress_percentage'] }}%
                                        </span>
                                        <span class="text-muted progress-ratio">
                                            {{ $userProgress['progress']['completed_sections'] }} / {{ $userProgress['progress']['total_sections'] }} قسم
                                        </span>
                                    </div>

                                    <!-- Section Types Stats -->
                                    <div class="mt-3">
                                        @php
                                            $typeLabels = [
                                                'lesson' => 'مقاطع',
                                                'project' => 'مشروعات عبور',
                                            ];
                                            $badgeClasses = [
                                                'lesson' => 'badge-lesson',
                                                'project' => 'badge-project',
                                            ];
                                        @endphp
                                        @foreach($userProgress['progress']['sections_by_type'] as $type => $count)
                                            @if($count > 0)
                                                <span class="stats-badge {{ $badgeClasses[$type] ?? '' }}">
                                                    {{ $typeLabels[$type] ?? $type }}:
                                                    {{ $userProgress['progress']['completed_by_type'][$type] ?? 0 }} / {{ $count }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                    @if($userProgress['progress']['last_activity'])
                                        <div class="last-activity mt-2">
                                            آخر نشاط {{ \App\Support\BusinessClock::relative($userProgress['progress']['last_activity']) }}
                                        </div>
                                    @endif
                                @else
                                    <div class="no-enrollment-card">
                                        <i class="fa fa-info-circle no-enrollment-icon"></i>
                                        <p class="mb-0 mt-2">لم يتم التسجيل في أي دورة حتى الآن</p>
                                    </div>
                                @endif
                            </div>

                            <!-- Actions -->
                            <div class="col-md-3 text-end progress-actions">
                                <a href="{{ route('admin.student-progress.show', $userProgress['user']->id) }}"
                                   class="btn btn-secondary-center btn-modern mb-2 progress-action-button">
                                    <i class="fa fa-eye"></i> عرض التفاصيل
                                </a>
                                <br>
                                <a href="{{ route('admin.users.show', $userProgress['user']->id) }}"
                                   class="btn btn-cancel-modern btn-modern progress-action-button">
                                    <i class="fa fa-user"></i> ملف الطالب
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="empty-state">
                    <div class="empty-state-icon">📊</div>
                    <h4 class="empty-state-title">لا توجد بيانات</h4>
                    <p class="text-muted empty-state-description">لم يتم العثور على طلاب مسجلين</p>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if($users->hasPages())
        <div class="row mt-4">
            <div class="col-12">
                <div class="d-flex justify-content-center">
                    <nav class="pagination-modern">
                        {{ $users->links() }}
                    </nav>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function() {

    // Add animation to progress bars
    setTimeout(function() {
        $('.progress-bar-fill').each(function() {
            const progress = Math.max(0, Math.min(100, Number($(this).data('progress')) || 0));
            $(this).css('width', '0');
            $(this).animate({width: progress + '%'}, 1000, 'easeOutCubic');
        });
    }, 100);
});
</script>
@endsection
