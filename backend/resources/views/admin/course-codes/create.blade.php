@extends('admin.layouts.app')

@section('page.title', 'إنشاء كود جديد')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-codes.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-codes-create.css') }}">
@endsection

@section('content')
<div class="admin-page content course-codes-page">
    <div class="animated fadeIn">
        <!-- Page Header -->
        <div class="page-header modern-header">
            <h1><i class="fa fa-plus-circle"></i> إنشاء كود جديد</h1>
        </div>

        <div class="modern-card">
            <div class="modern-card-header">
                <h4><i class="fa fa-edit"></i> معلومات الكود</h4>
            </div>
            <div class="modern-card-body">
            <div class="modern-card-body">

                        <form method="POST" action="{{ route('admin.course-codes.store') }}" id="create-code-form">
                            @csrf
                            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="name">اسم الكود (اختياري)</label>
                                        <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" placeholder="مثال: كود خاص للطلاب المتفوقين">
                                        @error('name')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="number_of_codes">عدد الأكواد المطلوب إنشاؤها</label>
                                        <input type="number" name="number_of_codes" id="number_of_codes" class="form-control" value="{{ old('number_of_codes', 1) }}" min="1" max="100">
                                        @error('number_of_codes')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="type">نوع الكود <span class="required-star">*</span></label>
                                        <select name="type" id="type" class="form-control" required>
                                            <option value="">اختر النوع</option>
                                            <option value="course" {{ old('type') == 'course' ? 'selected' : '' }}>دورة</option>
                                        </select>
                                        @error('type')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="max_uses">عدد مرات الاستخدام</label>
                                        <input type="number" name="max_uses" id="max_uses" class="form-control" value="{{ old('max_uses', 1) }}" min="1" max="10000">
                                        @error('max_uses')
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
                                            <label for="course_id"><i class="fa fa-graduation-cap"></i> اختر الدورة <span class="required-star">*</span></label>
                                            <select name="course_id" id="course_id" class="form-control">
                                                <option value="">اختر الدورة</option>
                                                @foreach($courses as $course)
                                                    <option value="{{ $course->id }}" {{ old('course_id') == $course->id ? 'selected' : '' }}>
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

                            <!-- Lesson Selection -->
                            <div class="selection-section" id="lesson-selection">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label for="lesson_id"><i class="fa fa-book"></i> اختر الدرس <span class="required-star">*</span></label>
                                            <select name="lesson_id" id="lesson_id" class="form-control">
                                                <option value="">اختر الدرس</option>
                                                @foreach($lessons as $lesson)
                                                    <option value="{{ $lesson->id }}" {{ old('lesson_id') == $lesson->id ? 'selected' : '' }}>
                                                        {{ $lesson->title }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('lesson_id')
                                                <span class="text-danger"><small>{{ $message }}</small></span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Multiple Lessons Selection -->
                            <div class="selection-section" id="multiple-lessons-selection">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label><i class="fa fa-list"></i> اختر الدروس <span class="required-star">*</span></label>
                                            <div id="lessons-container" class="mt-3">
                                                <p class="text-muted">يرجى اختيار دورة أولاً لعرض الدروس المتاحة</p>
                                            </div>
                                            @error('lesson_ids')
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
                                        <input type="datetime-local" name="start_date" id="start_date" class="form-control" value="{{ old('start_date') }}">
                                        @error('start_date')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expiry_date"><i class="fa fa-calendar-times-o"></i> تاريخ الانتهاء (اختياري)</label>
                                        <input type="datetime-local" name="expiry_date" id="expiry_date" class="form-control" value="{{ old('expiry_date') }}">
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
                                            <input class="form-check-input" type="checkbox" name="is_grant" id="is_grant" value="1" {{ old('is_grant') ? 'checked' : '' }}>
                                            <label class="form-check-label" for="is_grant"><strong>منحة كلية — كورس ومشاريع كاملة بلا Rokn AI أو شهادة</strong></label>
                                            <small class="form-text text-muted d-block">كل حساب وبريد يمكنه استخدام منحة واحدة لكورس واحد فقط. يمكنه لاحقًا ترقية نفس الكورس للمسار الكامل.</small>
                                        </div>
                                        <label for="allowed_email_domains"><i class="fa fa-university"></i> نطاقات البريد المسموح بها (اختياري)</label>
                                        <textarea name="allowed_email_domains" id="allowed_email_domains" class="form-control" rows="2" placeholder="مثال: students.cu.edu.eg, alexu.edu.eg">{{ old('allowed_email_domains') }}</textarea>
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
                                        <textarea name="description" id="description" class="form-control" rows="3" placeholder="وصف مختصر للكود">{{ old('description') }}</textarea>
                                        @error('description')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="action-buttons">
                                <button type="submit" class="btn btn-primary-modern btn-modern" id="submit-btn">
                                    <i class="fa fa-save"></i> إنشاء الأكواد
                                </button>
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

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {

    // Ensure jQuery is available
    if (typeof jQuery === 'undefined') {
        return;
    }

    var $ = jQuery;

    // Handle type change
    $('#type').on('change', function() {
        var type = $(this).val();

        // Hide all selection divs
        $('#course-selection, #lesson-selection, #multiple-lessons-selection').hide();

        // Reset selections
        $('#course_id, #lesson_id').val('');
        $('#lessons-container').html('<p class="text-muted">يرجى اختيار دورة أولاً لعرض الدروس المتاحة</p>');

        // Show relevant selection based on type
        switch(type) {
            case 'course':
                $('#course-selection').show();
                break;
            case 'lesson':
                $('#lesson-selection').show();
                break;
            case 'multiple_lessons':
                $('#course-selection').show();
                $('#multiple-lessons-selection').show();
                break;
        }
    });

    // Load lessons when course is selected for multiple lessons
    $('#course_id').on('change', function() {
        var courseId = $(this).val();

        if ($('#type').val() === 'multiple_lessons') {
            loadLessons();
        }
    });

    // Load lessons via AJAX
    function loadLessons() {
        var courseId = $('#course_id').val();
        if (!courseId) {
            $('#lessons-container').html('<p class="text-muted">يرجى اختيار دورة أولاً لعرض الدروس المتاحة</p>');
            return;
        }

        $('#lessons-container').html('<div class="text-center"><i class="fa fa-spinner fa-spin"></i> جاري تحميل الدروس...</div>');


        $.ajax({
            url: '{{ route("admin.course-codes.get-lessons") }}',
            method: 'GET',
            data: { course_id: courseId },
            dataType: 'json',
            success: function(response) {

                if (response.length === 0) {
                    $('#lessons-container').html('<p class="text-warning">لا توجد دروس متاحة لهذه الدورة</p>');
                    return;
                }

                var html = '<div class="checkbox-grid">';
                response.forEach(function(lesson) {
                    html += '<div class="checkbox-item">';
                    html += '<label for="lesson_' + lesson.id + '">';
                    html += '<input type="checkbox" name="lesson_ids[]" value="' + lesson.id + '" id="lesson_' + lesson.id + '">';
                    html += '<span>' + lesson.title + '</span>';
                    html += '</label>';
                    html += '</div>';
                });
                html += '</div>';
                html += '<div class="mt-3">';
                html += '<button type="button" class="btn btn-sm btn-primary-modern btn-modern" onclick="selectAllLessons()"><i class="fa fa-check-square-o"></i> تحديد الكل</button> ';
                html += '<button type="button" class="btn btn-sm btn-secondary-modern btn-modern" onclick="deselectAllLessons()"><i class="fa fa-square-o"></i> إلغاء التحديد</button>';
                html += '</div>';
                $('#lessons-container').html(html);
            },
            error: function(xhr, status, error) {
                $('#lessons-container').html('<p class="text-danger">حدث خطأ أثناء تحميل الدروس</p>');
            }
        });
    }

    // Form validation
    $('#create-code-form').on('submit', function(e) {
        var type = $('#type').val();
        var isValid = true;


        // Check required fields based on type
        if (type === 'course') {
            if (!$('#course_id').val()) {
                alert('يرجى اختيار الدورة');
                isValid = false;
            }
        } else if (type === 'lesson') {
            if (!$('#lesson_id').val()) {
                alert('يرجى اختيار الدرس');
                isValid = false;
            }
        } else if (type === 'multiple_lessons') {
            if (!$('#course_id').val()) {
                alert('يرجى اختيار الدورة');
                isValid = false;
            } else if ($('input[name="lesson_ids[]"]:checked').length === 0) {
                alert('يرجى اختيار درس واحد على الأقل');
                isValid = false;
            }
        }

        if (!isValid) {
            e.preventDefault();
        }
    });

    // Trigger type change on page load if there's a value
    if ($('#type').val()) {
        $('#type').trigger('change');
    }

});

// Global functions for lesson selection
function selectAllLessons() {
    if (typeof jQuery !== 'undefined') {
        jQuery('input[name="lesson_ids[]"]').prop('checked', true);
    }
}

function deselectAllLessons() {
    if (typeof jQuery !== 'undefined') {
        jQuery('input[name="lesson_ids[]"]').prop('checked', false);
    }
}
</script>
@endsection

