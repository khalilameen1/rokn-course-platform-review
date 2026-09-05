@if($course->is_coming_soon)
<section class="studio-course-panel course-editor studio-authoring-control" id="studioCoursePanel" hidden aria-labelledby="studioCoursePanelTitle">
    <header class="studio-course-panel__header">
        <div>
            <span>إعداد الكورس</span>
            <h2 id="studioCoursePanelTitle">البيانات والفئات</h2>
        </div>
        <button type="button" data-studio-course-close aria-label="إغلاق الإعدادات"><i class="fa fa-times" aria-hidden="true"></i></button>
    </header>

    {!! Form::model($course, ['method' => 'PATCH', 'files' => true, 'url' => route('admin.courses.update', $course->id), 'id' => 'studioCourseForm']) !!}
        <input type="hidden" name="authoring_version" value="{{ (int) $course->authoring_version }}">
        <input type="hidden" name="return_to" value="studio">
        <div class="studio-inline-feedback{{ $errors->any() ? ' is-error' : '' }}" data-course-feedback role="alert" aria-live="assertive" @if(!$errors->any()) hidden @endif>{{ $errors->first() }}</div>
        <div class="studio-course-panel__body">
            @include('admin.courses.partials.editor.basic-information')
            @include('admin.courses.partials.edit.course-settings')
            @include('admin.courses.partials.edit.access-plans')
            @include('admin.courses.partials.editor.course-image')
        </div>
        <footer class="studio-course-panel__actions">
            <button type="button" class="studio-inline-secondary" data-studio-course-close>إلغاء</button>
            <button type="submit" name="publishing_intent" value="save" class="studio-inline-secondary"><i class="fa fa-save" aria-hidden="true"></i> حفظ المسودة</button>
            <button type="submit" name="publishing_intent" value="publish" class="studio-inline-primary"><i class="fa fa-paper-plane" aria-hidden="true"></i> {{ $hasPublishedRevision ? 'نشر التعديلات' : 'نشر الكورس' }}</button>
        </footer>
    {!! Form::close() !!}
</section>
@endif
