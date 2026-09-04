@if(count($errors) > 0)
    <div class="enhanced-alert enhanced-alert-error" role="alert">
        <div class="alert-icon">
            <i class="fa fa-exclamation-circle"></i>
        </div>
        <div class="alert-content">
            <h6 class="alert-title">حدثت أخطاء في النموذج</h6>
            <ul class="alert-list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        <button type="button" class="alert-close" onclick="closeAlert(this)" aria-label="إغلاق رسالة الخطأ">
            <i class="fa fa-times"></i>
        </button>
        <div class="alert-progress"></div>
    </div>
@elseif(session()->has('error'))
    <div class="enhanced-alert enhanced-alert-error" role="alert">
        <div class="alert-icon">
            <i class="fa fa-exclamation-circle"></i>
        </div>
        <div class="alert-content">
            <h6 class="alert-title">خطأ</h6>
            <p class="alert-message">{{ session('error') }}</p>
        </div>
        <button type="button" class="alert-close" onclick="closeAlert(this)" aria-label="إغلاق رسالة الخطأ">
            <i class="fa fa-times"></i>
        </button>
        <div class="alert-progress"></div>
    </div>
@elseif(session()->has('success'))
    <div class="enhanced-alert enhanced-alert-success" role="alert">
        <div class="alert-icon">
            <i class="fa fa-check-circle"></i>
        </div>
        <div class="alert-content">
            <h6 class="alert-title">تم بنجاح</h6>
            <p class="alert-message">{{ session('success') }}</p>
        </div>
        <button type="button" class="alert-close" onclick="closeAlert(this)" aria-label="إغلاق رسالة النجاح">
            <i class="fa fa-times"></i>
        </button>
        <div class="alert-progress"></div>
    </div>
@endif


<script>
function closeAlert(button) {
    const alert = button.closest('.enhanced-alert');
    if (alert) {
        alert.classList.add('is-closing');
        setTimeout(() => {
            alert.remove();
        }, 400);
    }
}

// Success feedback may clear itself. Validation and operation failures stay
// visible until the editor dismisses them; long dashboard forms must not lose
// the reason a save failed while the user scrolls back to the relevant field.
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.enhanced-alert-success');
    alerts.forEach(alert => {
        // Add auto-hide class for animation
        alert.classList.add('auto-hide');

        // Remove alert after animation completes
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 4500);
    });
});
</script>
