        <div class="course-report-content">
            <div class="statistics-grid">
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->count() }}</span>
                    <span class="stat-label">إجمالي الأقسام</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->filter(fn ($section) => $section->isLesson())->count() }}</span>
                    <span class="stat-label">المقاطع</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->filter(fn ($section) => $section->isProject())->count() }}</span>
                    <span class="stat-label">مشروعات العبور</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ number_format($learningHealthSummary['enrolled_students']) }}</span>
                    <span class="stat-label">الطلاب النشطون فعليًا</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ number_format($learningHealthSummary['started_students']) }}</span>
                    <span class="stat-label">بدأوا التعلّم</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ number_format($learningHealthSummary['completed_students']) }}</span>
                    <span class="stat-label">أتموا الكورس</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ number_format($learningHealthSummary['not_started_students']) }}</span>
                    <span class="stat-label">لم يبدأوا بعد</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ number_format($learningHealthSummary['average_progress_percentage']) }}٪</span>
                    <span class="stat-label">متوسط التقدم الفعلي</span>
                </div>
                @if($ratingSummary)
                    <div class="stat-card">
                        <span class="stat-counter">{{ $ratingSummary['average'] !== null ? number_format($ratingSummary['average'], 1) : '—' }}</span>
                        <span class="stat-label">متوسط {{ number_format($ratingSummary['count']) }} تقييم فعلي</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-counter">{{ number_format($ratingSummary['removed_count']) }}</span>
                        <span class="stat-label">تقييمات حذفها أصحابها</span>
                    </div>
                @endif
            </div>
            @if($canViewCommercialReport)
                <div class="mt-4">
                    <a class="btn btn-primary-center btn-modern" href="{{ route('admin.student-progress.index', ['course_id' => $course->id]) }}">
                        <i class="fa fa-users" aria-hidden="true"></i>
                        تفاصيل تقدم الطلاب
                    </a>
                </div>
            @endif
        </div>
