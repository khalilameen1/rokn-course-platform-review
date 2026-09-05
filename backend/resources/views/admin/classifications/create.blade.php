@extends('admin.layouts.app')

@section('page.title', 'إضافة صف للرئيسية')

@section('content')
<div class="admin-page card">
    <div class="card-header">
        <strong>إضافة صف للرئيسية</strong>
    </div>
    <div class="card-body card-block">
        <form action="{{ route('admin.classifications.store') }}" method="post" class="form-horizontal" id="classificationForm">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_ar" class=" form-control-label">عنوان الصف بالعربية</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_ar" name="name_ar" placeholder="أدخل الاسم بالعربية" class="form-control" value="{{ old('name_ar') }}" required>
                </div>
            </div>
            @include('admin.classifications._course-selection', ['selectedCourseIds' => []])
            <div class="row form-group">
                <div class="col col-md-3"><label for="home_order" class="form-control-label">ترتيب الصف في الرئيسية</label></div>
                <div class="col-12 col-md-9">
                    <input type="number" id="home_order" name="home_order" min="0" max="10000" class="form-control" value="{{ old('home_order', 100) }}" required>
                    <small class="text-muted">الرقم الأصغر يظهر أولًا.</small>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3">الظهور في الرئيسية</div>
                <div class="col-12 col-md-9">
                    <input type="hidden" name="show_on_home" value="0">
                    <label><input type="checkbox" name="show_on_home" value="1" {{ old('show_on_home', true) ? 'checked' : '' }}> اعرض الصف في الرئيسية</label>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_en" class=" form-control-label">عنوان الصف بالإنجليزية</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_en" name="name_en" placeholder="Enter name in English" class="form-control" value="{{ old('name_en') }}" required>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-dot-circle-o"></i> حفظ
                </button>
                <button type="reset" class="btn btn-danger btn-sm">
                    <i class="fa fa-ban"></i> إعادة تعيين
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
    @include('admin.partials.course-authoring-draft', ['formId' => 'classificationForm'])
@endsection
