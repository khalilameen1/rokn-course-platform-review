<script>
function navigateToCourse(event, card) {
    // Don't navigate if clicking on buttons or links
    if (event.target.closest('.btn-card') || event.target.closest('button') || event.target.closest('a')) {
        return;
    }
    
    const url = card.getAttribute('data-url');
    if (url) {
        window.location.href = url;
    }
}

function deleteCourse(courseId, preservesLearnerAccess) {
    const title = preservesLearnerAccess ? 'إخفاء الكورس' : 'أرشفة المسودة';
    const message = preservesLearnerAccess
        ? 'سيتوقف ظهوره والشراء الجديد<br><strong>يستمر الطلاب الحاليون في فتح ما اشتروه</strong>'
        : 'ستُنقل المسودة غير المنشورة إلى الأرشيف<br><strong>يمكنك استعادتها لاحقًا</strong>';
    const action = preservesLearnerAccess ? 'إخفاء الكورس' : 'نقل إلى الأرشيف';
    // Create modern confirmation modal
    const modal = document.createElement('div');
    modal.className = 'delete-confirmation-overlay';
    modal.innerHTML = `
        <div class="delete-confirmation-modal">
            <div class="modal-icon">
                <i class="fa fa-exclamation-triangle"></i>
            </div>
            <h3 class="modal-title">${title}</h3>
            <p class="modal-message">
                ${message}
            </p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="closeDeleteModal()">
                    <i class="fa fa-times"></i>
                    إلغاء
                </button>
                <button class="btn-modal btn-confirm" onclick="confirmDelete(${courseId})">
                    <i class="fa fa-archive"></i>
                    ${action}
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeDeleteModal();
        }
    });
}

function closeDeleteModal() {
    const modal = document.querySelector('.delete-confirmation-overlay');
    if (modal) {
        modal.style.animation = 'fadeOut 0.3s ease-out forwards';
        setTimeout(() => {
            modal.remove();
            document.body.style.overflow = 'auto';
        }, 300);
    }
}

function confirmDelete(courseId) {
    const confirmBtn = document.querySelector('.btn-confirm');

    // Add loading state
    confirmBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> جارٍ النقل...';
    confirmBtn.disabled = true;
    confirmBtn.style.opacity = '0.7';

    document.getElementById('deleteForm' + courseId).submit();
}

</script>
