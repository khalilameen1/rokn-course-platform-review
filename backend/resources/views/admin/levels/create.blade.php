@extends('admin.layouts.app')

@section('page.title', 'إضافة مستوى جديد')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/levels-dashboard.css') }}">
@endsection

@section('content')
<div class="admin-page levels-page card">
    <div class="card-header">
        <strong>إضافة مستوى جديد</strong>
    </div>
    <div class="card-body card-block">
        <form action="{{ route('admin.levels.store') }}" method="post" class="form-horizontal" enctype="multipart/form-data" id="levelForm">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_ar" class=" form-control-label">الاسم (AR)</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_ar" name="name_ar" placeholder="أدخل الاسم بالعربية" class="form-control" value="{{ old('name_ar') }}" required>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_en" class=" form-control-label">الاسم (EN)</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_en" name="name_en" placeholder="Junior, Mid-level or Senior" class="form-control" value="{{ old('name_en') }}" required>
                    <small class="form-text text-muted">هذا الاسم يظهر للطالب بالإنجليزية. يمكن إنشاء أكثر من شارة وربط كل كورس بالمستوى المناسب.</small>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="badge_image" class=" form-control-label">الشعار / الوسام</label></div>
                <div class="col-12 col-md-9">
                    <input type="file" id="badge_image" name="badge_image" class="form-control">
                    <small class="form-text text-muted">PNG بخلفية شفافة، مربع، ومن دون كتابة داخل الصورة. الاسم يظهر بجوارها داخل التطبيق.</small>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="description_ar" class=" form-control-label">الوصف (AR)</label></div>
                <div class="col-12 col-md-9">
                    <textarea name="description_ar" id="description_ar" rows="4" placeholder="الوصف بالعربية..." class="form-control">{{ old('description_ar') }}</textarea>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="description_en" class=" form-control-label">الوصف (EN)</label></div>
                <div class="col-12 col-md-9">
                    <textarea name="description_en" id="description_en" rows="4" placeholder="Description in English..." class="form-control">{{ old('description_en') }}</textarea>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="order" class=" form-control-label">الترتيب</label></div>
                <div class="col-12 col-md-9">
                    <input type="number" id="order" name="order" placeholder="0" class="form-control" value="{{ old('order', 0) }}">
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
    @include('admin.partials.course-authoring-draft', ['formId' => 'levelForm'])
@endsection
