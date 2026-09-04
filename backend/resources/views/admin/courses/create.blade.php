@extends('admin.layouts.app')

@section('page.title', 'كورس جديد')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-studio.css') }}">
@endsection

@section('content')
@php
    $defaultCertificateTemplate = (string) config('certificate.default_text_template_key', 'completion');
@endphp
<main class="admin-page course-shell-create" aria-labelledby="courseShellTitle">
    <a href="{{ route('admin.courses.index') }}" class="course-shell-create__back">
        <i class="fa fa-arrow-right" aria-hidden="true"></i>
        <span>الكورسات</span>
    </a>

    <section class="course-shell-create__card">
        <header>
            <span>كورس جديد</span>
            <h1 id="courseShellTitle">ابدأ باسم الكورس</h1>
            <p>سننشئ مسودة ثم تكمل كل شيء داخل الاستوديو</p>
        </header>

        {!! Form::open(['method' => 'POST', 'route' => ['admin.courses.store'], 'id' => 'courseForm']) !!}
            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) Str::uuid()) }}">
            <input type="hidden" name="certificate_text_template_key" value="{{ old('certificate_text_template_key', $defaultCertificateTemplate) }}">

            <div class="course-shell-create__field">
                <label for="name_ar">اسم الكورس</label>
                {!! Form::text('name_ar', old('name_ar'), [
                    'id' => 'name_ar',
                    'class' => 'form-control'.($errors->has('name_ar') ? ' is-invalid' : ''),
                    'required' => true,
                    'maxlength' => 255,
                    'autocomplete' => 'off',
                    'autofocus' => true,
                    'placeholder' => 'مثال  أساسيات التصميم البصري',
                ]) !!}
                @error('name_ar')<small class="course-shell-create__error">{{ $message }}</small>@enderror
                @error('certificate_text_template_key')<small class="course-shell-create__error">تعذر إنشاء المسودة الآن</small>@enderror
            </div>

            <div class="course-shell-create__actions">
                <button type="submit">
                    <span>إنشاء المسودة والمتابعة</span>
                    <i class="fa fa-arrow-left" aria-hidden="true"></i>
                </button>
            </div>
        {!! Form::close() !!}
    </section>
</main>
@endsection

@section('scripts')
@include('admin.partials.course-authoring-draft', ['formId' => 'courseForm'])
@endsection
