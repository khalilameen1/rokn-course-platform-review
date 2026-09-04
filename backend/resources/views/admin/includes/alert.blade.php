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
        <button type="button" class="alert-close" data-close-alert aria-label="إغلاق رسالة الخطأ">
            <i class="fa fa-times"></i>
        </button>
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
        <button type="button" class="alert-close" data-close-alert aria-label="إغلاق رسالة الخطأ">
            <i class="fa fa-times"></i>
        </button>
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
        <button type="button" class="alert-close" data-close-alert aria-label="إغلاق رسالة النجاح">
            <i class="fa fa-times"></i>
        </button>
    </div>
@endif
