@extends('admin.layouts.app')
@section('page.title', 'إعدادات التطبيق')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/settings-dashboard.css') }}">
@endsection

@section('content')

<div class="admin-page settings-wrapper">
    <div class="settings-header">
        <h1>إعدادات التطبيق</h1>
        <p class="settings-header-description">التشغيل والروابط والتكاملات من مكان واحد</p>
    </div>

    <div class="settings-container">
        <div class="settings-card">
            <div class="settings-tabs">
                <button class="settings-tab active" data-tab="general">
                    <i class="fa fa-cog"></i> عام
                </button>
                <button class="settings-tab" data-tab="seo">
                    <i class="fa fa-search"></i> SEO
                </button>
                <button class="settings-tab" data-tab="advanced">
                    <i class="fa fa-sliders"></i> متقدم
                </button>
                <button class="settings-tab" data-tab="integrations">
                    <i class="fa fa-plug"></i> التكاملات
                </button>
                <button class="settings-tab" data-tab="app-links">
                    <i class="fa fa-mobile"></i> التطبيقات والصفحات
                </button>
                <button class="settings-tab" data-tab="wallet-support">
                    <i class="fa fa-whatsapp"></i> الدعم والعملات
                </button>
                <button class="settings-tab" data-tab="rokn-ai">
                    <i class="fa fa-robot"></i> Rokn AI
                </button>
            </div>

            <div class="settings-content">
                {!! Form::model($settings, ['method' => 'POST', 'url' => route('admin.settings.update')]) !!}
                <input type="hidden" name="editor_version" value="{{ $editorVersion }}">

                <!-- General Settings Tab -->
                <div class="tab-pane active" id="general">
                    <h2 class="section-title">
                        <i class="fa fa-info-circle"></i>
                        المعلومات الأساسية
                    </h2>

                    <div class="form-row">
                        <div class="form-group-modern">
                            <label for="site_name_ar">
                                <i class="fa fa-globe"></i> اسم الموقع ولوحة التحكم (عربي)
                            </label>
                            {!! Form::text('site_name_ar', null, ['class' => 'form-control-modern', 'id' => 'site_name_ar', 'placeholder' => 'رُكن']) !!}
                        </div>
                        <div class="form-group-modern">
                            <label for="site_name_en">
                                <i class="fa fa-globe"></i> اسم الموقع ولوحة التحكم (English)
                            </label>
                            {!! Form::text('site_name_en', null, ['class' => 'form-control-modern', 'id' => 'site_name_en', 'placeholder' => 'Rokn']) !!}
                        </div>
                    </div>

                    <div class="helper-text settings-help-panel settings-help-panel--info settings-help-panel--top">
                        <i class="fa fa-info-circle"></i>
                        <span>اسم التطبيق وشعاره اللذان يراهما الطالب يُداران من صفحة هوية التطبيق</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group-modern">
                            <label for="email">
                                <i class="fa fa-envelope"></i> البريد الإلكتروني
                            </label>
                            {!! Form::email('email', null, ['class' => 'form-control-modern', 'id' => 'email', 'placeholder' => 'info@example.com']) !!}
                        </div>
                        <div class="form-group-modern">
                            <label for="phone">
                                <i class="fa fa-phone"></i> رقم الهاتف
                            </label>
                            {!! Form::text('phone', null, ['class' => 'form-control-modern', 'id' => 'phone', 'placeholder' => '+20 10 0000 0000']) !!}
                        </div>
                    </div>

                    <div class="checkbox-modern">
                        {!! Form::hidden('english_translation', 0) !!}
                        {!! Form::checkbox('english_translation', 1, null, ['id' => 'english_translation']) !!}
                        <label for="english_translation">
                            <i class="fa fa-language"></i> تفعيل الترجمة الإنجليزية للمنصة
                        </label>
                    </div>
                </div>

                <!-- SEO Settings Tab -->
                <div class="tab-pane" id="seo">
                    <h2 class="section-title">
                        <i class="fa fa-search"></i>
                        إعدادات تحسين محركات البحث (SEO)
                    </h2>

                    <h3 class="settings-subheading">
                        <i class="fa fa-language"></i> النسخة العربية
                    </h3>

                    <div class="form-row">
                        <div class="form-group-modern settings-grid-full">
                            <label for="seo_meta_title_ar">
                                <i class="fa fa-tag"></i> عنوان الصفحة (Meta Title)
                            </label>
                            {!! Form::text('seo_meta_title_ar', null, ['class' => 'form-control-modern', 'id' => 'seo_meta_title_ar', 'placeholder' => 'عنوان يظهر في نتائج البحث (50-60 حرف)']) !!}
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group-modern settings-grid-full">
                            <label for="seo_meta_description_ar">
                                <i class="fa fa-align-left"></i> وصف الصفحة (Meta Description)
                            </label>
                            {!! Form::textarea('seo_meta_description_ar', null, ['class' => 'form-control-modern', 'id' => 'seo_meta_description_ar', 'rows' => 3, 'placeholder' => 'وصف موجز للمنصة يظهر في نتائج البحث (150-160 حرف)']) !!}
                        </div>
                    </div>

                    <h3 class="settings-subheading settings-subheading--spaced">
                        <i class="fa fa-language"></i> English Version
                    </h3>

                    <div class="form-row">
                        <div class="form-group-modern settings-grid-full">
                            <label for="seo_meta_title_en">
                                <i class="fa fa-tag"></i> Page Title (Meta Title)
                            </label>
                            {!! Form::text('seo_meta_title_en', null, ['class' => 'form-control-modern', 'id' => 'seo_meta_title_en', 'placeholder' => 'Title that appears in search results (50-60 characters)']) !!}
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group-modern settings-grid-full">
                            <label for="seo_meta_description_en">
                                <i class="fa fa-align-left"></i> Page Description (Meta Description)
                            </label>
                            {!! Form::textarea('seo_meta_description_en', null, ['class' => 'form-control-modern', 'id' => 'seo_meta_description_en', 'rows' => 3, 'placeholder' => 'Brief description that appears in search results (150-160 characters)']) !!}
                        </div>
                    </div>
                </div>

                <!-- Advanced Settings Tab -->
                <div class="tab-pane" id="advanced">
                    <h2 class="section-title">
                        <i class="fa fa-shield"></i>
                        سياسة تسجيل دخول الطلاب
                    </h2>

                    <div class="radio-group">
                        <div class="radio-option" data-value="multiple_devices">
                            {!! Form::radio('device_login_policy', 'multiple_devices', null, ['id' => 'device_multiple']) !!}
                            <div class="radio-label">
                                <strong>🌐 أجهزة متعددة</strong>
                                <span>يمكن للطالب تسجيل الدخول من أي عدد من الأجهزة في نفس الوقت. مناسب للمنصات المفتوحة.</span>
                            </div>
                        </div>

                        <div class="radio-option" data-value="single_device">
                            {!! Form::radio('device_login_policy', 'single_device', null, ['id' => 'device_single']) !!}
                            <div class="radio-label">
                                <strong>📱 جهاز واحد في وقت واحد</strong>
                                <span>يمكن للطالب تسجيل الدخول من جهاز واحد فقط، ويمكنه التبديل إلى جهاز آخر عند الحاجة. يوفر توازن بين الأمان والمرونة.</span>
                            </div>
                        </div>

                        <div class="radio-option" data-value="single_device_permanent">
                            {!! Form::radio('device_login_policy', 'single_device_permanent', null, ['id' => 'device_permanent']) !!}
                            <div class="radio-label">
                                <strong>🔒 جهاز واحد دائم</strong>
                                <span>يتم قفل حساب الطالب على أول جهاز يسجل منه الدخول. مناسب للحماية القصوى. يمكن للإدارة إعادة تعيين الجهاز من صفحة الطالب.</span>
                            </div>
                        </div>
                    </div>

                    <h2 class="section-title settings-section-title--wide">
                        <i class="fa fa-graduation-cap"></i>
                        إعدادات الدورات التعليمية
                    </h2>

                    <div class="checkbox-modern">
                        {!! Form::hidden('enforce_course_section_order', 0) !!}
                        {!! Form::checkbox('enforce_course_section_order', 1, null, ['id' => 'enforce_course_section_order']) !!}
                        <label for="enforce_course_section_order">
                            <i class="fa fa-sort-numeric-asc"></i> إجبار الطالب على مشاهدة أقسام الكورس بالترتيب
                        </label>
                    </div>
                    <div class="helper-text settings-help-panel settings-help-panel--info settings-help-panel--top">
                        <i class="fa fa-info-circle"></i>
                        <span>إذا كان مفعلاً، يجب على الطالب إكمال كل قسم قبل الانتقال للقسم التالي. إذا كان غير مفعل، يستطيع الطالب مشاهدة أي قسم بدون ترتيب.</span>
                    </div>
                </div>

                <!-- Integrations Tab -->
                <div class="tab-pane" id="integrations">
                    <h2 class="section-title">
                        <i class="fa fa-video-camera"></i>
                        Bunny.net Video Streaming
                    </h2>

                    <div class="helper-text settings-help-panel settings-help-panel--warning settings-help-panel--bottom">
                        <i class="fa fa-info-circle settings-warning-icon"></i>
                        <span>Bunny.net هي خدمة استضافة فيديو سريعة وآمنة. عند تفعيلها، يمكنك رفع الفيديوهات مباشرة بدلاً من استخدام روابط يوتيوب.</span>
                    </div>

                    <div class="checkbox-modern settings-block-spacing" id="bunny-toggle-wrapper">
                        {!! Form::hidden('bunny_enabled', 0) !!}
                        {!! Form::checkbox('bunny_enabled', 1, null, ['id' => 'bunny_enabled']) !!}
                        <label for="bunny_enabled">
                            <i class="fa fa-toggle-on"></i> تفعيل Bunny.net لاستضافة الفيديو
                        </label>
                    </div>

                    <div id="bunny-settings" class="settings-collapsible{{ $settings->bunny_enabled ? ' is-visible' : '' }}">
                        <div class="form-row">
                            <div class="form-group-modern">
                                <label for="bunny_api_key">
                                    <i class="fa fa-key"></i> API Key
                                </label>
                                {!! Form::password('bunny_api_key', ['class' => 'form-control-modern', 'id' => 'bunny_api_key', 'autocomplete' => 'new-password', 'placeholder' => 'اتركه فارغًا للاحتفاظ بالمفتاح المحفوظ']) !!}
                                <small class="settings-field-help">
                                    يمكنك الحصول على مفتاح API من لوحة تحكم Bunny.net > Stream > API
                                </small>
                            </div>
                            <div class="form-group-modern">
                                <label for="bunny_library_id">
                                    <i class="fa fa-folder"></i> Library ID
                                </label>
                                {!! Form::text('bunny_library_id', null, ['class' => 'form-control-modern', 'id' => 'bunny_library_id', 'placeholder' => 'معرف مكتبة الفيديو']) !!}
                                <small class="settings-field-help">
                                    موجود في إعدادات Video Library
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group-modern settings-grid-full">
                                <label for="bunny_cdn_hostname">
                                    <i class="fa fa-globe"></i> CDN Hostname
                                </label>
                                {!! Form::text('bunny_cdn_hostname', null, ['class' => 'form-control-modern', 'id' => 'bunny_cdn_hostname', 'placeholder' => 'مثال: vz-abc123.b-cdn.net']) !!}
                                <small class="settings-field-help">
                                    اسم نطاق Stream المرتبط بالمكتبة بدون https أو أي مسار
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group-modern">
                                <label for="bunny_storage_zone_name"><i class="fa fa-database"></i> Storage Zone</label>
                                {!! Form::text('bunny_storage_zone_name', null, ['class' => 'form-control-modern', 'id' => 'bunny_storage_zone_name']) !!}
                            </div>
                            <div class="form-group-modern">
                                <label for="bunny_storage_password"><i class="fa fa-lock"></i> Storage Password</label>
                                {!! Form::password('bunny_storage_password', ['class' => 'form-control-modern', 'id' => 'bunny_storage_password', 'autocomplete' => 'new-password', 'placeholder' => 'اتركه فارغًا للاحتفاظ بالمفتاح المحفوظ']) !!}
                            </div>
                            <div class="form-group-modern">
                                <label for="bunny_security_key"><i class="fa fa-shield-alt"></i> Token Authentication Key</label>
                                {!! Form::password('bunny_security_key', ['class' => 'form-control-modern', 'id' => 'bunny_security_key', 'autocomplete' => 'new-password', 'placeholder' => 'اتركه فارغًا للاحتفاظ بالمفتاح المحفوظ']) !!}
                                <small class="text-muted">مفتاح توقيع روابط العرض، وليس API Key.</small>
                            </div>
                        </div>

                        <div class="settings-inline-actions">
                            <button type="button" id="test-bunny-connection" class="btn-modern settings-test-button">
                                <i class="fa fa-plug"></i>
                                اختبار الاتصال
                            </button>
                            <span id="bunny-test-result" class="settings-test-result"></span>
                        </div>
                    </div>

                    <div class="settings-cleanup-panel">
                        <h3 class="settings-cleanup-title">تنظيف فيديوهات Bunny بأمان</h3>
                        <p class="settings-cleanup-description">
                            تُنظف الرفوعات المتروكة تلقائيًا بعد فترة الاحتفاظ. ويعيد العامل فحص ارتباط الفيديو بأي مقطع قبل الحذف.
                        </p>
                        <div class="settings-cleanup-filters">
                            <span class="badge badge-warning">بانتظار المراجعة: {{ $bunnyCleanupStats['pending_review'] }}</span>
                            <span class="badge badge-info">معتمد: {{ $bunnyCleanupStats['approved'] }}</span>
                            <span class="badge badge-success">تم تنظيفه: {{ $bunnyCleanupStats['deleted'] }}</span>
                        </div>

                        <div class="settings-cleanup-actions">
                            <a href="{{ route('admin.settings', ['cleanup_filter' => 'verified']) }}#integrations" class="btn btn-sm {{ $cleanupFilter === 'verified' ? 'btn-primary' : 'btn-outline-primary' }}">مرشحات موثقة</a>
                            <a href="{{ route('admin.settings', ['cleanup_filter' => 'failed']) }}#integrations" class="btn btn-sm {{ $cleanupFilter === 'failed' ? 'btn-primary' : 'btn-outline-primary' }}">بها خطأ</a>
                            <a href="{{ route('admin.settings', ['cleanup_filter' => 'all']) }}#integrations" class="btn btn-sm {{ $cleanupFilter === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">الكل</a>
                            <button
                                type="submit"
                                formaction="{{ route('admin.settings.bunny-cleanup.approve-batch') }}"
                                formmethod="POST"
                                class="btn btn-sm btn-outline-danger"
                                onclick="return confirmSelectedBunnyCleanup()"
                            >اعتماد المحدد</button>
                        </div>

                        @if($bunnyCleanupCandidates->isEmpty())
                            <div class="settings-cleanup-empty">لا توجد فيديوهات في قائمة التنظيف.</div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm settings-cleanup-table">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" id="cleanup-select-all" aria-label="تحديد الكل"></th>
                                            <th>Video GUID</th>
                                            <th>السبب</th>
                                            <th>موعد الأهلية</th>
                                            <th>المحاولات</th>
                                            <th>الحالة</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($bunnyCleanupCandidates as $cleanupCandidate)
                                            <tr>
                                                <td>
                                                    @if(!$cleanupCandidate->remote_deleted_at && !$cleanupCandidate->reviewed_at)
                                                        <input type="checkbox" class="cleanup-candidate-checkbox" name="cleanup_ids[]" value="{{ $cleanupCandidate->id }}" aria-label="تحديد {{ $cleanupCandidate->video_guid }}">
                                                    @endif
                                                </td>
                                                <td><code>{{ $cleanupCandidate->video_guid }}</code></td>
                                                <td>{{ $cleanupCandidate->reason }}</td>
                                                <td>{{ optional($cleanupCandidate->eligible_after)->format('Y-m-d H:i') }}</td>
                                                <td>{{ (int) $cleanupCandidate->attempts }}</td>
                                                <td>
                                                    @if($cleanupCandidate->remote_deleted_at)
                                                        <span class="text-success">تم التنظيف</span>
                                                    @elseif($cleanupCandidate->reviewed_at)
                                                        <span class="text-info">معتمد وينتظر العامل</span>
                                                    @else
                                                        <span class="text-warning">يحتاج مراجعة</span>
                                                    @endif
                                                    @if($cleanupCandidate->last_error)
                                                        <small class="text-danger settings-cleanup-error">{{ $cleanupCandidate->last_error }}</small>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if(!$cleanupCandidate->remote_deleted_at && !$cleanupCandidate->reviewed_at)
                                                        <button
                                                            type="submit"
                                                            formaction="{{ route('admin.settings.bunny-cleanup.approve', $cleanupCandidate) }}"
                                                            formmethod="POST"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('اعتماد حذف هذا الفيديو من Bunny بعد فترة الاحتفاظ؟')"
                                                        >اعتماد التنظيف</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- App Links and Pages Tab -->
                <div class="tab-pane" id="app-links">
                    <h2 class="section-title">
                        <i class="fa fa-mobile"></i>
                        روابط التطبيقات والصفحات
                    </h2>

                    <div class="alert alert-info mb-4">
                        روابط Google Play وApp Store والنسخة المباشرة تُدار من
                        <a href="{{ route('admin.app-versions.index') }}">إصدارات التطبيق</a>
                    </div>

                </div>

                <div class="tab-pane" id="wallet-support">
                    <h2 class="section-title">
                        <i class="fa fa-percent"></i>
                        قناة الشراء المباشر
                    </h2>

                    <div class="form-row">
                        <div class="form-group-modern settings-grid-full">
                            <label for="direct_checkout_discount_percent">خصم نسخة Android المباشرة</label>
                            {!! Form::number('direct_checkout_discount_percent', $settings->direct_checkout_discount_percent ?? 10, ['class' => 'form-control-modern', 'id' => 'direct_checkout_discount_percent', 'min' => 0, 'max' => 50, 'step' => '0.01', 'required']) !!}
                            <small class="text-muted">يُطبّق على كاشير فقط وتظل أسعار Google Play وApp Store من المتجرين</small>
                            @error('direct_checkout_discount_percent')<small class="text-danger">{{ $message }}</small>@enderror
                        </div>
                    </div>

                    <h2 class="section-title settings-section-title--spaced">
                        <i class="fa fa-share-alt"></i>
                        روابط المنصة
                    </h2>

                    <div class="form-row">
                        @foreach([
                            'facebook_url' => ['فيسبوك', 'https://facebook.com/...'],
                            'instagram_url' => ['إنستجرام', 'https://instagram.com/...'],
                            'tiktok_url' => ['تيك توك', 'https://tiktok.com/@...'],
                            'youtube_url' => ['يوتيوب', 'https://youtube.com/@...'],
                            'telegram_url' => ['تليجرام', 'https://t.me/...'],
                            'whatsapp_url' => ['واتساب المنصة', '+201001234567 أو https://wa.me/201001234567'],
                        ] as $field => [$label, $placeholder])
                            <div class="form-group-modern">
                                <label for="{{ $field }}">{{ $label }}</label>
                                @if($field === 'whatsapp_url')
                                    {!! Form::text($field, old($field, $designSettings->{$field}), ['class' => 'form-control-modern', 'id' => $field, 'placeholder' => $placeholder]) !!}
                                @else
                                    {!! Form::url($field, old($field, $designSettings->{$field}), ['class' => 'form-control-modern', 'id' => $field, 'placeholder' => $placeholder]) !!}
                                @endif
                                @error($field)<small class="text-danger">{{ $message }}</small>@enderror
                            </div>
                        @endforeach
                    </div>

                    <h2 class="section-title">
                        <i class="fa fa-whatsapp"></i>
                        دعم واتساب
                    </h2>

                    <div class="form-row">
                        <div class="form-group-modern settings-grid-full">
                            <label for="support_whatsapp_url">
                                <i class="fa fa-whatsapp"></i> رقم أو رابط واتساب الدعم
                            </label>
                            {!! Form::text('support_whatsapp_url', null, ['class' => 'form-control-modern', 'id' => 'support_whatsapp_url', 'placeholder' => '+201001234567 أو https://wa.me/201001234567']) !!}
                            <small class="text-muted">يفتح هذا الرقم عند ضغط المستخدم على الدعم</small>
                            @error('support_whatsapp_url')<small class="text-danger">{{ $message }}</small>@enderror
                        </div>
                    </div>

                    <div class="helper-text settings-help-panel settings-help-panel--info settings-help-panel--bottom">
                        <i class="fa fa-balance-scale"></i>
                        <span>
                            الروابط القانونية الرسمية:
                            <a href="{{ route('privacy') }}" target="_blank" rel="noopener">الخصوصية</a>
                            · <a href="{{ route('terms') }}" target="_blank" rel="noopener">شروط الاستخدام</a>
                            · <a href="{{ route('returns-policy') }}" target="_blank" rel="noopener">سياسة الاسترداد</a>
                            · <a href="{{ route('account-deletion.show') }}" target="_blank" rel="noopener">طلب حذف الحساب</a>
                        </span>
                    </div>

                    <h2 class="section-title settings-section-title--spaced">
                        <i class="fa fa-gift"></i>
                        اقتصاد المكافآت
                    </h2>
                    <div class="helper-text settings-help-panel settings-help-panel--info settings-help-panel--bottom">
                        <i class="fa fa-coins"></i>
                        <span>
                            تجمّعت مكافآت التسجيل والفتح اليومي والاستمرارية والدراسة وأول مشروع وإنهاء الكورس في
                            <a href="{{ route('admin.coin-earning-methods.index') }}">صفحة ربح العملات</a>
                            حتى لا يتكرر نفس التحكم في مكانين.
                        </span>
                    </div>
                </div>

                <div class="tab-pane" id="rokn-ai">
                    @php
                        $aiTierDefaults = (array) config('course_plans.ai_tiers');
                        $aiPlanPolicy = old('ai_plan_policy', $settings->ai_plan_policy ?: [
                            'basic' => ['chat_enabled' => false, 'chat_message_limit' => 0, 'chat_attachments_enabled' => false, 'project_feedback_level' => 'pass_only', 'project_followup_message_limit' => 0],
                            'guided' => ['chat_enabled' => true, 'chat_message_limit' => (int) data_get($aiTierDefaults, 'guided.chat_message_limit', 50), 'chat_attachments_enabled' => true, 'project_feedback_level' => 'report', 'project_followup_message_limit' => 0],
                            'mentor' => ['chat_enabled' => true, 'chat_message_limit' => (int) data_get($aiTierDefaults, 'mentor.chat_message_limit', 150), 'chat_attachments_enabled' => true, 'project_feedback_level' => 'enhanced', 'project_followup_message_limit' => (int) data_get($aiTierDefaults, 'mentor.project_followup_message_limit', 50)],
                        ]);
                    @endphp
                    <h2 class="section-title">
                        <i class="fa fa-robot"></i>
                        حدود تشغيل Rokn AI
                    </h2>
                    <div class="helper-text settings-help-panel settings-help-panel--info settings-help-panel--bottom settings-help-panel--relaxed">
                        <i class="fa fa-shield"></i>
                        <span>
                            القيم هنا تستطيع خفض حدود الخادم فقط ولا تستطيع تجاوزها
                            الكورسات المجانية والمنح المؤسسية لا تحصل على Rokn AI حتى لا تتحول المبادرة المجانية إلى تكلفة مفتوحة
                            كل عملية شراء لها حد رسائل وتوكنز ودولار مستقل حسب فئتها وهو أول حاجز يُفحص
                            حدود المنصة أدناه للتنبيه المبكر فقط ولا توقف الطلاب الدافعين
                            عند تجاوزها يصل تنبيه للأدمن بينما يظل الإيقاف مقصورًا على الطالب والفئة التي استنفدت ميزانيتها
                            ولا تُحفظ محادثات الطلاب كسجل طويل
                        </span>
                    </div>

                    <div class="form-row">
                        @foreach([
                            'ai_global_daily_request_limit' => ['تنبيه عند عدد طلبات يومي', config('openrouter.global_daily_request_limit', 5000), 1],
                            'ai_global_daily_token_budget' => ['تنبيه عند توكنز يومية', config('openrouter.global_daily_token_budget', 2100000), 1000],
                            'ai_global_monthly_token_budget' => ['تنبيه عند توكنز شهرية', config('openrouter.global_monthly_token_budget', 50000000), 1000],
                        ] as $field => [$label, $fallback, $minimum])
                            <div class="form-group-modern">
                                <label for="{{ $field }}">{{ $label }}</label>
                                {!! Form::number($field, old($field, $settings->{$field} ?: $fallback), [
                                    'class' => 'form-control-modern',
                                    'id' => $field,
                                    'min' => $minimum,
                                    'step' => 1,
                                    'inputmode' => 'numeric',
                                ]) !!}
                                @error($field)<small class="text-danger">{{ $message }}</small>@enderror
                            </div>
                        @endforeach
                    </div>

                    <h2 class="section-title settings-section-title--spaced">
                        <i class="fa fa-layer-group"></i>
                        مزايا الذكاء الاصطناعي حسب الفئة
                    </h2>
                    <div class="helper-text settings-help-panel settings-help-panel--info settings-help-panel--bottom">
                        هذا هو المصدر الوحيد للفئات في كل الكورسات الجديدة والمشتريات الجديدة
                        الكورس المجاني والمنحة لا يحصلان على خدمة مدفوعة لم تمنحها الفئة
                    </div>
                    <div class="form-row">
                        @foreach(['basic' => 'التعلّم', 'guided' => 'التعلّم بإرشاد', 'mentor' => 'التعلّم بمتابعة'] as $code => $label)
                            @php
                                $tier = (array) ($aiPlanPolicy[$code] ?? []);
                                $tierChatCeiling = (int) data_get($aiTierDefaults, $code . '.chat_message_limit', 0);
                                $tierFollowupCeiling = (int) data_get($aiTierDefaults, $code . '.project_followup_message_limit', 0);
                            @endphp
                            <div class="form-group-modern">
                                <h3>{{ $label }}</h3>
                                @if($code === 'basic')
                                    <input type="hidden" name="ai_plan_policy[basic][chat_enabled]" value="0">
                                    <input type="hidden" name="ai_plan_policy[basic][chat_message_limit]" value="0">
                                    <input type="hidden" name="ai_plan_policy[basic][chat_attachments_enabled]" value="0">
                                    <input type="hidden" name="ai_plan_policy[basic][project_feedback_level]" value="pass_only">
                                    <input type="hidden" name="ai_plan_policy[basic][project_followup_message_limit]" value="0">
                                    <p class="text-muted mb-0">عبور المشاريع دون تقرير أو شات مدفوع</p>
                                @else
                                <input type="hidden" name="ai_plan_policy[{{ $code }}][chat_enabled]" value="0">
                                <label><input type="checkbox" name="ai_plan_policy[{{ $code }}][chat_enabled]" value="1" @checked(!empty($tier['chat_enabled']))> شات ركن</label>
                                <label>عدد الرسائل</label>
                                <input class="form-control-modern" type="number" min="0" max="{{ $tierChatCeiling }}" name="ai_plan_policy[{{ $code }}][chat_message_limit]" value="{{ (int) ($tier['chat_message_limit'] ?? 0) }}">
                                <input type="hidden" name="ai_plan_policy[{{ $code }}][chat_attachments_enabled]" value="0">
                                <label><input type="checkbox" name="ai_plan_policy[{{ $code }}][chat_attachments_enabled]" value="1" @checked(!empty($tier['chat_attachments_enabled']))> مرفقات الشات</label>
                                <label>تقييم المشروع</label>
                                <select class="form-control-modern" name="ai_plan_policy[{{ $code }}][project_feedback_level]">
                                    @foreach(($code === 'mentor'
                                        ? ['pass_only' => 'عبور فقط', 'report' => 'تقرير', 'enhanced' => 'تقرير ومتابعة']
                                        : ['pass_only' => 'عبور فقط', 'report' => 'تقرير']) as $value => $text)
                                        <option value="{{ $value }}" @selected(($tier['project_feedback_level'] ?? 'pass_only') === $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                                @if($code === 'mentor')
                                <label>رسائل متابعة المشروع</label>
                                <input class="form-control-modern" type="number" min="0" max="{{ $tierFollowupCeiling }}" name="ai_plan_policy[{{ $code }}][project_followup_message_limit]" value="{{ (int) ($tier['project_followup_message_limit'] ?? 0) }}">
                                @else
                                    <input type="hidden" name="ai_plan_policy[guided][project_followup_message_limit]" value="0">
                                @endif
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="helper-text settings-help-panel settings-help-panel--gold settings-help-panel--relaxed">
                        <i class="fa fa-microchip"></i>
                        <span>
                            النموذج الافتراضي: <code>{{ config('openrouter.default_model') ?: 'غير مضبوط' }}</code><br>
                            النماذج المسموحة: {{ implode(' · ', config('openrouter.allowed_models', [])) ?: 'لم تُضبط بعد — الشات سيتوقف بأمان' }}
                        </span>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="form-actions-modern">
                    <button type="submit" class="btn-modern btn-primary-modern">
                        <span class="icon-badge">
                            <i class="fa fa-save"></i>
                        </span>
                        <span>حفظ جميع التغييرات</span>
                    </button>
                </div>

                {!! Form::close() !!}
            </div>
        </div>
    </div>
</div>

<script>
    // Tab switching functionality
    document.querySelectorAll('.settings-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const tabName = this.dataset.tab;

            // Remove active class from all tabs and panes
            document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));

            // Add active class to clicked tab and corresponding pane
            this.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        });
    });

    // Radio option selection styling
    document.querySelectorAll('.radio-option').forEach(option => {
        option.addEventListener('click', function() {
            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;

                // Remove selected class from all options in this group
                this.closest('.radio-group').querySelectorAll('.radio-option').forEach(opt => {
                    opt.classList.remove('selected');
                });

                // Add selected class to this option
                this.classList.add('selected');
            }
        });

        // Check if radio is already checked on page load
        const radio = option.querySelector('input[type="radio"]');
        if (radio && radio.checked) {
            option.classList.add('selected');
        }
    });

    // Form submission with loading state
    document.querySelector('form').addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="save-loading"></span> جاري الحفظ...';

        // Re-enable after form submission (in case of validation errors)
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }, 3000);
    });

    // Add smooth scrolling to top when switching tabs
    document.querySelectorAll('.settings-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelector('.settings-content').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });
    });

    // Bunny.net toggle functionality
    const bunnyToggle = document.getElementById('bunny_enabled');
    const bunnySettings = document.getElementById('bunny-settings');

    if (bunnyToggle && bunnySettings) {
        bunnyToggle.addEventListener('change', function() {
            bunnySettings.style.display = this.checked ? 'block' : 'none';
        });
    }

    // Bunny.net connection test
    const testBunnyBtn = document.getElementById('test-bunny-connection');
    const bunnyTestResult = document.getElementById('bunny-test-result');

    if (testBunnyBtn) {
        testBunnyBtn.addEventListener('click', function() {
            const apiKey = document.getElementById('bunny_api_key').value;
            const libraryId = document.getElementById('bunny_library_id').value;

            if (!apiKey || !libraryId) {
                bunnyTestResult.textContent = 'أدخل مفتاح Bunny ومعرّف المكتبة';
                bunnyTestResult.className = 'settings-test-result--error';
                return;
            }

            testBunnyBtn.disabled = true;
            testBunnyBtn.innerHTML = '<span class="save-loading"></span> جاري الاختبار...';
            bunnyTestResult.innerHTML = '';

            window.RoknAdminRequest.request('{{ route("admin.settings.test-bunny") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                timeout: 20000,
                body: JSON.stringify({
                    api_key: apiKey,
                    library_id: libraryId
                })
            })
            .then(data => {
                bunnyTestResult.textContent = data.message || 'تم الاتصال بمكتبة Bunny';
                bunnyTestResult.className = 'settings-test-result--success';
            })
            .catch(error => {
                if (error.code === 'cancelled') return;
                bunnyTestResult.textContent = error.message || 'تعذّر الاتصال بمكتبة Bunny';
                bunnyTestResult.className = 'settings-test-result--error';
            })
            .finally(() => {
                testBunnyBtn.disabled = false;
                testBunnyBtn.innerHTML = '<i class="fa fa-plug"></i> اختبار الاتصال';
            });
        });
    }

    const cleanupSelectAll = document.getElementById('cleanup-select-all');
    if (cleanupSelectAll) {
        cleanupSelectAll.addEventListener('change', function () {
            document.querySelectorAll('.cleanup-candidate-checkbox').forEach(checkbox => {
                checkbox.checked = cleanupSelectAll.checked;
            });
        });
    }

    function confirmSelectedBunnyCleanup() {
        const count = document.querySelectorAll('.cleanup-candidate-checkbox:checked').length;
        if (count === 0) {
            alert('حدد فيديو واحدًا على الأقل');
            return false;
        }

        return confirm(`سيتم اعتماد ${count} فيديو للتنظيف بعد فترة الاحتفاظ وإعادة فحص الارتباطات هل تريد المتابعة؟`);
    }
</script>
@endsection
