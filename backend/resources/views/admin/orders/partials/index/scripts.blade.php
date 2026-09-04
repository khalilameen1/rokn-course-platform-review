<script>
// Toggle filter section - Like course codes
function toggleFilterSection() {
    const filterBody = document.getElementById('filter-section-body');
    const toggleIcon = document.getElementById('filter-toggle-icon');

    filterBody.classList.toggle('show');
    toggleIcon.classList.toggle('rotated');

    // Save state to localStorage
    localStorage.setItem('filterSectionOpen', filterBody.classList.contains('show'));
}

// Restore filter section state on page load
document.addEventListener('DOMContentLoaded', function() {
    const filterBody = document.getElementById('filter-section-body');
    const toggleIcon = document.getElementById('filter-toggle-icon');
    const isOpen = localStorage.getItem('filterSectionOpen');

    // Open by default if there are active filters or if previously opened
    const hasActiveFilters = {{ request()->hasAny(['state', 'payment_method', 'user_search', 'course_search', 'date_from', 'date_to', 'amount_min', 'amount_max']) ? 'true' : 'false' }};
    if (isOpen === 'true' || hasActiveFilters) {
        filterBody.classList.add('show');
        toggleIcon.classList.add('rotated');
    }
});

function showPaymentScreenshot(imageUrl) {
    // Update image source
    document.getElementById('paymentScreenshotImage').src = imageUrl;
    document.getElementById('downloadScreenshot').href = imageUrl;

    // Try multiple methods to show modal
    try {
        // Method 1: Bootstrap 4 jQuery
        if (typeof $ !== 'undefined' && $.fn.modal) {
            $('#paymentScreenshotModal').modal('show');
            return;
        }

        // Method 2: Bootstrap 5 vanilla JS
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modalEl = document.getElementById('paymentScreenshotModal');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            return;
        }

        // Method 3: Direct DOM manipulation
        const modal = document.getElementById('paymentScreenshotModal');
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
            closeModal();
        };

        // Close button functionality
        modal.querySelectorAll('[data-dismiss="modal"]').forEach(btn => {
            btn.onclick = function() {
                closeModal();
            };
        });

    } catch(e) {
        alert('خطأ في فتح الصورة');
    }
}

function closeModal() {
    const modal = document.getElementById('paymentScreenshotModal');
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
