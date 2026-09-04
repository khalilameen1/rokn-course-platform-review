@extends('admin.layouts.app')

@section('page.title', 'إعدادات التصميم')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/design-settings.css') }}">
@endsection

@section('content')

<div class="admin-page design-settings-wrapper">
    <div class="page-header-design">
        <div class="header-icon-wrapper">
            <i class="fa fa-paint-brush"></i>
        </div>
        <div>
            <h1>إعدادات التصميم</h1>
            <p>قم بتخصيص مظهر وتصميم منصتك بالكامل</p>
        </div>
    </div>

    <div class="design-card">
        <form action="{{ route('admin.design-settings.store') }}" method="POST" enctype="multipart/form-data" id="designSettingsForm">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">

            <div class="accordion-design">
                <!-- Basic Settings Section -->
                <div class="accordion-item-design">
                    <div class="accordion-header-design">
                        <button type="button" class="accordion-button-design active" onclick="toggleAccordion(this, 'basic')">
                            <div class="icon-circle">
                                <i class="fa fa-info-circle"></i>
                            </div>
                            <span>الإعدادات الأساسية والشعارات</span>
                            <i class="fa fa-chevron-down arrow-icon"></i>
                        </button>
                    </div>
                    <div class="accordion-content-design show" id="basic">
                        <div class="accordion-body-design">
                            <div class="form-row-design">
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-text-width"></i>
                                        اسم المنصة (عربي)
                                    </label>
                                    <input type="text" name="name_ar" class="form-control-design"
                                           value="{{ $settings->name_ar }}" required placeholder="أدخل اسم المنصة بالعربي">
                                </div>
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-text-width"></i>
                                        اسم المنصة (إنجليزي)
                                    </label>
                                    <input type="text" name="name_en" class="form-control-design"
                                           value="{{ $settings->name_en }}" required placeholder="Enter platform name in English">
                                </div>
                            </div>

                            <div class="form-row-design">
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-quote-right"></i>
                                        الشعار الأول (عربي)
                                    </label>
                                    <input type="text" name="slogan_1_ar" class="form-control-design"
                                           value="{{ $settings->slogan_1_ar }}" placeholder="الشعار الأول بالعربي">
                                </div>
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-quote-right"></i>
                                        الشعار الأول (إنجليزي)
                                    </label>
                                    <input type="text" name="slogan_1_en" class="form-control-design"
                                           value="{{ $settings->slogan_1_en }}" placeholder="First slogan in English">
                                </div>
                            </div>

                            <div class="form-row-design">
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-quote-right"></i>
                                        الشعار الثاني (عربي)
                                    </label>
                                    <input type="text" name="slogan_2_ar" class="form-control-design"
                                           value="{{ $settings->slogan_2_ar }}" placeholder="الشعار الثاني بالعربي">
                                </div>
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-quote-right"></i>
                                        الشعار الثاني (إنجليزي)
                                    </label>
                                    <input type="text" name="slogan_2_en" class="form-control-design"
                                           value="{{ $settings->slogan_2_en }}" placeholder="Second slogan in English">
                                </div>
                            </div>

                            <div class="form-row-design">
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-quote-right"></i>
                                        الشعار الثالث (عربي)
                                    </label>
                                    <input type="text" name="slogan_3_ar" class="form-control-design"
                                           value="{{ $settings->slogan_3_ar }}" placeholder="الشعار الثالث بالعربي">
                                </div>
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-quote-right"></i>
                                        الشعار الثالث (إنجليزي)
                                    </label>
                                    <input type="text" name="slogan_3_en" class="form-control-design"
                                           value="{{ $settings->slogan_3_en }}" placeholder="Third slogan in English">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branding Section -->
                <div class="accordion-item-design">
                    <div class="accordion-header-design">
                        <button type="button" class="accordion-button-design" onclick="toggleAccordion(this, 'branding')">
                            <div class="icon-circle">
                                <i class="fa fa-image"></i>
                            </div>
                            <span>الشعار والأيقونة</span>
                            <i class="fa fa-chevron-down arrow-icon"></i>
                        </button>
                    </div>
                    <div class="accordion-content-design" id="branding">
                        <div class="accordion-body-design">

                            <div class="form-group-design form-group-design--spacious">
                                <label class="form-label-design">
                                    <i class="fa fa-image"></i>
                                    صورة خلفية الصفحة الرئيسية
                                </label>
                                <div class="image-upload-container">
                                    <div class="upload-area">
                                        <div class="file-input-custom">
                                            <input type="file" name="home_background_file" accept="image/*" data-max-size="2097152">
                                            <div class="file-input-icon">
                                                <i class="fa fa-cloud-upload"></i>
                                            </div>
                                            <div class="file-input-text">انقر لاختيار صورة خلفية الصفحة الرئيسية</div>
                                            <div class="file-input-hint">الحد الأقصى: 2 ميجابايت • JPG, PNG, GIF</div>
                                        </div>
                                        @error('home_background_file')
                                            <span class="text-danger design-error-message">{{ $message }}</span>
                                        @enderror>
                                    </div>
                                    @if($settings->home_background_url)
                                        <div class="current-image-preview">
                                            <label>الصورة الحالية</label>
                                            <img src="{{ $settings->home_background_url }}" alt="Current Home Background">
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="form-group-design form-group-design--spacious">
                                <label class="form-label-design">
                                    <i class="fa fa-image"></i>
                                    شعار المنصة
                                </label>
                                <div class="image-upload-container">
                                    <div class="upload-area">
                                        <div class="file-input-custom">
                                            <input type="file" name="logo_file" accept="image/*" data-max-size="1048576">
                                            <div class="file-input-icon">
                                                <i class="fa fa-cloud-upload"></i>
                                            </div>
                                            <div class="file-input-text">انقر لاختيار شعار المنصة</div>
                                            <div class="file-input-hint">الحد الأقصى: 1 ميجابايت • JPG, PNG, GIF</div>
                                        </div>
                                        @error('logo_file')
                                            <span class="text-danger design-error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    @if($settings->logo_url)
                                        <div class="current-image-preview">
                                            <label>الشعار الحالي</label>
                                            <img src="{{ $settings->logo_url }}" alt="Current Logo">
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="form-group-design">
                                <label class="form-label-design">
                                    <i class="fa fa-image"></i>
                                    أيقونة المنصة (Favicon)
                                </label>
                                <div class="image-upload-container">
                                    <div class="upload-area">
                                        <div class="file-input-custom">
                                            <input type="file" name="icon_file" accept="image/*" data-max-size="1048576">
                                            <div class="file-input-icon">
                                                <i class="fa fa-cloud-upload"></i>
                                            </div>
                                            <div class="file-input-text">انقر لاختيار أيقونة المنصة</div>
                                            <div class="file-input-hint">الحد الأقصى: 1 ميجابايت • JPG, PNG, GIF</div>
                                        </div>
                                        @error('icon_file')
                                            <span class="text-danger design-error-message">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    @if($settings->icon_url)
                                        <div class="current-image-preview">
                                            <label>الأيقونة الحالية</label>
                                            <img src="{{ $settings->icon_url }}" alt="Current Icon">
                                        </div>
                                    @endif
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Colors Section -->
                <div class="accordion-item-design">
                    <div class="accordion-header-design">
                        <button type="button" class="accordion-button-design" onclick="toggleAccordion(this, 'colors')">
                            <div class="icon-circle">
                                <i class="fa fa-paint-brush"></i>
                            </div>
                            <span>ألوان المنصة</span>
                            <i class="fa fa-chevron-down arrow-icon"></i>
                        </button>
                    </div>
                    <div class="accordion-content-design" id="colors">
                        <div class="accordion-body-design">
                            <!-- Color Role Legend -->
                            <div class="color-legend">
                                <h4><i class="fa fa-info-circle"></i> دليل استخدام الألوان</h4>
                                <div class="legend-grid">
                                    <div class="legend-item">
                                        <span class="legend-dot primary"></span>
                                        <div class="legend-text">
                                            <strong>اللون الأساسي (60%)</strong>
                                            <small>الهوية البصرية، الأزرار الرئيسية، العناوين</small>
                                        </div>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-dot secondary"></span>
                                        <div class="legend-text">
                                            <strong>اللون الثانوي (30%)</strong>
                                            <small>العناصر الداعمة، أزرار الإضافة، التأكيدات</small>
                                        </div>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-dot neutral"></span>
                                        <div class="legend-text">
                                            <strong>اللون المحايد (بنية)</strong>
                                            <small>الخلفيات، الحدود، البطاقات</small>
                                        </div>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-dot accent"></span>
                                        <div class="legend-text">
                                            <strong>اللون المميز (5%)</strong>
                                            <small>التنبيهات، الشارات، العناصر المهمة</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row-design">
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-circle"></i>
                                        اللون الأساسي
                                    </label>
                                    <div class="color-picker-wrapper">
                                        <div class="color-preview-box">
                                            <input type="color" name="color_1" id="color_1" value="{{ $settings->color_1 }}" required>
                                            <div class="color-display"></div>
                                        </div>
                                        <span class="color-value-text" id="color_1_text">{{ $settings->color_1 }}</span>
                                    </div>
                                </div>

                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-circle"></i>
                                        اللون الثانوي
                                    </label>
                                    <div class="color-picker-wrapper">
                                        <div class="color-preview-box">
                                            <input type="color" name="color_2" id="color_2" value="{{ $settings->color_2 }}" required>
                                            <div class="color-display"></div>
                                        </div>
                                        <span class="color-value-text" id="color_2_text">{{ $settings->color_2 }}</span>
                                    </div>
                                </div>

                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-circle"></i>
                                        اللون المحايد
                                    </label>
                                    <div class="color-picker-wrapper">
                                        <div class="color-preview-box">
                                            <input type="color" name="color_3" id="color_3" value="{{ $settings->color_3 }}" required>
                                            <div class="color-display"></div>
                                        </div>
                                        <span class="color-value-text" id="color_3_text">{{ $settings->color_3 }}</span>
                                    </div>
                                </div>

                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-circle"></i>
                                        اللون المميز
                                    </label>
                                    <div class="color-picker-wrapper">
                                        <div class="color-preview-box">
                                            <input type="color" name="color_4" id="color_4" value="{{ $settings->color_4 }}" required>
                                            <div class="color-display"></div>
                                        </div>
                                        <span class="color-value-text" id="color_4_text">{{ $settings->color_4 }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Real-time Color Demonstration -->
                            <div class="color-demo-section">
                                <h4 class="demo-section-title">
                                    <i class="fa fa-eye"></i>
                                    معاينة مباشرة للألوان
                                    <span class="demo-hint">شاهد كيف ستظهر الألوان في الواجهة</span>
                                </h4>

                                <div class="demo-modes-container">
                                    <!-- Light Mode Demo -->
                                    <div class="demo-mode-wrapper">
                                        <div class="demo-mode-label">
                                            <i class="fa fa-sun-o"></i>
                                            الوضع الفاتح
                                        </div>
                                        <div class="demo-preview light-mode" id="lightModeDemo">
                                            <!-- Header Section -->
                                            <div class="demo-header">
                                                <div class="demo-header-content">
                                                    <div class="demo-header-text">
                                                        <div class="demo-title">
                                                            <i class="fa fa-play-circle"></i>
                                                            تجربة التعلّم
                                                        </div>
                                                        <div class="demo-subtitle">مقاطع قصيرة بخريطة واضحة</div>
                                                    </div>
                                                    <span class="demo-add-btn" aria-hidden="true">
                                                        <i class="fa fa-plus"></i>
                                                        ابدأ التعلّم
                                                    </span>
                                                </div>

                                                <!-- Stats Cards -->
                                                <div class="demo-stats">
                                                    <div class="demo-stat-card">
                                                        <span class="demo-stat-number">62</span>
                                                        <span class="demo-stat-label">دقيقة</span>
                                                    </div>
                                                    <div class="demo-stat-card">
                                                        <span class="demo-stat-number">4.8</span>
                                                        <span class="demo-stat-label">التقييم</span>
                                                    </div>
                                                    <div class="demo-stat-card">
                                                        <span class="demo-stat-number">1,240</span>
                                                        <span class="demo-stat-label">طالب</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Search Bar -->
                                            <div class="demo-search-section">
                                                <input type="text" class="demo-search-input" placeholder="ابحث عن كورس" readonly>
                                            </div>

                                            <!-- Center Card -->
                                            <div class="demo-card">
                                                <div class="demo-card-header">
                                                    <span class="demo-card-title">أساسيات صناعة المحتوى</span>
                                                    <span class="demo-badge">جديد</span>
                                                </div>
                                                <div class="demo-card-info">
                                                    <span><i class="fa fa-clock-o"></i> 62 دقيقة</span>
                                                    <span><i class="fa fa-star"></i> 4.8 تقييم</span>
                                                </div>
                                                <div class="demo-card-address">
                                                    <i class="fa fa-map-o"></i>
                                                    ثلاث وحدات مرتبة من البداية إلى التطبيق
                                                </div>
                                                <div class="demo-card-actions">
                                                    <span class="demo-btn demo-btn-primary" aria-hidden="true">
                                                        <i class="fa fa-eye"></i>
                                                        شاهد مجانًا
                                                    </span>
                                                    <span class="demo-btn demo-btn-secondary" aria-hidden="true">
                                                        <i class="fa fa-edit"></i>
                                                        تفاصيل الكورس
                                                    </span>
                                                    <span class="demo-btn demo-btn-danger" aria-hidden="true">
                                                        <i class="fa fa-bookmark-o"></i>
                                                        حفظ
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Dark Mode Demo -->
                                    <div class="demo-mode-wrapper">
                                        <div class="demo-mode-label dark">
                                            <i class="fa fa-moon-o"></i>
                                            الوضع الداكن
                                        </div>
                                        <div class="demo-preview dark-mode" id="darkModeDemo">
                                            <!-- Header Section -->
                                            <div class="demo-header">
                                                <div class="demo-header-content">
                                                    <div class="demo-header-text">
                                                        <div class="demo-title">
                                                            <i class="fa fa-play-circle"></i>
                                                            تجربة التعلّم
                                                        </div>
                                                        <div class="demo-subtitle">مقاطع قصيرة بخريطة واضحة</div>
                                                    </div>
                                                    <span class="demo-add-btn" aria-hidden="true">
                                                        <i class="fa fa-plus"></i>
                                                        ابدأ التعلّم
                                                    </span>
                                                </div>

                                                <!-- Stats Cards -->
                                                <div class="demo-stats">
                                                    <div class="demo-stat-card">
                                                        <span class="demo-stat-number">62</span>
                                                        <span class="demo-stat-label">دقيقة</span>
                                                    </div>
                                                    <div class="demo-stat-card">
                                                        <span class="demo-stat-number">4.8</span>
                                                        <span class="demo-stat-label">التقييم</span>
                                                    </div>
                                                    <div class="demo-stat-card">
                                                        <span class="demo-stat-number">1,240</span>
                                                        <span class="demo-stat-label">طالب</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Search Bar -->
                                            <div class="demo-search-section">
                                                <input type="text" class="demo-search-input" placeholder="ابحث عن كورس" readonly>
                                            </div>

                                            <!-- Center Card -->
                                            <div class="demo-card">
                                                <div class="demo-card-header">
                                                    <span class="demo-card-title">أساسيات صناعة المحتوى</span>
                                                    <span class="demo-badge">جديد</span>
                                                </div>
                                                <div class="demo-card-info">
                                                    <span><i class="fa fa-clock-o"></i> 62 دقيقة</span>
                                                    <span><i class="fa fa-star"></i> 4.8 تقييم</span>
                                                </div>
                                                <div class="demo-card-address">
                                                    <i class="fa fa-map-o"></i>
                                                    ثلاث وحدات مرتبة من البداية إلى التطبيق
                                                </div>
                                                <div class="demo-card-actions">
                                                    <span class="demo-btn demo-btn-primary" aria-hidden="true">
                                                        <i class="fa fa-eye"></i>
                                                        شاهد مجانًا
                                                    </span>
                                                    <span class="demo-btn demo-btn-secondary" aria-hidden="true">
                                                        <i class="fa fa-edit"></i>
                                                        تفاصيل الكورس
                                                    </span>
                                                    <span class="demo-btn demo-btn-danger" aria-hidden="true">
                                                        <i class="fa fa-bookmark-o"></i>
                                                        حفظ
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Advanced Settings Section -->
                <div class="accordion-item-design">
                    <div class="accordion-header-design">
                        <button type="button" class="accordion-button-design" onclick="toggleAccordion(this, 'advanced')">
                            <div class="icon-circle">
                                <i class="fa fa-cogs"></i>
                            </div>
                            <span>إعدادات متقدمة</span>
                            <i class="fa fa-chevron-down arrow-icon"></i>
                        </button>
                    </div>
                    <div class="accordion-content-design" id="advanced">
                        <div class="accordion-body-design">
                            <div class="switch-container">
                                <label class="switch-toggle">
                                    <input type="checkbox" name="show_how_platform_works" value="1"
                                           {{ $settings->show_how_platform_works ? 'checked' : '' }}>
                                    <span class="switch-slider"></span>
                                </label>
                                <div class="switch-label">
                                    <i class="fa fa-eye"></i>
                                    عرض قسم "كيف تعمل المنصة" في الموقع التعريفي
                                </div>
                            </div>

                            <div class="form-row-design form-row-design--spaced">
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-heading"></i>
                                        عنوان "كيف تعمل المنصة" (عربي)
                                    </label>
                                    <input type="text" name="how_platform_works_title_ar" class="form-control-design"
                                           value="{{ $settings->how_platform_works_title_ar }}"
                                           placeholder="كيف تعمل المنصة">
                                </div>

                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-heading"></i>
                                        عنوان "كيف تعمل المنصة" (إنجليزي)
                                    </label>
                                    <input type="text" name="how_platform_works_title_en" class="form-control-design"
                                           value="{{ $settings->how_platform_works_title_en }}"
                                           placeholder="How Platform Works">
                                </div>
                            </div>

                            <div class="form-row-design">
                                <div class="form-group-design">
                                    <label class="form-label-design">
                                        <i class="fa fa-video"></i>
                                        رابط فيديو "كيف تعمل المنصة"
                                    </label>
                                    <input type="url" name="how_platform_works_video_link" class="form-control-design"
                                           value="{{ $settings->how_platform_works_video_link }}"
                                           placeholder="https://www.youtube.com/watch...">
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="save-button-wrapper">
                <button type="submit" class="btn-save-design">
                    <i class="fa fa-save"></i>
                    <span>حفظ جميع الإعدادات</span>
                </button>
            </div>
        </form>
        @include('admin.partials.course-authoring-draft', ['formId' => 'designSettingsForm'])
    </div>
</div>

<script>
function toggleAccordion(button, contentId) {
    const content = document.getElementById(contentId);
    const allButtons = document.querySelectorAll('.accordion-button-design');
    const allContents = document.querySelectorAll('.accordion-content-design');

    // Close all other accordions
    allButtons.forEach(btn => {
        if (btn !== button) {
            btn.classList.remove('active');
        }
    });

    allContents.forEach(cnt => {
        if (cnt !== content) {
            cnt.classList.remove('show');
        }
    });

    // Toggle current accordion
    button.classList.toggle('active');
    content.classList.toggle('show');
}

document.addEventListener('DOMContentLoaded', function() {
    // File size validation
    const fileInputs = document.querySelectorAll('input[type="file"][data-max-size]');

    fileInputs.forEach(input => {
        input.addEventListener('change', function() {
            const maxSize = parseInt(this.getAttribute('data-max-size'));
            const file = this.files[0];

            if (file && file.size > maxSize) {
                alert('حجم الملف يجب أن يكون أقل من 1 ميجابايت');
                this.value = '';
                return;
            }

            // Show preview for uploaded image
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                const container = this.closest('.image-upload-container');

                reader.onload = function(e) {
                    // Check if preview already exists
                    let previewDiv = container.querySelector('.current-image-preview');

                    if (!previewDiv) {
                        // Create new preview div
                        previewDiv = document.createElement('div');
                        previewDiv.className = 'current-image-preview';
                        previewDiv.innerHTML = '<label>معاينة الصورة الجديدة</label>';
                        container.appendChild(previewDiv);
                    } else {
                        // Update existing preview label
                        previewDiv.querySelector('label').textContent = 'معاينة الصورة الجديدة';
                    }

                    // Update or create image
                    let img = previewDiv.querySelector('img');
                    if (!img) {
                        img = document.createElement('img');
                        img.alt = 'New Image Preview';
                        previewDiv.appendChild(img);
                    }
                    img.src = e.target.result;
                };

                reader.readAsDataURL(file);
            }
        });
    });

    // Helper function to adjust brightness of a color
    function adjustBrightness(hex, percent) {
        const cleanHex = hex.replace('#', '');
        let r = parseInt(cleanHex.substring(0, 2), 16);
        let g = parseInt(cleanHex.substring(2, 4), 16);
        let b = parseInt(cleanHex.substring(4, 6), 16);

        r = Math.max(0, Math.min(255, r + (r * percent / 100)));
        g = Math.max(0, Math.min(255, g + (g * percent / 100)));
        b = Math.max(0, Math.min(255, b + (b * percent / 100)));

        const toHex = (n) => {
            const hex = Math.round(n).toString(16);
            return hex.length === 1 ? '0' + hex : hex;
        };

        return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
    }

    // Function to update the demo previews with new colors
    function updateDemoColors() {
        const color1 = document.getElementById('color_1').value;
        const color2 = document.getElementById('color_2').value;
        const color3 = document.getElementById('color_3').value;
        const color4 = document.getElementById('color_4').value;

        // Generate gradient for primary color
        const primaryDark = adjustBrightness(color1, -20);
        const primaryGradient = `linear-gradient(135deg, ${color1} 0%, ${primaryDark} 100%)`;

        // Get demo elements
        const lightDemo = document.getElementById('lightModeDemo');
        const darkDemo = document.getElementById('darkModeDemo');

        if (lightDemo && darkDemo) {
            // Update CSS custom properties for both demos
            [lightDemo, darkDemo].forEach(demo => {
                demo.style.setProperty('--demo-primary', color1);
                demo.style.setProperty('--demo-primary-gradient', primaryGradient);
                demo.style.setProperty('--demo-secondary', color2);
                demo.style.setProperty('--demo-neutral', color3);
                demo.style.setProperty('--demo-accent', color4);
            });

            // Update headers
            document.querySelectorAll('.demo-header').forEach(header => {
                header.style.background = primaryGradient;
            });

            // Update add buttons
            document.querySelectorAll('.demo-add-btn').forEach(btn => {
                btn.style.background = color2;
            });

            // Update badges
            document.querySelectorAll('.demo-badge').forEach(badge => {
                badge.style.background = color4;
            });

            // Update primary buttons
            document.querySelectorAll('.demo-btn-primary').forEach(btn => {
                btn.style.background = primaryGradient;
            });

            // Update secondary buttons
            document.querySelectorAll('.demo-btn-secondary').forEach(btn => {
                btn.style.background = color2;
            });

            // Update card top borders
            document.querySelectorAll('.demo-card::before').forEach(card => {
                // This won't work directly, we need to update via CSS variable
            });

            // Update card borders using inline style workaround
            document.querySelectorAll('.demo-card').forEach(card => {
                card.style.borderTopColor = color1;
            });

            // Update address borders
            document.querySelectorAll('.demo-card-address').forEach(addr => {
                addr.style.borderRightColor = color1;
            });

            // Update icons color
            document.querySelectorAll('.demo-card-info i, .demo-card-address i').forEach(icon => {
                icon.style.color = color1;
            });

            // Update legend dots
            const primaryDot = document.querySelector('.legend-dot.primary');
            const secondaryDot = document.querySelector('.legend-dot.secondary');
            const neutralDot = document.querySelector('.legend-dot.neutral');
            const accentDot = document.querySelector('.legend-dot.accent');

            if (primaryDot) primaryDot.style.background = primaryGradient;
            if (secondaryDot) secondaryDot.style.background = color2;
            if (neutralDot) neutralDot.style.background = color3;
            if (accentDot) accentDot.style.background = color4;

            // Add a subtle animation to indicate change
            [lightDemo, darkDemo].forEach(demo => {
                demo.style.transform = 'scale(1.01)';
                setTimeout(() => {
                    demo.style.transform = '';
                }, 200);
            });
        }
    }

    // Color picker updates with real-time preview
    const colorInputs = [
        {input: document.getElementById('color_1'), text: document.getElementById('color_1_text'), display: null},
        {input: document.getElementById('color_2'), text: document.getElementById('color_2_text'), display: null},
        {input: document.getElementById('color_3'), text: document.getElementById('color_3_text'), display: null},
        {input: document.getElementById('color_4'), text: document.getElementById('color_4_text'), display: null}
    ];

    colorInputs.forEach(colorObj => {
        if (colorObj.input && colorObj.text) {
            // Find the color display element (the div that shows the color - it comes AFTER the input)
            colorObj.display = colorObj.input.nextElementSibling;
            if (colorObj.display) {
                colorObj.display.style.background = colorObj.input.value;
            }

            // Update color in real-time as user picks from palette
            colorObj.input.addEventListener('input', function() {
                const newColor = this.value.toUpperCase();
                colorObj.text.textContent = newColor;
                if (colorObj.display) {
                    colorObj.display.style.background = newColor;
                }
                // Update demo previews
                updateDemoColors();
            });

            // Also handle change event for compatibility
            colorObj.input.addEventListener('change', function() {
                const newColor = this.value.toUpperCase();
                colorObj.text.textContent = newColor;
                if (colorObj.display) {
                    colorObj.display.style.background = newColor;
                }
                // Update demo previews
                updateDemoColors();
            });
        }
    });

    // Initialize demo colors on page load
    updateDemoColors();
});
</script>

@endsection
