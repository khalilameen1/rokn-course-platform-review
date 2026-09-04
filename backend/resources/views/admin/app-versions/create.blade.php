@extends('admin.layouts.app')

@section('page.title', 'إضافة إصدار جديد')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/app-versions.css') }}">
@endsection

@section('content')
<div class="card admin-card app-versions-page">
    <div class="card-header">
        <strong class="card-title">إضافة إصدار تطبيق جديد</strong>
    </div>
    <div class="card-body">
        <form action="{{ route('admin.app-versions.store') }}" method="POST">
            @csrf
            <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>المنصة</label>
                        <select name="platform" class="form-control" required id="platform-select">
                            <option value="android" {{ old('platform') === 'android' ? 'selected' : '' }}>Android</option>
                            <option value="ios" {{ old('platform') === 'ios' ? 'selected' : '' }}>iOS</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>قناة التوزيع</label>
                        <select name="distribution_channel" class="form-control" required id="channel-select">
                            <option value="play" data-platform="android" {{ old('distribution_channel', 'play') === 'play' ? 'selected' : '' }}>Google Play</option>
                            <option value="direct" data-platform="android" {{ old('distribution_channel') === 'direct' ? 'selected' : '' }}>Android مباشر</option>
                            <option value="appstore" data-platform="ios" {{ old('distribution_channel') === 'appstore' ? 'selected' : '' }}>App Store</option>
                        </select>
                        <small class="form-text text-muted" id="channel-hint">كل قناة لها رابط تحديث مستقل ولا تختلط بالقنوات الأخرى</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>اسم الإصدار الظاهر (مثال 1.0.0)</label>
                        <input type="text" name="version_name" class="form-control" required placeholder="1.0.0" value="{{ old('version_name') }}">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6" id="version-code-group">
                    <div class="form-group">
                        <label>كود الإصدار (للأندرويد - رقمي)</label>
                        <input type="number" min="1" name="version_code" id="version-code" class="form-control" placeholder="100" value="{{ old('version_code') }}">
                    </div>
                </div>
                <div class="col-md-6" id="build-number-group" hidden>
                    <div class="form-group">
                        <label>رقم البناء (لـ iOS - رقمي)</label>
                        <input type="number" min="1" name="build_number" id="build-number" class="form-control" placeholder="1" value="{{ old('build_number') }}">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>رابط التحميل</label>
                        <input type="url" name="download_url" class="form-control" placeholder="https://..." value="{{ old('download_url') }}">
                    </div>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>رسالة التحديث (عربي)</label>
                        <textarea name="update_message_ar" class="form-control" rows="3">{{ old('update_message_ar') }}</textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>رسالة التحديث (إنجليزي)</label>
                        <textarea name="update_message_en" class="form-control" rows="3">{{ old('update_message_en') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>ملاحظات الإصدار (عربي)</label>
                        <textarea name="release_notes_ar" class="form-control" rows="3">{{ old('release_notes_ar') }}</textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>ملاحظات الإصدار (إنجليزي)</label>
                        <textarea name="release_notes_en" class="form-control" rows="3">{{ old('release_notes_en') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_force_update" value="1" id="forceUpdate" {{ old('is_force_update') ? 'checked' : '' }}>
                    <label class="form-check-label" for="forceUpdate">
                        تحديث إجباري
                    </label>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" {{ old('is_active', '1') ? 'checked' : '' }}>
                    <label class="form-check-label" for="isActive">
                        نشط (الإصدار الحالي)
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">حفظ</button>
            <a href="{{ route('admin.app-versions.index') }}" class="btn btn-secondary">إلغاء</a>
        </form>
    </div>
</div>

<script>
const platformSelect = document.getElementById('platform-select');
const channelSelect = document.getElementById('channel-select');
const versionCode = document.getElementById('version-code');
const buildNumber = document.getElementById('build-number');
const channelHint = document.getElementById('channel-hint');
const latestIdentifiers = @json($latestIdentifiers);

function syncPlatformFields() {
    const platform = platformSelect.value;
    const isAndroid = platform === 'android';
    document.getElementById('version-code-group').hidden = !isAndroid;
    document.getElementById('build-number-group').hidden = isAndroid;
    versionCode.required = isAndroid;
    buildNumber.required = !isAndroid;

    Array.from(channelSelect.options).forEach(option => {
        const available = option.dataset.platform === platform;
        option.hidden = !available;
        option.disabled = !available;
    });
    if (channelSelect.selectedOptions[0]?.disabled) {
        channelSelect.value = isAndroid ? 'play' : 'appstore';
    }
    const latest = latestIdentifiers[channelSelect.value] || {};
    const channelLatest = Number(latest.channel || 0);
    const platformLatest = Number(latest.platform || 0);
    channelHint.textContent = channelLatest > 0
        ? `آخر رقم في هذه القناة ${channelLatest} استخدم رقمًا أكبر منه`
        : platformLatest > 0
        ? `يمكنك استخدام رقم المنصة الحالي ${platformLatest} أو رقم أكبر`
        : 'لا يوجد إصدار سابق في هذه القناة';
}

platformSelect.addEventListener('change', syncPlatformFields);
channelSelect.addEventListener('change', syncPlatformFields);
syncPlatformFields();
</script>
@endsection
