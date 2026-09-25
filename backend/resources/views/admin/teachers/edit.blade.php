@extends('admin.layouts.app')

@section('page.title', 'تعديل بيانات معلم')

@section('content')
<div class="admin-page animated fadeIn">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <strong>تعديل بيانات المعلم: {{ $teacher->name }}</strong>
                </div>
                <div class="card-body card-block">
                    <form action="{{ route('admin.teachers.update', $teacher->id) }}" method="POST" enctype="multipart/form-data" class="form-horizontal" id="teacherForm">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                        @include('admin.teachers._form', ['isEdit' => true])
                        
                        <div class="form-actions form-group">
                            <button type="submit" class="btn btn-primary btn-sm">تعديل</button>
                            <a href="{{ route('admin.teachers.index') }}" class="btn btn-secondary btn-sm">إلغاء</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.partials.course-authoring-draft', ['formId' => 'teacherForm'])
@endsection
