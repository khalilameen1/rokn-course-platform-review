@php
    $courseEditorFormId = $formId;
    $courseEditorProgressId = $progressId ?? null;
    $courseEditorChangesId = $changesId ?? null;
    $courseEditorImageStatus = $imageStatus ?? 'تم اختيار الصورة';
@endphp
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById(@json($courseEditorFormId));
    if (!form) return;

    const progressBar = @json($courseEditorProgressId)
        ? document.getElementById(@json($courseEditorProgressId))
        : null;
    const changesIndicator = @json($courseEditorChangesId)
        ? document.getElementById(@json($courseEditorChangesId))
        : null;
    const ignoredFingerprintFields = new Set([
        '_token', '_method', 'authoring_version', 'authoring_request_id', 'authoring_draft_receipt',
    ]);

    const fingerprint = () => {
        const rows = [];
        form.querySelectorAll('input, select, textarea').forEach(field => {
            if (!field.name || ignoredFingerprintFields.has(field.name) || field.disabled) return;
            if (field.type === 'file') {
                const file = field.files?.[0];
                rows.push([field.name, file ? `${file.name}:${file.size}:${file.lastModified}` : '']);
                return;
            }
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
            if (field.multiple) {
                rows.push([field.name, Array.from(field.selectedOptions).map(option => option.value).join('\u001f')]);
                return;
            }
            rows.push([field.name, field.value]);
        });
        return JSON.stringify(rows);
    };
    const originalFingerprint = fingerprint();

    const refreshProgress = () => {
        if (!progressBar) return;
        const required = Array.from(form.querySelectorAll('[required]'));
        const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]), select, textarea'));
        const hasValue = field => field.type === 'checkbox' || field.type === 'radio'
            ? field.checked
            : String(field.value || '').trim() !== '';
        const requiredRatio = required.length ? required.filter(hasValue).length / required.length : 1;
        const completionRatio = fields.length ? fields.filter(hasValue).length / fields.length : 1;
        progressBar.style.width = `${Math.round((requiredRatio * 0.7 + completionRatio * 0.3) * 100)}%`;
    };

    const refreshState = () => {
        changesIndicator?.classList.toggle('show', fingerprint() !== originalFingerprint);
        refreshProgress();
    };

    if (window.jQuery?.fn?.select2) {
        window.jQuery(form).find('.select2').select2({placeholder: 'اختر التصنيفات', allowClear: true, dir: 'rtl'});
    }

    form.querySelectorAll('.checkbox-item').forEach(item => {
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (!checkbox) return;
        const syncCard = () => item.classList.toggle('selected', checkbox.checked);
        syncCard();
        checkbox.addEventListener('change', syncCard);
    });

    const fileInput = form.querySelector('#image');
    const uploadArea = form.querySelector('.file-upload-area');
    const imagePreview = form.querySelector('#imagePreview');
    const showImage = file => {
        if (!fileInput || !imagePreview || !file) return;
        const maximumBytes = Number(fileInput.dataset.maxBytes || 0);
        if (!file.type.startsWith('image/')) {
            window.alert('اختر ملف صورة صحيحًا');
            fileInput.value = '';
            imagePreview.replaceChildren();
            refreshState();
            return;
        }
        if (maximumBytes && file.size > maximumBytes) {
            window.alert('حجم الصورة أكبر من الحد المسموح');
            fileInput.value = '';
            imagePreview.replaceChildren();
            refreshState();
            return;
        }
        const reader = new FileReader();
        reader.addEventListener('load', event => {
            const image = document.createElement('img');
            image.src = String(event.target?.result || '');
            image.alt = 'معاينة الصورة';
            image.className = 'image-preview';
            const status = document.createElement('div');
            status.className = 'course-editor__preview-status';
            status.textContent = @json($courseEditorImageStatus);
            imagePreview.replaceChildren(image, status);
        }, {once: true});
        reader.readAsDataURL(file);
        refreshState();
    };

    fileInput?.addEventListener('change', () => showImage(fileInput.files?.[0]));
    if (fileInput && uploadArea) {
        uploadArea.addEventListener('dragover', event => {
            event.preventDefault();
            uploadArea.classList.add('dragover');
        });
        uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
        uploadArea.addEventListener('drop', event => {
            event.preventDefault();
            uploadArea.classList.remove('dragover');
            const file = event.dataTransfer?.files?.[0];
            if (!file) return;
            try {
                fileInput.files = event.dataTransfer.files;
            } catch (_) {
                fileInput.click();
                return;
            }
            showImage(file);
        });
    }

    form.querySelectorAll('input, select, textarea').forEach(field => {
        field.addEventListener('input', refreshState);
        field.addEventListener('change', refreshState);
    });
    refreshProgress();
});
</script>
