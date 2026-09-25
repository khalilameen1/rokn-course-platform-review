@extends('admin.layouts.app')

@section('page.title', 'تعديل الكود')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-codes.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-codes-edit.css') }}">
@endsection

@section('content')
<div class="admin-page content course-codes-page">
    <div class="animated fadeIn">
        <!-- Page Header -->
        <div class="page-header modern-header">
            <h1><i class="fa fa-edit"></i> تعديل الكود: {{ $courseCode->code }}</h1>
        </div>

        <div class="modern-card">
            <div class="modern-card-header">
                <h4><i class="fa fa-pencil"></i> تحديث معلومات الكود</h4>
            </div>
            <div class="modern-card-body">
                <form method="POST" action="{{ route('admin.course-codes.update', $courseCode) }}" id="edit-code-form">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="name">اسم الكود (اختياري)</label>
                                        <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $courseCode->name) }}" placeholder="مثال: كود خاص للطلاب المتفوقين">
                                        @error('name')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="max_uses">عدد مرات الاستخدام</label>
                                        <input type="number" name="max_uses" id="max_uses" class="form-control" value="{{ old('max_uses', $courseCode->max_uses) }}" min="1" max="10000">
                                        <small class="text-muted">الاستخدامات الحالية: <strong>{{ $courseCode->used_count }}</strong></small>
                                        @error('max_uses')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="type">نوع الكود</label>
                                        <select name="type" id="type" class="form-control" required>
                                            <option value="">اختر النوع</option>
                                            <option value="course" {{ old('type', $courseCode->type) == 'course' ? 'selected' : '' }}>دورة</option>
                                        </select>
                                        @error('type')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="is_active">الحالة</label>
                                        <select name="is_active" id="is_active" class="form-control">
                                            <option value="1" {{ old('is_active', $courseCode->is_active) ? 'selected' : '' }}>مفعل</option>
                                            <option value="0" {{ old('is_active', $courseCode->is_active) ? '' : 'selected' }}>معطل</option>
                                        </select>
                                        @error('is_active')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <!-- Course Selection -->
                            <div class="selection-section" id="course-selection">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label for="course_id"><i class="fa fa-graduation-cap"></i> اختر الدورة</label>
                                            <select name="course_id" id="course_id" class="form-control" required>
                                                @if($courseCode->course_id !== null && !$courses->contains('id', $courseCode->course_id))
                                                    <option value="{{ $courseCode->course_id }}" @selected((string) old('course_id', $courseCode->course_id) === (string) $courseCode->course_id)>ارتباط قديم غير صالح — اختر الكورس الأصلي أو أوقف الكود</option>
                                                @endif
                                                <option value="">اختر الدورة</option>
                                                @foreach($courses as $course)
                                                    <option value="{{ $course->id }}" {{ old('course_id', $courseCode->course_id) == $course->id ? 'selected' : '' }}>
                                                        {{ $course->name_ar }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('course_id')
                                                <span class="text-danger"><small>{{ $message }}</small></span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="start_date"><i class="fa fa-calendar"></i> تاريخ البداية (اختياري)</label>
                                        <input type="datetime-local" name="start_date" id="start_date" class="form-control"
                                               value="{{ old('start_date', \App\Support\BusinessClock::forDateTimeInput($courseCode->start_date)) }}">
                                        @error('start_date')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expiry_date"><i class="fa fa-calendar-times-o"></i> تاريخ الانتهاء (اختياري)</label>
                                        <input type="datetime-local" name="expiry_date" id="expiry_date" class="form-control"
                                               value="{{ old('expiry_date', \App\Support\BusinessClock::forDateTimeInput($courseCode->expiry_date)) }}">
                                        @error('expiry_date')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <div class="form-check mb-3">
                                            <input type="hidden" name="is_grant" value="0">
                                            <input class="form-check-input" type="checkbox" name="is_grant" id="is_grant" value="1" {{ old('is_grant', $courseCode->is_grant) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="is_grant"><strong>منحة واحدة لكل حساب</strong></label>
                                            <div class="form-help">الكود يفتح مشاهدة الكورس مجانًا بدون شات أو مشاريع أو تقييم أو شهادة ويمكن أن تكون الجهة المانحة جامعة أو أي جهة أخرى</div>
                                            <small class="form-text text-muted d-block">كل حساب وبريد يمكنه استخدام منحة واحدة لكورس واحد فقط.</small>
                                        </div>
                                        <label for="allowed_email_domains"><i class="fa fa-university"></i> نطاقات البريد المسموح بها (اختياري)</label>
                                        <textarea name="allowed_email_domains" id="allowed_email_domains" class="form-control" rows="2" placeholder="مثال: students.cu.edu.eg, alexu.edu.eg">{{ old('allowed_email_domains', implode(', ', $courseCode->allowed_email_domains ?? [])) }}</textarea>
                                        <small class="text-muted">اتركه فارغًا ليعمل الكود مع أي حساب، أو افصل النطاقات بفاصلة.</small>
                                        @error('allowed_email_domains')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for="description"><i class="fa fa-align-right"></i> الوصف (اختياري)</label>
                                        <textarea name="description" id="description" class="form-control" rows="3" placeholder="وصف مختصر للكود">{{ old('description', $courseCode->description) }}</textarea>
                                        @error('description')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="action-buttons">
                                <button type="submit" class="btn btn-primary-modern btn-modern">
                                    <i class="fa fa-save"></i> حفظ التغييرات
                                </button>
                                <a href="{{ route('admin.course-codes.show', $courseCode) }}" class="btn btn-secondary-modern btn-modern">
                                    <i class="fa fa-eye"></i> عرض
                                </a>
                                <a href="{{ route('admin.course-codes.index') }}" class="btn btn-secondary-modern btn-modern">
                                    <i class="fa fa-arrow-left"></i> رجوع
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

