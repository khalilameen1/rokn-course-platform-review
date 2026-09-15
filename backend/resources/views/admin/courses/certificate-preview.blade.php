@extends('admin.layouts.app')

@section('page.title', 'معاينة الشهادة')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-certificate-preview.css') }}">
@endsection

@section('content')
<main class="certificate-preview">
    <header class="certificate-preview__header">
        <div><h1>معاينة الشهادة</h1><p>{{ $courseName }}</p></div>
        <a href="{{ route('admin.courses.show', $previewCourse) }}">العودة للاستوديو</a>
    </header>
    <p class="certificate-preview__notice">معاينة فقط من آخر نسخة محفوظة وليست شهادة صادرة لمتعلم</p>
    <figure class="certificate-preview__artwork">
        <img src="{{ route('admin.courses.certificate-preview', [$previewCourse, 'image' => 1]) }}" alt="معاينة التصميم الحقيقي لشهادة كورس {{ $courseName }}">
        <figcaption>
            @if($hasPassageProjects)
                هكذا تظهر بعد إتمام الكورس واجتياز مشروعات العبور
            @else
                هكذا تظهر بعد إتمام الكورس
            @endif
        </figcaption>
    </figure>
    <p class="certificate-preview__footnote">رمز المعاينة تجريبي ولا يفتح شهادة حقيقية<br>في الشهادة الصادرة يفتح الأعمال المنشورة والمعتمدة إن وجدت وإلا يفتح التحقق من الشهادة</p>
</main>
@endsection
