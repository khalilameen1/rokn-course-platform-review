@extends('admin.layouts.app')

@section('page.title', 'تفاصيل تقدم الطالب')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/student-progress-show.css') }}">
@endsection

@section('content')
<div class="animated fadeIn">
    <!-- Header with User Info -->
    <div class="detail-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="user-avatar-large">
                        {{ mb_substr($user->name, 0, 1) }}
                    </div>
                    <div class="student-identity">
                        <h2 class="student-identity-name">{{ $user->name }}</h2>
                        <p class="student-identity-meta">
                            <i class="fa fa-envelope"></i> {{ $user->email ?? 'لا يوجد' }}
                        </p>
                        <p class="student-identity-meta">
                            <i class="fa fa-phone"></i> {{ $user->phone ?? 'لا يوجد' }}
                        </p>

                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end student-detail-actions">
                <a href="{{ route('admin.student-progress.index') }}" class="btn-action btn-back-action">
                    <i class="fa fa-arrow-right"></i> رجوع
                </a>
            </div>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="stats-grid mb-4">
        <div class="stat-box">
            <div class="stat-value">{{ $totalEnrollments }}</div>
            <div class="stat-label">إجمالي الدورات المسجلة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">
                @php
                    $totalCompleted = 0;
                    $totalSections = 0;
                    foreach($coursesProgress as $cp) {
                        $totalCompleted += $cp['progress']['completed_sections'];
                        $totalSections += $cp['progress']['total_sections'];
                    }
                    $avgProgress = $totalSections > 0 ? round(($totalCompleted / $totalSections) * 100) : 0;
                @endphp
                {{ $avgProgress }}%
            </div>
            <div class="stat-label">متوسط التقدم</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">{{ $totalCompleted }}</div>
            <div class="stat-label">الأقسام المكتملة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">{{ $totalSections }}</div>
            <div class="stat-label">إجمالي الأقسام</div>
        </div>
    </div>

    @if($totalEnrollments > 0)
        <div class="info-box">
            <i class="fa fa-info-circle"></i>
            <div>
                <strong>معلومة:</strong> يتم عرض جميع الدورات المسجل فيها الطالب مع تفاصيل التقدم لكل دورة
            </div>
        </div>
    @endif

    <!-- Courses Progress -->
    @forelse($coursesProgress as $courseProgress)
        <div class="course-detail-card">
            <div class="course-header">
                <div>
                    <h3 class="course-title-large">
                        📚 {{ $courseProgress['course']->name_ar ?? $courseProgress['course']->name_en }}
                    </h3>
                    <div class="enrollment-date">
                        <i class="fa fa-calendar"></i>
                        تاريخ الالتحاق: {{ \App\Support\BusinessClock::format($courseProgress['enrollment']->enrolled_at, 'Y-m-d') }}
                    </div>
                    @if($courseProgress['progress']['last_activity'])
                        <div class="last-activity-badge">
                            <i class="fa fa-clock"></i>
                            آخر نشاط {{ \App\Support\BusinessClock::relative($courseProgress['progress']['last_activity']) }}
                        </div>
                    @endif
                </div>
                <div class="progress-circle">
                    {{ $courseProgress['progress']['progress_percentage'] }}%
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="progress-bar-modern">
                <div class="progress-bar-fill" data-progress="{{ $courseProgress['progress']['progress_percentage'] }}">
                </div>
            </div>

            <!-- Stats by Type -->
            <div class="type-breakdown">
                @php
                    $typeLabels = [
                        'lesson' => 'مقاطع',
                        'project' => 'مشروعات عبور',
                    ];
                    $typeBadgeClasses = [
                        'lesson' => 'type-badge-lesson',
                        'project' => 'type-badge-project',
                    ];
                @endphp
                @foreach($courseProgress['progress']['sections_by_type'] as $type => $count)
                    @if($count > 0)
                        <span class="type-badge {{ $typeBadgeClasses[$type] ?? '' }}">
                            {{ $typeLabels[$type] ?? $type }}:
                            {{ $courseProgress['progress']['completed_by_type'][$type] ?? 0 }} / {{ $count }}
                        </span>
                    @endif
                @endforeach
            </div>

            <!-- Sections Detail -->
            <div class="sections-timeline mt-4">
                <h5 class="sections-title">
                    تفاصيل الأقسام ({{ count($courseProgress['sections_detail']) }})
                </h5>
                @foreach($courseProgress['sections_detail'] as $section)
                    <div class="section-item {{ $section['is_completed'] ? 'completed' : 'incomplete' }}">
                        @php
                            $iconClasses = [
                                'lesson' => 'icon-lesson',
                                'project' => 'icon-project',
                            ];
                            $icons = [
                                'lesson' => '▶',
                                'project' => '◆',
                            ];
                        @endphp
                        <div class="section-icon {{ $iconClasses[$section['type']] ?? 'icon-lesson' }}">
                            {{ $icons[$section['type']] ?? '📄' }}
                        </div>
                        <div class="section-content">
                            <div class="section-title">
                                {{ $section['order'] }}. {{ $section['title'] }}
                            </div>
                            <div class="section-meta">
                                <span>النوع: {{ $typeLabels[$section['type']] ?? $section['type'] }}</span>
                                @if($section['completed_at'])
                                    <span class="me-3 section-completed-at">
                                        اكتمل في: {{ \App\Support\BusinessClock::format($section['completed_at']) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <span class="completion-badge {{ $section['is_completed'] ? 'badge-completed' : 'badge-incomplete' }}">
                            @if($section['is_completed'])
                                ✓ مكتمل
                            @else
                                ⏳ قيد التقدم
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="no-courses">
            <div class="no-courses-icon">📚</div>
            <h4 class="no-courses-title">لا توجد دورات مسجلة</h4>
            <p class="text-muted">لم يتم التسجيل في أي دورة حتى الآن</p>
        </div>
    @endforelse
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function() {
    // Animate progress bars
    setTimeout(function() {
        $('.progress-bar-fill').each(function() {
            const progress = Math.max(0, Math.min(100, Number($(this).data('progress')) || 0));
            $(this).css('width', '0');
            $(this).animate({width: progress + '%'}, 1000, 'easeOutCubic');
        });
    }, 100);

    // Add smooth scroll animation to sections
    $('.section-item').each(function(index) {
        $(this).css('opacity', '0');
        $(this).delay(100 * index).animate({opacity: 1}, 500);
    });
});
</script>
@endsection
