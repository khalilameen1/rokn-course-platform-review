<nav class="student-operations" aria-label="متابعة الطالب">
    <a href="#studentLearning">
        @if(($learning['has_enrollment'] ?? false) && is_array($learning['progress'] ?? null))
            <strong>{{ number_format($learning['progress']['progress_percentage']) }}% في الكورس الحالي</strong>
            <span>{{ $learning['course']->title ?? 'التعلم الحالي' }}</span>
        @else
            <strong>لا يوجد تعلم نشط</strong>
            <span>الكورسات والتقدم</span>
        @endif
    </a>
    <a href="#studentPurchases">
        <strong>{{ number_format($orderStats['approved']) }} مشتريات معتمدة</strong>
        <span>الفئات وما دفعه الطالب</span>
    </a>
    <a href="#studentProjects" @class(['needs-attention' => (int) $projectStatusCounts->get(\App\Models\ProjectSubmission::STATUS_PENDING, 0) > 0])>
        <strong>
            @if((int) $projectStatusCounts->get(\App\Models\ProjectSubmission::STATUS_PENDING, 0) > 0)
                {{ number_format((int) $projectStatusCounts->get(\App\Models\ProjectSubmission::STATUS_PENDING)) }} مشاريع قيد المراجعة
            @else
                {{ number_format($projectSubmissions->total()) }} محاولات
            @endif
        </strong>
        <span>المشاريع والنتائج</span>
    </a>
</nav>
