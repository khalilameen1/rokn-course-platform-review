@extends('admin.layouts.app')

@section('page.title', 'استوديو الكورس')

@section('styles')
@include('admin.courses.partials._dynamic_styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-studio.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-workspace.css') }}">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-editor.css') }}">
@endsection

@section('content')
@php
    $sectionTypes = [
        'lesson' => ['مقطع', 'fa-play-circle', 'lesson'],
        'project' => ['مشروع', 'fa-briefcase', 'project'],
    ];
    $authoringSectionsById = collect($authoringGraph['modules'] ?? [])
        ->flatMap(fn (array $module) => $module['sections'] ?? [])
        ->keyBy(fn (array $section) => (int) $section['id']);
@endphp
<div class="admin-page course-studio" id="courseStudio" data-course-id="{{ $course->id }}" data-actor-id="{{ auth()->id() }}" data-summary-url="{{ route('admin.courses.show', [$course, 'summary' => 1]) }}" data-authoring-version="{{ $course->authoring_version }}" data-can-author="{{ $course->is_coming_soon ? '1' : '0' }}">
    <script type="application/json" id="courseAuthoringGraph">@json($authoringGraph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    @include('admin.courses.partials.workspace-header', ['course' => $course])
    <div class="studio-inline-feedback is-error" id="courseStudioSummaryStatus" role="status" hidden>
        <span data-studio-summary-message>حُفظ التعديل ولم تتحدث المعاينة بعد</span>
        <button type="button" class="studio-inline-secondary" data-studio-summary-retry>تحديث المعاينة</button>
    </div>

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
                @include('admin.courses.partials.show.course-overview')

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
                                            @php($authoringSection = $authoringSectionsById->get((int) $section->id))
                                            @if($course->is_coming_soon)
                                                <button type="button" class="outline-item-insert studio-authoring-control" data-inline-editor-open="lesson" data-module-id="{{ $module->id }}" data-insert-order="{{ $loop->iteration }}" aria-label="إضافة مقطع هنا"><i class="fa fa-plus" aria-hidden="true"></i><span>مقطع هنا</span></button>
                                            @endif
                                            <div class="outline-item" data-section-id="{{ $section->id }}" data-section-type="{{ $section->getSectionType() }}">
                                                @if($course->is_coming_soon && $section->getSectionType() === 'lesson')<button type="button" class="outline-item__drag studio-authoring-control" aria-label="اسحب لترتيب المقطع"><i class="fa fa-ellipsis-v" aria-hidden="true"></i></button>@endif
                                                <span class="outline-item__icon outline-item__icon--{{ $type[2] }}"><i class="fa {{ $type[1] }}" aria-hidden="true"></i></span>
                                                <span class="outline-item__copy"><strong>{{ $authoringSection['title'] }}</strong><small>{{ $authoringSection['row_label'] }}</small></span>
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
                @include('admin.courses.partials.show.course-readiness')
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
<script src="{{ versioned_asset('admin/assets/js/vendor/sortablejs/Sortable.min.js') }}"></script>
@endif
@include('admin.courses.partials.show.scripts')
@if($course->is_coming_soon)
@include('admin.courses.partials.editor-scripts', ['formId' => 'studioCourseForm', 'changesId' => null, 'progressId' => null, 'imageStatus' => 'تم اختيار الغلاف'])
@include('admin.course-pdfs.partials.form-scripts')
@include('admin.course-sections.partials.bunny-direct-upload')
@endif
@endsection
