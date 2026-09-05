@php
    $selectedCourseIds = collect(old('course_ids', $selectedCourseIds ?? []))
        ->map(fn ($id) => (string) $id)
        ->all();
@endphp
<div class="row form-group">
    <div class="col col-md-3">
        <label for="course_ids" class="form-control-label">كورسات الصف</label>
    </div>
    <div class="col-12 col-md-9">
        <select id="course_ids" name="course_ids[]" class="form-control" multiple size="{{ min(10, max(4, $courses->count())) }}">
            @foreach($courses as $course)
                <option value="{{ $course->id }}" {{ in_array((string) $course->id, $selectedCourseIds, true) ? 'selected' : '' }}>
                    {{ $course->name_ar ?: $course->name_en }}{{ $course->is_coming_soon ? ' — قريبًا' : '' }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">
            اختر الكورسات الظاهرة في الكتالوج، المنشورة أو «قريبًا». يمكن للكورس نفسه الظهور في أكثر من صف.
        </small>
        @if($courses->isEmpty())
            <div class="alert alert-light mt-2 mb-0">لا توجد كورسات ظاهرة في الكتالوج الآن.</div>
        @endif
        @error('course_ids')<div class="text-danger">{{ $message }}</div>@enderror
        @error('course_ids.*')<div class="text-danger">{{ $message }}</div>@enderror
    </div>
</div>
