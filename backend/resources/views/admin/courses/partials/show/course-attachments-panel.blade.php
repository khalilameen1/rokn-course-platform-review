@if($course->is_coming_soon)
@php
    $coursePdfGraph = [
        'store_url' => $coursePdfStoreUrl,
        'create_receipt_url' => route('admin.courses.pdfs.create-intents.show', [$course, '__INTENT__']),
        'reorder_url' => $coursePdfReorderUrl,
        'max_order' => $coursePdfMaxOrder,
        'pdfs' => $coursePdfs,
    ];
@endphp
<section class="studio-attachments-panel studio-authoring-control" id="studioCourseAttachments" hidden aria-labelledby="studioCourseAttachmentsTitle">
    <script type="application/json" id="coursePdfAuthoringGraph">@json($coursePdfGraph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

    <header class="studio-course-panel__header">
        <div><span>مواد الكورس</span><h2 id="studioCourseAttachmentsTitle">مرفقات الكورس</h2></div>
        <button type="button" data-studio-attachments-close aria-label="إغلاق المرفقات"><i class="fa fa-times" aria-hidden="true"></i></button>
    </header>

    <div class="studio-attachments-panel__body">
        <div class="studio-attachments-list" id="studioCoursePdfList" aria-live="polite">
            @foreach($coursePdfs as $pdf)
                @php
                    $externalAttachment = ($pdf['source_type'] ?? 'upload') === 'external';
                    $attachmentMeta = collect([
                        $externalAttachment ? 'رابط' : ($pdf['formatted_file_size'] ?? null),
                        ($pdf['platform'] ?? 'mobile') === 'computer' ? 'للكمبيوتر · نسخ الرابط' : 'للهاتف · تحميل',
                        $pdf['is_active'] ? 'ظاهر للطلاب' : 'مخفي',
                    ])->filter()->implode(' · ');
                @endphp
                <article class="studio-attachment" data-pdf-id="{{ $pdf['id'] }}">
                    <span class="studio-attachment__drag" aria-label="اسحب لترتيب المرفق"><i class="fa fa-bars" aria-hidden="true"></i></span>
                    <span class="studio-attachment__icon {{ $externalAttachment ? 'studio-attachment__icon--link' : '' }}"><i class="fa {{ $externalAttachment ? 'fa-link' : 'fa-file-o' }}" aria-hidden="true"></i></span>
                    <span class="studio-attachment__copy"><strong>{{ $pdf['title'] }}</strong><small>{{ $attachmentMeta }}</small></span>
                    <span class="studio-attachment__actions">
                        <a href="{{ $pdf['preview_url'] }}" target="_blank" rel="noopener" aria-label="فتح المرفق"><i class="fa fa-eye" aria-hidden="true"></i></a>
                        <button type="button" data-studio-pdf-toggle="{{ $pdf['id'] }}" aria-label="{{ $pdf['is_active'] ? 'إخفاء المرفق' : 'إظهار المرفق' }}"><i class="fa {{ $pdf['is_active'] ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i></button>
                        <button type="button" data-studio-pdf-edit="{{ $pdf['id'] }}" aria-label="تعديل المرفق"><i class="fa fa-pencil" aria-hidden="true"></i></button>
                    </span>
                </article>
            @endforeach
        </div>
        <div class="studio-attachments-empty" id="studioCoursePdfEmpty" @if($coursePdfs->isNotEmpty()) hidden @endif>لا توجد مرفقات حتى الآن</div>
        <button type="button" class="studio-attachments-add" data-studio-pdf-add><i class="fa fa-plus" aria-hidden="true"></i> إضافة مرفق</button>

        <div class="studio-attachment-editor" id="studioCoursePdfEditor" hidden>
            <form action="{{ $coursePdfStoreUrl }}" method="POST" enctype="multipart/form-data" id="coursePdfForm">
                @csrf
                <input type="hidden" name="authoring_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
                <input type="hidden" name="_method" value="POST" disabled>
                <div class="studio-inline-feedback" data-pdf-feedback role="status" aria-live="polite" hidden></div>
                @include('admin.course-pdfs.partials.form', ['pdf' => null, 'maxOrder' => $coursePdfMaxOrder])
                <button type="button" class="studio-inline-danger" data-studio-pdf-delete hidden><i class="fa fa-trash" aria-hidden="true"></i> حذف المرفق</button>
            </form>
        </div>
    </div>
</section>
@endif
