@php
    $projectSubmissionTypes = (array) config('projects.submission_types', []);
@endphp

@if($course->is_coming_soon)
<div class="studio-inline-authoring studio-authoring-control" id="studioInlineAuthoring">
    <section class="studio-inline-editor" id="studioInlineEditor" hidden aria-labelledby="studioInlineEditorTitle">
        <form
            id="sectionForm"
            action="{{ route('admin.courses.sections.store', $course) }}"
            method="POST"
            enctype="multipart/form-data"
            data-course-id="{{ $course->id }}"
            data-section-id=""
            data-bunny-upload-init="{{ route('admin.courses.sections.video-uploads.store', $course) }}"
            data-bunny-upload-renew="{{ route('admin.courses.sections.video-uploads.renew', $course) }}">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
            <input type="hidden" name="_method" value="POST" disabled>
            <input type="hidden" name="module_id" value="">
            <input type="hidden" name="order" value="">
            <input type="hidden" name="section_type" id="section_type" value="lesson">
            <input type="hidden" name="video_source_type" value="bunny">
            <input type="hidden" name="bunny_video_claim" id="bunny_video_claim" value="">

            <header class="studio-inline-editor__header">
                <div>
                    <span id="studioInlineEditorEyebrow">مقطع جديد</span>
                    <h3 id="studioInlineEditorTitle">أضف المقطع داخل الوحدة</h3>
                </div>
                <button type="button" class="studio-inline-editor__close" data-inline-editor-close aria-label="إغلاق">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </header>

            <div class="studio-inline-feedback" id="studioInlineFeedback" role="status" aria-live="polite" hidden></div>

            <div class="studio-inline-editor__body">
                <div class="studio-inline-field studio-inline-field--wide">
                    <label for="title_ar">العنوان</label>
                    <input type="text" id="title_ar" name="title_ar" maxlength="255" required autocomplete="off" placeholder="عنوان واضح للمقطع">
                    <span class="studio-inline-error" data-field-error="title_ar"></span>
                </div>

                <div id="studioInlineLessonFields" class="studio-inline-fields">
                    <div class="studio-inline-field studio-inline-field--video">
                        <label for="bunny_video">الفيديو</label>
                        <input
                            type="file"
                            id="bunny_video"
                            accept="video/mp4,video/quicktime,video/x-msvideo,video/webm"
                            data-video-required="true"
                            data-required="true"
                            required>
                        <small>MP4 أو MOV أو AVI أو WebM حتى 5GB</small>
                        <span class="studio-inline-error" data-field-error="bunny_video_claim"></span>
                    </div>

                    <div class="studio-inline-field studio-inline-field--wide">
                        <label for="lesson_description_ar">الكابشن</label>
                        <textarea id="lesson_description_ar" name="lesson_description_ar" rows="3" placeholder="النص المختصر الذي يظهر مع المقطع"></textarea>
                        <span class="studio-inline-error" data-field-error="lesson_description_ar"></span>
                    </div>

                    <div class="studio-inline-field">
                        <label for="lesson_thumbnail">الصورة المصغرة</label>
                        <input type="file" id="lesson_thumbnail" name="lesson_thumbnail" accept="image/jpeg,image/png,image/webp">
                        <small>JPG أو PNG أو WebP حتى 2MB</small>
                        <span class="studio-inline-error" data-field-error="lesson_thumbnail"></span>
                    </div>

                    <div class="studio-inline-field">
                        <label for="lesson_duration_minutes">المدة بالدقائق</label>
                        <input type="number" id="lesson_duration_minutes" name="lesson_duration_minutes" min="1" step="1" inputmode="numeric" placeholder="تُقرأ من الفيديو">
                        <span class="studio-inline-error" data-field-error="lesson_duration_minutes"></span>
                    </div>

                    <label class="studio-inline-check studio-inline-field--wide" for="is_opened">
                        <input type="checkbox" name="is_opened" id="is_opened" value="1">
                        <span>معاينة مجانية</span>
                    </label>

                    <div id="bunny_upload_progress" class="studio-inline-upload is-hidden studio-inline-field--wide" aria-live="polite">
                        <div class="studio-inline-upload__track">
                            <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                        </div>
                        <div class="studio-inline-upload__status">
                            <small id="bunny_upload_status">جاري تجهيز الرفع</small>
                            <div>
                                <button type="button" id="bunny_upload_cancel">إيقاف</button>
                                <button type="button" id="bunny_upload_retry" class="is-hidden">متابعة الرفع</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="studioInlineProjectFields" class="studio-inline-fields" hidden>
                    <div class="studio-inline-field studio-inline-field--wide">
                        <label for="project_requirements_ar">المطلوب من الطالب</label>
                        <textarea id="project_requirements_ar" name="project_requirements_ar" rows="5" placeholder="اكتب المطلوب بوضوح"></textarea>
                        <span class="studio-inline-error" data-field-error="project_requirements_ar"></span>
                    </div>

                    <fieldset class="studio-inline-field studio-inline-field--wide studio-inline-submission-types">
                        <legend>طرق التسليم</legend>
                        <div>
                            @foreach($projectSubmissionTypes as $typeKey => $type)
                                <label for="studio_project_submission_{{ $typeKey }}">
                                    <input
                                        type="checkbox"
                                        name="project_submission_types[]"
                                        value="{{ $typeKey }}"
                                        id="studio_project_submission_{{ $typeKey }}"
                                        checked>
                                    <span>{{ $type['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        <span class="studio-inline-error" data-field-error="project_submission_types"></span>
                    </fieldset>

                    <label class="studio-inline-check studio-inline-field--wide" for="is_graduation_project">
                        <input type="checkbox" name="is_graduation_project" id="is_graduation_project" value="1">
                        <span>المشروع النهائي للكورس</span>
                    </label>
                </div>
            </div>

            <footer class="studio-inline-editor__actions">
                <button type="button" class="studio-inline-secondary" data-inline-editor-close>إلغاء</button>
                <button type="button" class="studio-inline-danger" id="studioInlineDeleteSection" hidden>
                    <i class="fa fa-trash" aria-hidden="true"></i>
                    <span>حذف</span>
                </button>
                <button type="submit" class="studio-inline-primary" id="studioInlineSaveSection">
                    <i class="fa fa-check" aria-hidden="true"></i>
                    <span>حفظ المقطع</span>
                </button>
            </footer>
        </form>
    </section>

    <section class="studio-inline-module" id="studioInlineModuleEditor" hidden aria-labelledby="studioInlineModuleTitle">
        <form id="studioModuleForm" action="{{ route('admin.courses.modules.store', $course) }}" method="POST">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
            <input type="hidden" name="_method" value="POST" disabled>
            <input type="hidden" name="order" value="">
            <div class="studio-inline-feedback" data-module-feedback role="status" aria-live="polite" hidden></div>
            <div class="studio-inline-module__copy">
                <span>وحدة جديدة</span>
                <label id="studioInlineModuleTitle" for="studioInlineModuleName">عنوان الوحدة</label>
            </div>
            <input type="text" id="studioInlineModuleName" name="title_ar" maxlength="255" required autocomplete="off" placeholder="مثال: ابدأ الرسم">
            <span class="studio-inline-error" data-field-error="title_ar"></span>
            <div class="studio-inline-module__actions">
                <button type="button" class="studio-inline-secondary" data-inline-module-close>إلغاء</button>
                <button type="button" class="studio-inline-danger" id="studioInlineDeleteModule" hidden><i class="fa fa-trash" aria-hidden="true"></i> حذف</button>
                <button type="submit" class="studio-inline-primary"><i class="fa fa-check" aria-hidden="true"></i> حفظ الوحدة</button>
            </div>
        </form>
    </section>
</div>
@endif
