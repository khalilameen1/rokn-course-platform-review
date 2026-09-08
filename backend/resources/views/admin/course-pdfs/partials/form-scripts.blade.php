<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('pdfFile');
    const filePreview = document.getElementById('filePreview');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const removeFile = document.getElementById('removeFile');
    if (!dropZone || !fileInput || !filePreview || !fileName || !fileSize || !removeFile) return;
    const form = fileInput.form;
    const sourceInput = form.elements.source_type;
    const platformInput = form.elements.platform;
    const externalInput = form.elements.external_url;
    const uploadFields = form.querySelector('[data-attachment-upload-fields]');
    const externalFields = form.querySelector('[data-attachment-external-fields]');
    const deliveryHint = form.querySelector('[data-attachment-delivery-hint]');
    const fileHelp = form.querySelector('[data-studio-pdf-file-help]');
    const fileRequired = form.querySelector('[data-studio-pdf-file-required]');

    const formatFileSize = bytes => {
        if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(2)} GB`;
        if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(2)} MB`;
        if (bytes >= 1024) return `${(bytes / 1024).toFixed(2)} KB`;
        return `${bytes} bytes`;
    };
    const showFilePreview = file => {
        fileName.textContent = file.name;
        fileSize.textContent = formatFileSize(file.size);
        filePreview.classList.add('show');
        const extensions = fileInput.accept.toLowerCase().split(',').map(value => value.trim()).filter(value => value.startsWith('.'));
        const supported = extensions.some(extension => file.name.toLowerCase().endsWith(extension));
        fileInput.setCustomValidity(!supported ? 'اختر مستندًا أو صورة أو ملف ZIP من الأنواع المتاحة' : file.size > 50 * 1024 * 1024 ? 'الحد الأقصى للملف هو 50 ميجابايت' : '');
    };
    const hideFilePreview = () => {
        filePreview.classList.remove('show');
        fileInput.value = '';
        fileInput.setCustomValidity('');
        fileName.textContent = '';
        fileSize.textContent = '';
    };
    const syncAttachmentMode = () => {
        if (!sourceInput || !platformInput || !externalInput) return;
        const external = sourceInput.value === 'external';
        const computer = platformInput.value === 'computer';
        const needsUpload = !external && form.dataset.hasUploadedFile !== '1';
        uploadFields.hidden = external;
        externalFields.hidden = !external;
        fileInput.disabled = external;
        fileInput.required = needsUpload;
        externalInput.disabled = !external;
        externalInput.required = external;
        if (fileRequired) fileRequired.hidden = !needsUpload;
        if (fileHelp) fileHelp.textContent = needsUpload
            ? 'اسحب الملف هنا أو انقر للاختيار'
            : 'اختر ملفًا جديدًا للاستبدال أو اتركه كما هو';
        if (deliveryHint) deliveryHint.textContent = computer
            ? 'سينسخ الطالب الرابط لفتحه على الكمبيوتر'
            : external
                ? 'استخدم رابط ملف متاح للتنزيل دون تسجيل دخول'
                : 'سيحفظ الطالب الملف على الهاتف';
    };
    sourceInput?.addEventListener('change', () => {
        if (sourceInput.value === 'external') hideFilePreview();
        else if (externalInput) externalInput.value = '';
        syncAttachmentMode();
    });
    platformInput?.addEventListener('change', syncAttachmentMode);
    form.addEventListener('rokn:attachment-context', () => {
        hideFilePreview();
        syncAttachmentMode();
    });
    form.addEventListener('reset', () => queueMicrotask(syncAttachmentMode));
    syncAttachmentMode();

    fileInput.addEventListener('change', () => {
        if (fileInput.disabled) return hideFilePreview();
        if (fileInput.files.length > 0) showFilePreview(fileInput.files[0]);
        else hideFilePreview();
    });
    dropZone.addEventListener('dragover', event => {
        event.preventDefault();
        if (fileInput.disabled) return;
        dropZone.classList.add('dragover');
    });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', event => {
        event.preventDefault();
        dropZone.classList.remove('dragover');
        if (fileInput.disabled) return;
        if (event.dataTransfer.files.length > 0) {
            const transfer = new DataTransfer();
            transfer.items.add(event.dataTransfer.files[0]);
            fileInput.files = transfer.files;
            fileInput.dispatchEvent(new Event('change', {bubbles: true}));
        }
    });
    removeFile.addEventListener('click', hideFilePreview);

    const isActive = document.getElementById('isActive');
    const status = document.getElementById('statusBadge');
    if (!isActive || !status) return;
    const syncStatus = () => {
        status.textContent = isActive.checked ? 'مفعّل' : 'غير مفعّل';
        status.classList.toggle('active', isActive.checked);
        status.classList.toggle('inactive', !isActive.checked);
    };
    isActive.addEventListener('change', syncStatus);
    syncStatus();
});
</script>
