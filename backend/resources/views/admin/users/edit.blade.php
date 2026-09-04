@extends('admin.layouts.app')

@section('page.title', 'تعديل الطالب')

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
                    <i class="fa fa-edit"></i>
                    <h3>تعديل بيانات الطالب: {{ $user->name }}</h3>
                </div>
                <div class="form-body-modern">
                    {!! Form::model($user,['method' => 'PATCH', 'url' => route('admin.users.update', $user->id)]) !!}
                        <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                        @include('admin.users._form')
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
