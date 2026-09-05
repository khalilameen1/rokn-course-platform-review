@php
    $selectedTemplateKey = old(
        'certificate_text_template_key',
        $course?->certificate_text_template_key
    );
    $previewCourseName = trim((string) old('name_ar', $course?->name_ar ?? '')) ?: 'اسم الكورس';
@endphp

<div class="form-group-modern" id="course-certificate-template" data-certificate-template-picker>
    @include('admin.courses.partials.publishing-area-issues', ['area' => 'certificate'])
    <label class="form-label-modern">
        <i class="fa fa-certificate label-icon"></i>
        نص الشهادة
    </label>
    <div class="form-help mb-3">اختر الصياغة الأنسب لطبيعة هذا الكورس قبل حفظه</div>

    @foreach($certificateTextTemplates as $key => $template)
        @php
            $label = is_array($template) ? ($template['label'] ?? $key) : $key;
            $text = is_array($template) ? ($template['text'] ?? '') : (string) $template;
            $description = is_array($template) ? ($template['description'] ?? '') : '';
            $qrDestination = is_array($template) ? ($template['qr_destination'] ?? 'certificate') : 'certificate';
        @endphp
        <label class="border rounded p-3 mb-2 d-block" for="certificate_text_template_key_{{ $key }}">
            <span class="d-flex align-items-start">
                <input
                    class="ml-2 mt-1"
                    type="radio"
                    name="certificate_text_template_key"
                    id="certificate_text_template_key_{{ $key }}"
                    value="{{ $key }}"
                    {{ $selectedTemplateKey === $key ? 'checked' : '' }}
                    required
                >
                <span>
                    <strong class="d-block">{{ $label }}</strong>
                    @if($description !== '')
                        <span class="d-block text-muted">{{ $description }}</span>
                    @endif
                    <span class="d-block mt-2">
                        <span>{{ $text }}</span>
                        <strong data-certificate-preview-course> — {{ $previewCourseName }}</strong>
                    </span>
                    <span class="d-block text-muted mt-1">
                        رمز QR: {{ $qrDestination === 'portfolio' ? 'شاهد الأعمال' : 'تحقق من الشهادة' }}
                    </span>
                </span>
            </span>
        </label>
    @endforeach

    @error('certificate_text_template_key')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const picker = document.querySelector('[data-certificate-template-picker]');
            const courseNameInput = document.querySelector('[name="name_ar"]');
            if (!picker || !courseNameInput) return;

            const updatePreview = function () {
                const courseName = courseNameInput.value.trim() || 'اسم الكورس';
                picker.querySelectorAll('[data-certificate-preview-course]').forEach(function (preview) {
                    preview.textContent = ' — ' + courseName;
                });
            };

            updatePreview();
            courseNameInput.addEventListener('input', updatePreview);
        });
    </script>
@endonce
