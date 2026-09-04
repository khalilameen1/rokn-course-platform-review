@extends('admin.layouts.app')
@section('page.title', 'عن ركن')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
<div class="admin-learning admin-learning--legal admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'عن ركن',
        'pageDescription' => 'هذا النص يظهر في التطبيق والموقع',
        'pageIcon' => 'fa-info-circle',
    ])
    <div class="mb-3">
        <a class="btn btn-light" href="{{ route('admin.about') }}">عن ركن</a>
        <a class="btn btn-light" href="{{ route('admin.privacy') }}">سياسة الخصوصية</a>
        <a class="btn btn-light" href="{{ route('admin.policy') }}">شروط الاستخدام</a>
    </div>
    {!! Form::model($about, ['method' => 'PATCH', 'url' => route('admin.abouts.update', $about->id)]) !!}
    <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
    <div class="card admin-card legal-editor__card">
        <div class="card-header"><strong>النص العربي</strong></div>
        <div class="card-body"><textarea name="about_ar" class="form-control legal-editor__textarea" rows="14">{{ $about->about_ar }}</textarea></div>
    </div>
    <div class="card admin-card legal-editor__card">
        <div class="card-header"><strong>English copy</strong></div>
        <div class="card-body"><textarea name="about_en" class="form-control legal-editor__textarea" dir="ltr" rows="14">{{ $about->about_en }}</textarea></div>
    </div>
    <button type="submit" class="btn btn-primary">حفظ ونشر</button>
    {!! Form::close() !!}
</div>
@endsection
