            @php
                $classificationSelectionWasSubmitted = old('classification_ids_present') !== null;
                $selectedClassificationIds = collect($classificationSelectionWasSubmitted
                    ? old('classification_ids', [])
                    : $course->classifications->modelKeys())
                    ->map(fn ($id) => (string) $id)
                    ->all();
                $teacherSelectionWasSubmitted = old('teacher_ids_present') !== null;
                $selectedTeacherIds = collect($teacherSelectionWasSubmitted
                    ? old('teacher_ids', [])
                    : $course->teachers->modelKeys())
                    ->map(fn ($id) => (string) $id)
                    ->all();
                $selectedLevelId = (string) old('level_id', $course->level_id ?? '');
                $selectedPathId = (string) old('path_id', $course->path_id ?? '');
            @endphp
            <!-- Course Settings Section -->
            <div class="form-section" id="course-editor-settings">
                @include('admin.courses.partials.publishing-area-issues', ['area' => 'settings'])
                <h2 class="section-title">
                    <div class="section-icon">
                        <i class="fa fa-cog"></i>
                    </div>

                    إعدادات الكورس
                </h2>

                <div class="form-row">

                    <div class="form-group-modern">
                        <label for="classification_ids" class="form-label-modern">
                            <i class="fa fa-tags label-icon"></i>
                            التصنيفات
                        </label>
                        <input type="hidden" name="classification_ids_present" value="1">
                        <select name="classification_ids[]" id="classification_ids" class="form-control-modern select2" multiple>
                            @foreach($classifications as $classification)
                                <option value="{{ $classification->id }}" {{ in_array((string) $classification->id, $selectedClassificationIds, true) ? 'selected' : '' }}>
                                    {{ $classification->name_ar }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-help">اختر التصنيفات المناسبة للكورس</div>
                    </div>

                    <div class="form-group-modern">
                        <label for="level_id" class="form-label-modern">
                            <i class="fa fa-signal label-icon"></i>
                            مستوى الكورس
                        </label>
                        <select name="level_id" id="level_id" class="form-control-modern select2">
                            <option value="">اختر المستوى...</option>
                            @foreach($levels as $level)
                                <option value="{{ $level->id }}" {{ $selectedLevelId === (string) $level->id ? 'selected' : '' }}>
                                    {{ $level->name_ar }} ({{ $level->name_en }})
                                </option>
                            @endforeach
                        </select>
                        <div class="form-help">حدد مستوى صعوبة الكورس</div>
                    </div>

                    <div class="form-group-modern">
                        <label for="path_id" class="form-label-modern">
                            <i class="fa fa-road label-icon"></i>
                            المسار (Path)
                        </label>
                        <select name="path_id" id="path_id" class="form-control-modern select2">
                            <option value="">لا يوجد مسار</option>
                            @foreach($paths as $path)
                                <option value="{{ $path->id }}" {{ $selectedPathId === (string) $path->id ? 'selected' : '' }}>
                                    {{ $path->title_ar }} ({{ $path->title_en }})
                                </option>
                            @endforeach
                        </select>
                        <div class="form-help">اختر المسار الذي يتبع له هذا الكورس</div>
                    </div>
                </div>

                <div class="form-section">
                    <h2 class="section-title">
                        <div class="section-icon"><i class="fa fa-paperclip"></i></div>
                        تنبيه اكتشاف المرفقات أثناء المشاهدة
                    </h2>
                    <div class="form-help course-editor__section-help">يستخدم نفس الملفات وزر التنزيل الذي يراه الطالب.</div>
                    <input type="hidden" name="attachment_prompt_enabled" value="0">
                    <label class="course-editor__inline-check course-editor__inline-check--spaced">
                        <input type="checkbox" name="attachment_prompt_enabled" value="1" {{ old('attachment_prompt_enabled', $course->attachment_prompt_enabled ?? true) ? 'checked' : '' }}>
                        إظهار التنبيه داخل المشاهدة
                    </label>
                    <div class="form-row">
                        <div class="form-group-modern">
                            <label class="form-label-modern">يظهر بعد كم ثانية؟</label>
                            <input class="form-control-modern" type="number" min="0" max="3600" name="attachment_prompt_at_seconds" value="{{ old('attachment_prompt_at_seconds', $course->attachment_prompt_at_seconds ?? config('course_attachments.prompt.at_seconds')) }}">
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">عنوان النافذة</label>
                            <input class="form-control-modern" type="text" maxlength="120" name="attachment_prompt_title" value="{{ old('attachment_prompt_title', $course->attachment_prompt_title ?: config('course_attachments.prompt.title')) }}">
                        </div>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">التكرار</label>
                        <select class="form-control-modern" name="attachment_prompt_frequency">
                            @foreach((array) config('course_attachments.prompt.frequencies', []) as $frequency => $label)
                                <option value="{{ $frequency }}" {{ old('attachment_prompt_frequency', $course->attachment_prompt_frequency ?: config('course_attachments.prompt.default_frequency')) === $frequency ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">نص النافذة</label>
                        <textarea class="form-control-modern" rows="3" maxlength="500" name="attachment_prompt_body">{{ old('attachment_prompt_body', $course->attachment_prompt_body ?: config('course_attachments.prompt.body')) }}</textarea>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">نص زر الفتح</label>
                        <input class="form-control-modern" type="text" maxlength="80" name="attachment_prompt_button_text" value="{{ old('attachment_prompt_button_text', $course->attachment_prompt_button_text ?: config('course_attachments.prompt.button_text')) }}">
                    </div>
                </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">سياسة الشارة المهنية</label>
                        <input type="hidden" name="awards_badge" value="0">
                        <label class="course-editor__inline-check course-editor__inline-check--spaced">
                            <input type="checkbox" name="awards_badge" value="1" {{ old('awards_badge', $course->awards_badge) ? 'checked' : '' }}>
                            يمنح هذا الكورس شارة مهنية
                        </label>
                        <select name="badge_track" class="form-control-modern">
                            <option value="">بدون شارة (الديني واللغات وغيرهما)</option>
                            <option value="professional" {{ old('badge_track', $course->badge_track) === 'professional' ? 'selected' : '' }}>مهني</option>
                            <option value="freelance" {{ old('badge_track', $course->badge_track) === 'freelance' ? 'selected' : '' }}>فريلانس</option>
                        </select>
                        <div class="form-help">لن تُمنح الشارة إلا عند تفعيل الخيار واختيار مهني أو فريلانس.</div>
                    </div>

                    @include('admin.courses.partials.certificate-text-template')

                    <div class="form-group-modern">
                        <label for="teacher_ids" class="form-label-modern">
                            <i class="fa fa-user-tie label-icon"></i>
                            المعلمون
                        </label>
                        <input type="hidden" name="teacher_ids_present" value="1">
                        <select name="teacher_ids[]" id="teacher_ids" class="form-control-modern select2" multiple>
                            @foreach($teachers as $teacher)
                                <option value="{{ $teacher->id }}" {{ in_array((string) $teacher->id, $selectedTeacherIds, true) ? 'selected' : '' }}>
                                    {{ $teacher->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-help">يمكنك اختيار أكثر من معلم للإشراف على الكورس</div>
                    </div>
                </div>

                @if($canManageHero)
                <div class="form-row">
                    <div class="form-group-modern">
                        <label class="checkbox-item {{ $mainCourseDefault ? 'selected' : '' }}" for="is_main_course">
                            <div class="custom-checkbox">
                                <i class="fa fa-check{{ $mainCourseDefault ? '' : ' course-editor__check-icon--hidden' }}"></i>
                            </div>
                            <div>
                                <div class="course-editor__option-title">كورس رئيسي</div>
                                <div class="course-editor__option-description">يظهر كبطل الصفحة الوحيد. اختياره يستبدل الكورس الرئيسي السابق تلقائيًا.</div>
                            </div>
                            {!! Form::hidden('is_main_course', 0) !!}
                            {!! Form::checkbox('is_main_course', 1, old('is_main_course', $mainCourseDefault), ['id' => 'is_main_course', 'class' => 'course-editor__native-checkbox']) !!}
                        </label>
                    </div>
                </div>
                @endif

                {{-- The update route owns both operations. The clicked submit
                     button supplies the one explicit intent; publication is no
                     longer hidden inside an inverted draft checkbox. --}}
                <div class="form-row">
                    <div class="form-group-modern">
                        <label class="checkbox-item {{ $catalogVisibilityDefault ? 'selected' : '' }}" for="is_catalog_visible">
                            <div class="custom-checkbox">
                                <i class="fa fa-check{{ $catalogVisibilityDefault ? '' : ' course-editor__check-icon--hidden' }}"></i>
                            </div>
                            <div>
                                <div class="course-editor__option-title">
                                    {{ $hasPublishedRevision ? 'إظهار الكورس في التطبيق والبحث' : 'إظهار بطاقة «قريبًا» في التطبيق' }}
                                </div>
                                <div class="course-editor__option-description">
                                    {{ $hasPublishedRevision
                                        ? 'يمكن إخفاؤه من الاكتشاف مع بقاء وصول الطلاب المسجلين'
                                        : 'لن تظهر البطاقة قبل اكتمال الغلاف والمحاضر والتصنيف والوصف' }}
                                </div>
                            </div>
                            {!! Form::hidden('is_catalog_visible', 0) !!}
                            {!! Form::checkbox('is_catalog_visible', 1, old('is_catalog_visible', $catalogVisibilityDefault), ['id' => 'is_catalog_visible', 'class' => 'course-editor__native-checkbox']) !!}
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="home_sort_order" class="form-label-modern">ترتيب الكورس داخل صفوف الرئيسية</label>
                        {!! Form::number('home_sort_order', $course->home_sort_order ?? 100, ['class' => 'form-control-modern', 'id' => 'home_sort_order', 'min' => 0, 'max' => 10000, 'required' => true]) !!}
                        <div class="form-help">الرقم الأصغر يظهر أولًا داخل كل صف.</div>
                    </div>
                    <div class="form-group-modern">
                        <label for="catalog_badge_ar" class="form-label-modern">شارة البطاقة</label>
                        {!! Form::text('catalog_badge_ar', $course->catalog_badge_ar, ['class' => 'form-control-modern', 'id' => 'catalog_badge_ar', 'maxlength' => 40, 'placeholder' => 'مثال مجاني أو جديد']) !!}
                        <div class="form-help">اتركها فارغة إذا لم تكن لها قيمة حقيقية.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="catalog_badge_en" class="form-label-modern">شارة البطاقة بالإنجليزية</label>
                        {!! Form::text('catalog_badge_en', $course->catalog_badge_en, ['class' => 'form-control-modern', 'id' => 'catalog_badge_en', 'maxlength' => 40]) !!}
                    </div>
                    <div class="form-group-modern">
                        <label for="catalog_badge_tone" class="form-label-modern">لون الشارة</label>
                        {!! Form::select('catalog_badge_tone', ['blue' => 'أزرق', 'green' => 'أخضر', 'gold' => 'ذهبي', 'neutral' => 'محايد'], $course->catalog_badge_tone ?: 'blue', ['class' => 'form-control-modern', 'id' => 'catalog_badge_tone']) !!}
                    </div>
                </div>
