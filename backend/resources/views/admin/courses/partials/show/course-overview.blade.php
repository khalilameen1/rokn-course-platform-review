@php
    $teacher = $course->teachers->first();
    $courseTitle = trim((string) ($course->name_ar ?: $course->name_en)) ?: 'كورس بلا عنوان';
    $courseDescription = trim((string) ($course->description_ar ?: $course->description_en)) ?: 'أضف وصفًا مختصرًا يشرح للطالب ما الذي سيتعلمه ولماذا يبدأ هذا الكورس.';
    $teacherName = trim((string) ($teacher?->name_ar ?: $teacher?->name_en ?: $teacher?->getRawOriginal('name'))) ?: 'اختر محاضر الكورس';
    $teacherBio = trim((string) ($teacher?->bio_ar ?: $teacher?->bio_en ?: $teacher?->getRawOriginal('bio'))) ?: 'ستظهر نبذة المحاضر للطالب هنا.';
@endphp
<article class="student-course-card" data-studio-summary="course">
    <div class="student-course-card__cover">
        @if($course->image)<img src="{{ $course->image }}" alt="غلاف {{ $courseTitle }}">
        @else<div class="student-course-card__placeholder"><i class="fa fa-picture-o" aria-hidden="true"></i><span>أضف صورة غلاف للكورس</span></div>@endif
        @if($course->is_coming_soon)<button type="button" data-studio-course-open="image" class="studio-edit-chip studio-authoring-control"><i class="fa fa-pencil" aria-hidden="true"></i> تعديل الغلاف</button>@endif
        <span class="student-course-card__state">{{ $course->is_coming_soon ? 'قريبًا' : ($course->is_catalog_visible ? 'متاح الآن' : 'مخفي من الاكتشاف') }}</span>
    </div>
    <div class="student-course-card__content">
        @if($course->is_coming_soon)<button type="button" data-studio-course-open="details" class="studio-block-edit studio-authoring-control" aria-label="تعديل بيانات الكورس"><i class="fa fa-pencil" aria-hidden="true"></i></button>@endif
        <div class="student-course-card__badges">@foreach($course->classifications as $classification)<span>{{ $classification->name_ar }}</span>@endforeach @if($course->level)<span>{{ $course->level->name_ar }}</span>@endif</div>
        <h2 data-studio-course-title>{{ $courseTitle }}</h2>
        <p>{{ $courseDescription }}</p>
        <div class="student-course-card__facts">
            <span><i class="fa fa-play-circle" aria-hidden="true"></i> {{ number_format($sections->filter(fn ($section) => $section->getSectionType() === 'lesson')->count()) }} مقطع</span>
            <span><i class="fa fa-clock-o" aria-hidden="true"></i> {{ number_format((int) ($course->duration_minutes_computed ?? 0)) }} دقيقة</span>
            @if($activeStudentsCount !== null)<span><i class="fa fa-users" aria-hidden="true"></i> {{ number_format($activeStudentsCount) }} طالب</span>@endif
            <span><i class="fa fa-star" aria-hidden="true"></i> {{ $catalogRatingSummary['count'] > 0 ? number_format((float) $catalogRatingSummary['average'], 1).' · '.number_format($catalogRatingSummary['count']) : 'لا تقييمات' }}</span>
        </div>
        @if($previewPlans->isNotEmpty())
            <div class="student-course-card__plans" aria-label="فئات الكورس كما تظهر للطالب">
                @foreach($previewPlans as $plan)
                    <article class="student-course-plan">
                        <div><strong>{{ $plan['name'] }}</strong><span>{{ number_format($plan['price_coins']) }} عملة</span></div>
                        <ul>
                            <li>محتوى الكورس</li>
                            @if($plan['chat_enabled'])<li>شات ركن</li>@endif
                            @if($plan['project_report_enabled'])<li>تقرير المشروع</li>@endif
                            @if($plan['project_thread_reply_enabled'])<li>محادثة المشروع</li>@endif
                            @if($plan['certificate_enabled'])<li>الشهادة</li>@endif
                        </ul>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</article>

<section class="student-instructor-card" data-studio-summary="instructor">
    @if($course->is_coming_soon)<button type="button" data-studio-course-open="details" class="studio-block-edit studio-authoring-control" aria-label="تعديل المحاضر"><i class="fa fa-pencil" aria-hidden="true"></i></button>@endif
    <div class="student-instructor-card__avatar">@if($teacher?->profile_image_url)<img src="{{ $teacher->profile_image_url }}" alt="{{ $teacherName }}">@else<i class="fa fa-user" aria-hidden="true"></i>@endif</div>
    <div><span>المحاضر</span><h3>{{ $teacherName }}</h3><p>{{ $teacherBio }}</p></div>
</section>
