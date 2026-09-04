@extends('admin.layouts.app')

@section('page.title', 'إضافة مرحلة دراسية جديدة')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.grades.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/grades-create.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/grades-form.css') }}">

@endsection

@section('content')
    <div class="container-fluid grades-module admin-page">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10 col-xl-8">
                <div class="card border-0 shadow-lg slide-up">
                    <!-- Enhanced Header -->
                    <div class="create-header">
                        <div class="page-header-content">
                            <div>
                                <div class="header-title">
                                    <i class="fa fa-plus-circle"></i>
                                    <h1>إضافة مرحلة دراسية جديدة</h1>
                                </div>
                                <p class="header-description">
                                    قم بإضافة مرحلة دراسية جديدة لتنظيم الكورسات والمحتوى التعليمي
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Form Container -->
                    <div class="form-container">
                        <!-- Success Message (if any) -->
                        @if(session('success'))
                            <div class="success-message">
                                <i class="fa fa-check-circle"></i>
                                <span>{{ session('success') }}</span>
                            </div>
                        @endif

                        <!-- Form -->
                        <form action="{{ route('admin.grades.store') }}" method="post" id="gradeForm">
                            @csrf
                            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                            @include('admin.grades._form')
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    @include('admin.partials.course-authoring-draft', ['formId' => 'gradeForm'])
@endsection
