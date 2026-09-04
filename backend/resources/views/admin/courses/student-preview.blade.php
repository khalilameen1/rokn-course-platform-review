@extends('admin.layouts.app')

@section('page.title', 'معاينة الطالب')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-student-preview.css') }}">
@endsection

@section('content')
@php
    $modules = collect($previewPayload['modules'] ?? []);
    $allSections = collect($previewPayload['sections'] ?? []);
    $metadata = is_array($previewPayload['metadata'] ?? null) ? $previewPayload['metadata'] : [];
    $attachmentCount = $modules->flatMap(function ($module) {
        $moduleAttachments = collect($module['attachments'] ?? [])
            ->map(fn ($attachment) => 'file:'.(string) ($attachment['id'] ?? ''));
        $sectionAttachments = collect($module['sections'] ?? [])->flatMap(
            fn ($section) => collect($section['attachments'] ?? [])
                ->map(fn ($attachment) => 'file:'.(string) ($attachment['id'] ?? ''))
        );
        return $moduleAttachments->concat($sectionAttachments);
    })->filter(fn ($key) => $key !== 'file:')->unique()->count();
    $hasPublishedDeviceVersion = $publishedDeviceCourseId !== null;
    $typeLabels = ['lesson' => 'مقطع', 'project' => 'مشروع عبور'];
    $typeIcons = ['lesson' => 'fa-play', 'project' => 'fa-briefcase'];
    $renderSection = function (array $section) use ($typeLabels, $typeIcons) {
        $type = (string) ($section['type'] ?? 'lesson');
        $content = is_array($section['content'] ?? null) ? $section['content'] : [];
        $locked = (bool) ($section['is_locked'] ?? true);
        $preview = (bool) ($section['is_preview'] ?? false);
        $duration = max(0, (int) ($content['duration_minutes'] ?? 0));
        return compact('type', 'content', 'locked', 'preview', 'duration', 'section', 'typeLabels', 'typeIcons');
    };
@endphp
<main class="learner-preview">
    <header class="learner-preview__bar">
        <div><h1>معاينة الطالب</h1><p>طالب جديد · فئة {{ $selectedPlan['name'] }}</p></div>
        <div class="learner-preview__bar-actions">
            <a class="learner-preview__back" href="{{ route('admin.courses.show', $previewCourse) }}"><i class="fa fa-arrow-right"></i> الاستوديو</a>
            <a class="learner-preview__device {{ $hasPublishedDeviceVersion ? '' : 'is-disabled' }}" href="{{ $hasPublishedDeviceVersion ? 'rokn://course/'.$publishedDeviceCourseId : '#' }}"><i class="fa fa-mobile"></i> فتح النسخة المنشورة</a>
        </div>
    </header>

    <div class="learner-preview__notice {{ $isWorkingDraftPreview ? 'is-draft' : '' }}">
        <i class="fa {{ $isWorkingDraftPreview ? 'fa-eye-slash' : 'fa-check-circle' }}"></i>
        <span>
            @if($isWorkingDraftPreview && $hasPublishedDeviceVersion)
                هذه معاينة للمسودة فقط · رابط الجهاز يفتح النسخة المنشورة الحالية
            @elseif($isWorkingDraftPreview)
                هذه معاينة للمسودة فقط · لن يصل إليها الطالب قبل النشر
            @else
                هذه هي النسخة المنشورة الحالية
            @endif
        </span>
    </div>

    <nav class="learner-preview__plans" aria-label="اختر فئة الطالب">
        @foreach($planOptions as $plan)
            <a class="learner-preview__plan {{ $plan['code'] === $selectedPlan['code'] ? 'is-active' : '' }}" href="{{ route('admin.courses.student-preview', [$previewCourse, 'plan' => $plan['code']]) }}">
                <strong>{{ $plan['name'] }}</strong>
                <span>{{ ($plan['code'] ?? '') === 'grant' ? 'إتاحة المنحة' : number_format((int) $plan['price_coins']).' عملة' }}</span>
            </a>
        @endforeach
    </nav>

    <div class="learner-preview__shell">
        <section class="learner-preview__phone" aria-label="تجربة الطالب داخل الكورس">
            <div class="learner-preview__cover">
                @if(!empty($previewPayload['image']))<img src="{{ $previewPayload['image'] }}" alt="غلاف {{ $previewPayload['title'] }}">
                @else<div class="learner-preview__cover-placeholder"><i class="fa fa-picture-o"></i></div>@endif
            </div>
            <div class="learner-preview__course">
                <h2>{{ $previewPayload['title'] }}</h2>
                @if(!empty($previewPayload['description']))<p>{{ $previewPayload['description'] }}</p>@endif
                <div class="learner-preview__facts">
                    @if(isset($metadata['duration_minutes']))<span class="learner-preview__chip"><i class="fa fa-clock-o"></i> {{ number_format((int) $metadata['duration_minutes']) }} دقيقة</span>@endif
                    @if(isset($metadata['students_count']))<span class="learner-preview__chip"><i class="fa fa-users"></i> {{ number_format((int) $metadata['students_count']) }} طالب</span>@endif
                    @if(!empty($previewPayload['average_rating']))<span class="learner-preview__chip"><i class="fa fa-star"></i> {{ number_format((float) $previewPayload['average_rating'], 1) }}</span>@endif
                    <span class="learner-preview__chip"><i class="fa fa-list"></i> {{ number_format($allSections->count()) }} عنصر</span>
                </div>

                <div class="learner-preview__outline">
                    <h2>خريطة الكورس</h2>
                    @forelse($modules as $module)
                        <article class="learner-preview__module">
                            <header class="learner-preview__module-head">
                                <h3>{{ $module['title'] }}</h3>
                                <span>{{ number_format(count($module['sections'] ?? [])) }} عناصر · {{ number_format((int) ($module['attachments_count'] ?? 0)) }} مرفقات</span>
                            </header>
                            @foreach(($module['sections'] ?? []) as $section)
                                @php(extract($renderSection($section)))
                                <div class="learner-preview__step {{ $locked ? 'is-locked' : '' }}">
                                    <div class="learner-preview__step-icon"><i class="fa {{ $locked ? 'fa-lock' : ($typeIcons[$type] ?? 'fa-circle-o') }}"></i></div>
                                    <div>
                                        <h4>{{ $section['title'] }}</h4>
                                        <p>{{ $typeLabels[$type] ?? $type }}@if($duration) · {{ $duration }} دقيقة@endif @if($preview) · متاح مجانًا@endif</p>
                                        @if($type === 'project' && !$locked)
                                            <div class="learner-preview__project">
                                                @if(!empty($content['requirements_text']))<p>{{ $content['requirements_text'] }}</p>@endif
                                                @php($feedback = $content['project_feedback'] ?? [])
                                                <p>{{ !empty($feedback['report_enabled']) ? 'يتلقى الطالب تقريرًا' : 'تقييم عبور' }}{{ !empty($feedback['reply_enabled']) ? ' · ويمكنه متابعة التقرير داخل الشات' : '' }}</p>
                                            </div>
                                        @endif
                                        @if(!$locked && !empty($section['attachments']))<p><i class="fa fa-paperclip"></i> {{ number_format(count($section['attachments'])) }} مرفقات قابلة للتحميل</p>@endif
                                    </div>
                                    <span class="learner-preview__step-state">{{ $locked ? 'مغلق' : 'متاح' }}</span>
                                </div>
                            @endforeach
                        </article>
                    @empty
                        <div class="learner-preview__empty">لا يوجد محتوى بعد</div>
                    @endforelse
                </div>
            </div>
        </section>

        <aside class="learner-preview__side">
            <section class="learner-preview__side-section">
                <h3>ما يحصل عليه الطالب</h3>
                <div class="learner-preview__features">
                    <span class="learner-preview__chip {{ !empty($previewPayload['chat_available']) ? '' : 'is-off' }}"><i class="fa fa-comments"></i> شات ركن</span>
                    <span class="learner-preview__chip {{ !empty($previewPayload['certificate_included']) ? '' : 'is-off' }}"><i class="fa fa-certificate"></i> الشهادة</span>
                    <span class="learner-preview__chip {{ !empty($selectedPlan['project_report_enabled']) ? '' : 'is-off' }}"><i class="fa fa-file-text-o"></i> تقرير المشروع</span>
                    <span class="learner-preview__chip {{ !empty($selectedPlan['project_thread_reply_enabled']) ? '' : 'is-off' }}"><i class="fa fa-reply"></i> متابعة التقرير</span>
                    <span class="learner-preview__chip {{ !empty($previewPayload['chat_attachments_enabled']) ? '' : 'is-off' }}"><i class="fa fa-paperclip"></i> ملفات الشات</span>
                </div>
            </section>
            <section class="learner-preview__side-section">
                <h3>مرفقات الكورس</h3>
                <p>{{ $attachmentCount ? number_format($attachmentCount).' ملفات تظهر عند فتح وحدتها' : 'لا توجد مرفقات في هذه النسخة' }}</p>
                @if(!empty($previewPayload['attachment_prompt']['enabled']))
                    <div class="learner-preview__prompt">
                        <strong>{{ $previewPayload['attachment_prompt']['title'] }}</strong>
                        <span>{{ $previewPayload['attachment_prompt']['body'] }}</span>
                        <span>تظهر بعد {{ number_format((int) $previewPayload['attachment_prompt']['at_seconds']) }} ثانية</span>
                        <span>{{ config('course_attachments.prompt.frequencies.'.$previewPayload['attachment_prompt']['frequency']) }}</span>
                    </div>
                @endif
            </section>
            <section class="learner-preview__side-section">
                <h3>حالة الشهادة</h3>
                <p>{{ !empty($previewPayload['certificate_included']) ? 'ضمن هذه الفئة · تصدر بعد إتمام المطلوب' : 'ليست ضمن هذه الفئة' }}</p>
                @if(!empty($previewPayload['certificate_included']))
                    <p class="learner-preview__certificate-text">
                        {{ $certificateTextTemplate['text'] }}
                        <strong>{{ $previewPayload['title'] }}</strong>
                    </p>
                @endif
            </section>
        </aside>
    </div>
</main>
@endsection
