@extends('admin.layouts.app')

@section('page.title', 'استوديو الكورس')

@section('styles')
@include('admin.courses.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-studio.css') }}">
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-workspace.css') }}">
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-editor.css') }}">
@endsection

@section('content')
@php
    $teacher = $course->teachers->first();
    $courseTitle = trim((string) ($course->name_ar ?: $course->name_en)) ?: 'كورس بلا عنوان';
    $courseDescription = trim((string) ($course->description_ar ?: $course->description_en)) ?: 'أضف وصفًا مختصرًا يشرح للطالب ما الذي سيتعلمه ولماذا يبدأ هذا الكورس.';
    $teacherName = trim((string) ($teacher?->name_ar ?: $teacher?->name_en ?: $teacher?->getRawOriginal('name'))) ?: 'اختر محاضر الكورس';
    $teacherBio = trim((string) ($teacher?->bio_ar ?: $teacher?->bio_en ?: $teacher?->getRawOriginal('bio'))) ?: 'ستظهر نبذة المحاضر للطالب هنا.';
    $sectionTypes = [
        'lesson' => ['مقطع', 'fa-play-circle', 'lesson'],
        'project' => ['مشروع', 'fa-briefcase', 'project'],
    ];
@endphp
<div class="admin-page course-studio" id="courseStudio" data-authoring-version="{{ $course->authoring_version }}" data-can-author="{{ $course->is_coming_soon ? '1' : '0' }}">
    <script type="application/json" id="courseAuthoringGraph">@json($authoringGraph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    @include('admin.courses.partials.workspace-header', ['course' => $course])

    @if($canViewCommercialReport)
    <nav class="course-studio__tabs" aria-label="أقسام استوديو الكورس" role="tablist">
        <button type="button" class="course-studio__tab is-active" data-studio-tab="builder" role="tab" aria-controls="builder" aria-selected="true" tabindex="0"><i class="fa fa-magic" aria-hidden="true"></i> بناء الكورس</button>
        <button type="button" class="course-studio__tab" data-studio-tab="statistics" role="tab" aria-controls="statistics" aria-selected="false" tabindex="-1"><i class="fa fa-bar-chart" aria-hidden="true"></i> أداء الكورس</button>
        @if($commercialReport)
            <button type="button" class="course-studio__tab" data-studio-tab="commercial-report" role="tab" aria-controls="commercial-report" aria-selected="false" tabindex="-1"><i class="fa fa-line-chart" aria-hidden="true"></i> الطلاب والدخل</button>
        @else
            <a class="course-studio__tab" href="{{ route('admin.courses.show', [$course, 'tab' => 'commercial-report']) }}#commercial-report"><i class="fa fa-line-chart" aria-hidden="true"></i> الطلاب والدخل</a>
        @endif
    </nav>
    @endif

    <section class="course-studio__panel is-active" id="builder" data-studio-panel role="tabpanel" tabindex="0">
        <div class="course-studio__layout">
            <div class="course-studio__canvas" aria-label="عرض وتحرير صفحة الكورس">
                <article class="student-course-card">
                    <div class="student-course-card__cover">
                        @if($course->image)<img src="{{ $course->image }}" alt="غلاف {{ $courseTitle }}">
                        @else<div class="student-course-card__placeholder"><i class="fa fa-picture-o" aria-hidden="true"></i><span>أضف صورة غلاف للكورس</span></div>@endif
                        @if($course->is_coming_soon)<button type="button" data-studio-course-open="image" class="studio-edit-chip studio-authoring-control"><i class="fa fa-pencil" aria-hidden="true"></i> تعديل الغلاف</button>@endif
                        <span class="student-course-card__state">{{ $course->is_coming_soon ? 'قريبًا' : ($course->is_catalog_visible ? 'متاح الآن' : 'مخفي من الاكتشاف') }}</span>
                    </div>
                    <div class="student-course-card__content">
                        @if($course->is_coming_soon)<button type="button" data-studio-course-open="details" class="studio-block-edit studio-authoring-control" aria-label="تعديل بيانات الكورس"><i class="fa fa-pencil" aria-hidden="true"></i></button>@endif
                        <div class="student-course-card__badges">@foreach($course->classifications as $classification)<span>{{ $classification->name_ar }}</span>@endforeach @if($course->level)<span>{{ $course->level->name_ar }}</span>@endif</div>
                        <h2>{{ $courseTitle }}</h2>
                        <p>{{ $courseDescription }}</p>
                        <div class="student-course-card__facts">
                            <span><i class="fa fa-play-circle" aria-hidden="true"></i> {{ number_format($sections->filter(fn ($section) => $section->getSectionType() === 'lesson')->count()) }} مقطع</span>
                            <span><i class="fa fa-clock-o" aria-hidden="true"></i> {{ number_format((int) ($course->duration_minutes_computed ?? 0)) }} دقيقة</span>
                            @if($activeStudentsCount !== null)<span><i class="fa fa-users" aria-hidden="true"></i> {{ number_format($activeStudentsCount) }} طالب</span>@endif
                            <span><i class="fa fa-star" aria-hidden="true"></i> {{ $course->ratings_count ? number_format((float) $course->ratings_avg_rating, 1).' · '.number_format((int) $course->ratings_count) : 'لا تقييمات' }}</span>
                        </div>
                        @if($previewPlans->isNotEmpty())
                            <div class="student-course-card__plans" aria-label="فئات الكورس كما تظهر للطالب">
                                @foreach($previewPlans as $plan)
                                    <article class="student-course-plan">
                                        <div><strong>{{ $plan['name'] }}</strong><span>{{ number_format($plan['price_coins']) }} عملة</span></div>
                                        <ul>
                                            <li>محتوى الكورس</li>
                                            @if($plan['chat_enabled'])<li>شات ركن</li>@endif
                                            @if($plan['project_report_enabled'])<li>تقرير المشروع</li>@endif
                                            @if($plan['project_thread_reply_enabled'])<li>محادثة المشروع</li>@endif
                                            @if($plan['certificate_enabled'])<li>الشهادة</li>@endif
                                        </ul>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </article>

                <section class="student-instructor-card">
                    @if($course->is_coming_soon)<button type="button" data-studio-course-open="details" class="studio-block-edit studio-authoring-control" aria-label="تعديل المحاضر"><i class="fa fa-pencil" aria-hidden="true"></i></button>@endif
                    <div class="student-instructor-card__avatar">@if($teacher?->profile_image_url)<img src="{{ $teacher->profile_image_url }}" alt="{{ $teacherName }}">@else<i class="fa fa-user" aria-hidden="true"></i>@endif</div>
                    <div><span>المحاضر</span><h3>{{ $teacherName }}</h3><p>{{ $teacherBio }}</p></div>
                </section>

                @include('admin.courses.partials.show.course-settings-panel')
                @include('admin.courses.partials.show.course-attachments-panel')

                <section class="course-outline" aria-labelledby="courseOutlineTitle">
                    <div class="course-outline__header">
                        <div><span>خريطة التعلّم</span><h2 id="courseOutlineTitle">محتوى الكورس</h2><p>رتّب المقاطع داخل الوحدات وأضف مشروع عبور عند الحاجة.</p></div>
                        @if($course->is_coming_soon)<button type="button" class="course-outline__add-module studio-authoring-control" data-inline-module-open><i class="fa fa-plus" aria-hidden="true"></i> وحدة جديدة</button>@endif
                    </div>

                    @if($course->modules->isEmpty())
                        <div class="course-outline__empty" id="studioEmptyCourse"><i class="fa fa-list-alt" aria-hidden="true"></i><h3>ابدأ بأول وحدة</h3><p>أنشئ وحدة، ثم أضف داخلها المقاطع ومشروع العبور عند الحاجة.</p>@if($course->is_coming_soon)<button type="button" class="studio-authoring-control" data-inline-module-open>إضافة أول وحدة</button>@endif</div>
                        <div class="course-outline__modules" id="studioModulesList"></div>
                    @else
                        <div class="course-outline__modules" id="studioModulesList">
                            @foreach($course->modules as $module)
                                <article class="outline-module" data-module-id="{{ $module->id }}">
                                    <header class="outline-module__header">
                                        @if($course->is_coming_soon)<button type="button" class="outline-module__drag studio-authoring-control" aria-label="اسحب لترتيب الوحدة"><i class="fa fa-bars" aria-hidden="true"></i></button>@endif
                                        <button type="button" class="outline-module__toggle" aria-expanded="true" aria-controls="module-{{ $module->id }}-content"><span class="outline-module__number">{{ $loop->iteration }}</span><span class="outline-module__name"><small>الوحدة {{ $loop->iteration }}</small><strong>{{ $module->title_ar ?: $module->title_en ?: 'وحدة بلا عنوان' }}</strong></span><span class="outline-module__count">{{ $module->sections->count() }} عناصر</span><i class="fa fa-chevron-up" aria-hidden="true"></i></button>
                                        @if($course->is_coming_soon)<button type="button" data-inline-module-edit data-module-id="{{ $module->id }}" class="outline-module__edit studio-authoring-control" aria-label="تعديل الوحدة"><i class="fa fa-pencil" aria-hidden="true"></i></button>@endif
                                    </header>
                                    <div class="outline-module__content studio-sortable-sections" id="module-{{ $module->id }}-content" data-module-id="{{ $module->id }}">
                                        @foreach($module->sections as $section)
                                            @php($type = $sectionTypes[$section->getSectionType()] ?? ['محتوى', 'fa-file-o', 'other'])
                                            @if($course->is_coming_soon)
                                                <button type="button" class="outline-item-insert studio-authoring-control" data-inline-editor-open="lesson" data-module-id="{{ $module->id }}" data-insert-order="{{ $loop->iteration }}" aria-label="إضافة مقطع هنا"><i class="fa fa-plus" aria-hidden="true"></i><span>مقطع هنا</span></button>
                                            @endif
                                            <div class="outline-item" data-section-id="{{ $section->id }}" data-section-type="{{ $section->getSectionType() }}">
                                                @if($course->is_coming_soon && $section->getSectionType() === 'lesson')<button type="button" class="outline-item__drag studio-authoring-control" aria-label="اسحب لترتيب المقطع"><i class="fa fa-ellipsis-v" aria-hidden="true"></i></button>@endif
                                                <span class="outline-item__icon outline-item__icon--{{ $type[2] }}"><i class="fa {{ $type[1] }}" aria-hidden="true"></i></span>
                                                <span class="outline-item__copy"><strong>{{ $section->title_ar ?: $section->title_en ?: 'عنصر بلا عنوان' }}</strong><small>{{ $type[0] }}@if($section->isLesson() && $section->sectionable?->duration_minutes) · {{ $section->sectionable->duration_minutes }} دقيقة@endif</small></span>
                                                @if($course->is_coming_soon)<button type="button" data-inline-section-edit="{{ $section->id }}" class="outline-item__edit studio-authoring-control"><i class="fa fa-pencil" aria-hidden="true"></i><span>تعديل</span></button>@endif
                                            </div>
                                        @endforeach
                                        @if($course->is_coming_soon)<div class="outline-item-actions studio-authoring-control" data-module-actions="{{ $module->id }}">
                                            @if($module->sections->filter(fn ($section) => $section->getSectionType() === 'project')->isEmpty())
                                                <button type="button" data-inline-editor-open="project" data-module-id="{{ $module->id }}"><i class="fa fa-briefcase" aria-hidden="true"></i> إضافة مشروع عبور اختياري</button>
                                            @else
                                                <span data-project-present><i class="fa fa-check-circle" aria-hidden="true"></i> مشروع العبور مضاف</span>
                                            @endif
                                        </div>@endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif

                    @include('admin.courses.partials.show.inline-authoring')
                </section>
            </div>

            <aside class="course-studio__rail studio-authoring-control" aria-label="أدوات الكورس">
                <section class="studio-rail-card studio-rail-card--status">
                    <div class="studio-rail-card__heading"><span class="studio-status-dot {{ $course->is_coming_soon ? 'is-draft' : 'is-live' }}"></span><div><small>حالة الكورس</small><h2>{{ !$course->is_coming_soon ? ($course->is_catalog_visible ? 'منشور في التطبيق' : 'منشور للطلاب ومخفي') : ($publishingAudit['ready'] ? 'جاهز للنشر' : 'مسودة غير مكتملة') }}</h2></div></div>
                    <div class="studio-readiness"><span><strong>{{ $publishingAudit['counts']['modules'] }}</strong> وحدات</span><span><strong>{{ $publishingAudit['counts']['reels'] }}</strong> مقاطع</span><span><strong>{{ $publishingAudit['counts']['projects'] }}</strong> مشروعات</span></div>
                    @if($course->is_coming_soon && !$publishingAudit['ready'])<ul>@foreach(array_slice($publishingAudit['issues'], 0, 4) as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif
                    @if($course->is_coming_soon)
                        <button type="button" data-studio-course-open="publish">مراجعة الجاهزية والنشر</button>
                    @else
                        <form method="POST" action="{{ route('admin.courses.draft.start', $course) }}">
                            @csrf
                            <button type="submit">بدء تعديل الكورس</button>
                        </form>
                    @endif
                </section>
                @if($course->is_coming_soon)
                <section class="studio-rail-card">
                    <h2>إدارة سريعة</h2>
                    <button type="button" data-studio-course-open="details"><i class="fa fa-info-circle" aria-hidden="true"></i><span><strong>بيانات الكورس</strong><small>الغلاف والوصف والمحاضر والفئات</small></span><i class="fa fa-chevron-left" aria-hidden="true"></i></button>
                    <a href="#courseOutlineTitle"><i class="fa fa-sitemap" aria-hidden="true"></i><span><strong>الوحدات والمقاطع</strong><small>الترتيب ونقل العناصر هنا</small></span><i class="fa fa-chevron-left" aria-hidden="true"></i></a>
                    <button type="button" data-studio-attachments-open><i class="fa fa-paperclip" aria-hidden="true"></i><span><strong>المرفقات والملفات</strong><small>PDF والمواد القابلة للتحميل</small></span><i class="fa fa-chevron-left" aria-hidden="true"></i></button>
                </section>
                @endif
                <p class="course-studio__hint"><i class="fa fa-lightbulb-o" aria-hidden="true"></i> {{ $course->is_coming_soon ? 'اسحب الوحدات والعناصر لترتيبها. الحفظ يتم بعد نجاح الطلب.' : 'حوّل الكورس إلى مسودة من الإعدادات قبل تغيير وحداته أو محتواه.' }}</p>
            </aside>
        </div>
    </section>

    @if($canViewCommercialReport)<section class="course-studio__panel" id="statistics" data-studio-panel role="tabpanel" tabindex="0" hidden>@include('admin.courses.partials.show.statistics')</section>@endif
    @if($commercialReport)<section class="course-studio__panel" id="commercial-report" data-studio-panel role="tabpanel" tabindex="0" hidden>@include('admin.courses.partials.show.commercial-report')</section>@endif
    <div class="course-studio__toast" id="courseStudioToast" role="status" aria-live="polite"></div>
</div>
@endsection

@section('scripts')
@php($sortableAsset = public_path('admin/assets/js/vendor/sortablejs/Sortable.min.js'))
@if(is_file($sortableAsset))
<script src="{{ asset('admin/assets/js/vendor/sortablejs/Sortable.min.js') }}?v={{ filemtime($sortableAsset) }}"></script>
@endif
@include('admin.courses.partials.show.scripts')
@if($course->is_coming_soon)
@include('admin.courses.partials.editor-scripts', ['formId' => 'studioCourseForm', 'changesId' => null, 'progressId' => null, 'imageStatus' => 'تم اختيار الغلاف'])
@include('admin.course-pdfs.partials.form-scripts')
@include('admin.course-sections.partials.bunny-direct-upload')
@endif
@endsection
