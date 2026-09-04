@extends('admin.layouts.app')

@section('page.title', 'إضافة مهمة ربح عملات')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
<div class="card shadow-sm border-0 coin-panel admin-learning admin-learning--coins admin-page">
    <div class="card-header bg-white py-3"><h5 class="mb-0 font-weight-bold">إضافة مهمة ربح عملات</h5></div>
    <div class="card-body p-4">
        <form action="{{ route('admin.coin-earning-methods.store') }}" method="POST">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            @include('admin.coin_earning_methods._form')
            <div class="coin-form-actions">
                <button type="submit" class="btn btn-primary px-5 coin-form-action">حفظ</button>
                <a href="{{ route('admin.coin-earning-methods.index') }}" class="btn btn-light px-5 coin-form-action">رجوع</a>
            </div>
        </form>
    </div>
</div>
@endsection
