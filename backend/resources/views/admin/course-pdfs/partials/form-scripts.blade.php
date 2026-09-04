<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('pdfFile');
    const filePreview = document.getElementById('filePreview');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const removeFile = document.getElementById('removeFile');
    if (!dropZone || !fileInput || !filePreview || !fileName || !fileSize || !removeFile) return;

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
    };
    const hideFilePreview = () => {
        filePreview.classList.remove('show');
        fileInput.value = '';
    };

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) showFilePreview(fileInput.files[0]);
    });
    dropZone.addEventListener('dragover', event => {
        event.preventDefault();
        dropZone.classList.add('dragover');
    });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', event => {
        event.preventDefault();
        dropZone.classList.remove('dragover');
        if (event.dataTransfer.files.length > 0) {
            fileInput.files = event.dataTransfer.files;
            showFilePreview(event.dataTransfer.files[0]);
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
