@php
    // Keep the saved authoring key: a unified design must not rewrite course settings.
    $savedTemplateKey = trim((string) $course?->certificate_text_template_key);
    $selectedTemplateKey = old('certificate_text_template_key', $savedTemplateKey !== ''
        ? $savedTemplateKey : config('certificate.default_text_template_key', 'completion'));
@endphp

<div class="form-group-modern" id="course-certificate-template">
    @include('admin.courses.partials.publishing-area-issues', ['area' => 'certificate'])
    <input type="hidden" name="certificate_text_template_key" value="{{ $selectedTemplateKey }}">
    <h3 class="form-label-modern"><i class="fa fa-certificate label-icon" aria-hidden="true"></i> الشهادة</h3>
    <p class="form-help">تصميم واحد لكل الكورسات باسم المتعلم واسم الكورس</p>
    <p class="form-help">تُضاف «واجتاز مشروعاته» بعد اجتياز مشروعات العبور الموجودة في الكورس</p>
    <p class="form-help">رمز QR يفتح الأعمال المنشورة والمعتمدة إن وجدت وإلا يفتح التحقق من الشهادة</p>
    <a class="btn btn-outline-primary" href="{{ route('admin.courses.certificate-preview', $course) }}" target="_blank" rel="noopener">معاينة الشهادة</a>
    <div class="form-help mt-2">المعاينة من آخر نسخة محفوظة</div>
    @error('certificate_text_template_key')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>

