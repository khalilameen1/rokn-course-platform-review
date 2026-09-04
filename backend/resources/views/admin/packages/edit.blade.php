@extends('admin.layouts.app')

@section('page.title', 'تعديل باقة العملات')

@section('content')
@include('admin.payments.partials.navigation')
<div class="admin-page card">
    <div class="card-header"><strong>تعديل {{ $package->name_ar }}</strong></div>
    <div class="card-body card-block">
        <form action="{{ route('admin.packages.update', $package) }}" method="post" class="form-horizontal">
            @csrf
            @method('PUT')
            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
            @include('admin.packages._form', ['package' => $package])
            <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-save"></i> حفظ</button>
                <a href="{{ route('admin.packages.index') }}" class="btn btn-secondary btn-sm">رجوع</a>
            </div>
        </form>
    </div>
</div>
@endsection
