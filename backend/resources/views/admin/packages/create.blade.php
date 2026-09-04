@extends('admin.layouts.app')

@section('page.title', 'إضافة باقة عملات')

@section('content')
@include('admin.payments.partials.navigation')
<div class="admin-page card">
    <div class="card-header"><strong>إضافة باقة عملات</strong></div>
    <div class="card-body card-block">
        <form action="{{ route('admin.packages.store') }}" method="post" class="form-horizontal" id="packageForm">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            @include('admin.packages._form')
            <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-save"></i> حفظ</button>
                <a href="{{ route('admin.packages.index') }}" class="btn btn-secondary btn-sm">رجوع</a>
            </div>
        </form>
    </div>
</div>
@endsection
