<script>
function showFullScreenshot(imageUrl) {
    // Update image source
    document.getElementById('fullScreenshotImage').src = imageUrl;
    document.getElementById('downloadFullScreenshot').href = imageUrl;

    // Try multiple methods to show modal
    try {
        // Method 1: Bootstrap 4 jQuery
        if (typeof $ !== 'undefined' && $.fn.modal) {
            $('#screenshotModal').modal('show');
            return;
        }

        // Method 2: Bootstrap 5 vanilla JS
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modalEl = document.getElementById('screenshotModal');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            return;
        }

        // Method 3: Direct DOM manipulation
        const modal = document.getElementById('screenshotModal');
        modal.classList.add('show');
        modal.style.display = 'block';
        modal.setAttribute('aria-modal', 'true');
        modal.removeAttribute('aria-hidden');

        // Add backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.id = 'modalBackdrop';
        document.body.appendChild(backdrop);
        document.body.classList.add('modal-open');

        // Close on backdrop click
        backdrop.onclick = function() {
            closeShowModal();
        };

        // Close button functionality
        modal.querySelectorAll('[data-dismiss="modal"]').forEach(btn => {
            btn.onclick = function() {
                closeShowModal();
            };
        });

    } catch(e) {
        alert('خطأ في فتح الصورة');
    }
}

function closeShowModal() {
    const modal = document.getElementById('screenshotModal');
    const backdrop = document.getElementById('modalBackdrop');

    modal.classList.remove('show');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('aria-modal');

    if (backdrop) {
        backdrop.remove();
    }
    document.body.classList.remove('modal-open');
}
</script>
