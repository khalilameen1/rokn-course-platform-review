@extends('admin.layouts.app')
@section('page.title', 'شروط الأستخدام')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="admin-learning admin-learning--legal admin-page">
        @include('admin.partials.page-header', [
            'pageTitle' => 'شروط الاستخدام',
            'pageDescription' => 'حرّر النص المنشور بالعربية والإنجليزية.',
            'pageIcon' => 'fa-file-text-o',
        ])
    <div class="mb-3"><a class="btn btn-light" href="{{ route('admin.about') }}">عن ركن</a> <a class="btn btn-light" href="{{ route('admin.privacy') }}">سياسة الخصوصية</a> <a class="btn btn-light" href="{{ route('admin.policy') }}">شروط الاستخدام</a></div>
    <div class="row">
        <div class="col-md-12">
            {!! Form::model($about,['method' => 'PATCH', 'files' => true, 'url' => route('admin.abouts.update', $about->id)]) !!}
            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                <div class="card admin-card legal-editor__card">
                    <div class="card-header">
                        <i class="fa fa-file-text-o"></i><strong class="card-title pr-2"> شروط الأستخدام بالعربية</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="form-group">
                                    <textarea name="policy_ar" class="form-control legal-editor__textarea" placeholder="شروط الأستخدام ..." maxlength="" tabindex="4">
                                        {{ $about->policy_ar }}
                                    </textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card admin-card legal-editor__card">
                <div class="card-header">
                    <i class="fa fa-file-text-o"></i><strong class="card-title pr-2"> شروط الأستخدام بالأنجليزية</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-sm-12">
                            <div class="form-group">
                                <textarea name="policy_en" class="form-control legal-editor__textarea" placeholder="شروط الأستخدام ..." maxlength="" tabindex="4">
                                    {{ $about->policy_en }}
                                </textarea>
                            </div>
                        </div>
                        <div class="col-sm-2">
                            <div class="form-group">
                                <button id="payment-button" type="submit" class="btn btn-primary btn-block">حفظ</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {!! Form::close() !!}
        </div>
    </div>
    </div>
@endsection
