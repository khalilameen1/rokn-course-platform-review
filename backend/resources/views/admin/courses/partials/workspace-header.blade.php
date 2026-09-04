@php
    $workspaceTitle = trim((string) ($course->name_ar ?: $course->name_en)) ?: 'كورس بلا عنوان';
@endphp

<header class="course-workspace" aria-label="مساحة صناعة الكورس">
    <div class="course-workspace__context">
        <a href="{{ route('admin.courses.index') }}" class="course-workspace__back" aria-label="العودة إلى الكورسات">
            <i class="fa fa-arrow-right" aria-hidden="true"></i>
        </a>
        <div>
            <span>صناعة الكورس</span>
            <h1 data-studio-course-title>{{ $workspaceTitle }}</h1>
        </div>
        <small class="course-workspace__state {{ $course->is_coming_soon ? 'is-draft' : 'is-live' }}">
            {{ $course->is_coming_soon ? 'مسودة' : 'منشور' }}
        </small>
    </div>

    <nav class="course-workspace__nav" aria-label="أجزاء الكورس">
        <a href="{{ route('admin.courses.show', $course) }}" class="is-active">بناء الكورس</a>
        @if($course->is_coming_soon)
            <button type="button" data-studio-course-open="details">البيانات والفئات</button>
            <button type="button" data-studio-attachments-open>المرفقات</button>
        @endif
        <a href="{{ route('admin.courses.student-preview', $course) }}" target="_blank" rel="noopener">معاينة الطالب</a>
    </nav>
</header>
