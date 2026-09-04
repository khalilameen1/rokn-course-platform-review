@extends('admin.layouts.app')

@section('page.title', 'اضافة طالب')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.users.partials._dynamic_styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/users-editor.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/users-form.css') }}">
@endsection

@section('content')

<div class="form-container animated fadeIn admin-page users-page">
    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10 col-md-12">
            <div class="form-card-modern">
                <div class="form-header-modern">
                    <i class="fa fa-user-plus"></i>
                    <h3>إضافة طالب جديد</h3>
                </div>
                <div class="form-body-modern">
                    {!! Form::open(['method' => 'POST','files' => true, 'route' => ['admin.users.store']]) !!}
                        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                        @include('admin.users._form')
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
