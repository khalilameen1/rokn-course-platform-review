@extends('admin.layouts.app')

@section('page.title', 'تعديل الإصدار')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/app-versions.css') }}">
@endsection

@section('content')
<div class="card admin-card app-versions-page">
    <div class="card-header">
        <strong class="card-title">تعديل إصدار التطبيق</strong>
    </div>
    <div class="card-body">
        <form action="{{ route('admin.app-versions.update', $version->id) }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>المنصة</label>
                        <input type="hidden" name="platform" value="{{ $version->platform }}">
                        <select class="form-control" id="platform-select" disabled>
                            <option value="android" {{ $version->platform == 'android' ? 'selected' : '' }}>Android</option>
                            <option value="ios" {{ $version->platform == 'ios' ? 'selected' : '' }}>iOS</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>قناة التوزيع</label>
                        @php($selectedChannel = $version->distribution_channel ?: ($version->platform === 'ios' ? 'appstore' : 'play'))
                        <input type="hidden" name="distribution_channel" value="{{ $selectedChannel }}">
                        <select class="form-control" id="channel-select" disabled>
                            <option value="play" data-platform="android" {{ $selectedChannel === 'play' ? 'selected' : '' }}>Google Play</option>
                            <option value="direct" data-platform="android" {{ $selectedChannel === 'direct' ? 'selected' : '' }}>Android مباشر</option>
                            <option value="appstore" data-platform="ios" {{ $selectedChannel === 'appstore' ? 'selected' : '' }}>App Store</option>
                        </select>
                        <small class="form-text text-muted">هوية الإصدار ثابتة بعد إنشائه</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>اسم الإصدار الظاهر (مثال 1.0.0)</label>
                        <input type="text" name="version_name" class="form-control" required readonly value="{{ $version->version_name }}">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6" id="version-code-group" {{ $version->platform == 'android' ? '' : 'hidden' }}>
                    <div class="form-group">
                        <label>كود الإصدار (للأندرويد - رقمي)</label>
                        <input type="number" min="1" name="version_code" id="version-code" class="form-control" readonly value="{{ $version->version_code }}">
                    </div>
                </div>
                <div class="col-md-6" id="build-number-group" {{ $version->platform == 'ios' ? '' : 'hidden' }}>
                    <div class="form-group">
                        <label>رقم البناء (لـ iOS - رقمي)</label>
                        <input type="number" min="1" name="build_number" id="build-number" class="form-control" readonly value="{{ $version->build_number }}">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>رابط التحميل</label>
                        <input type="url" name="download_url" class="form-control" value="{{ $version->download_url }}">
                    </div>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>رسالة التحديث (عربي)</label>
                        <textarea name="update_message_ar" class="form-control" rows="3">{{ $version->update_message_ar }}</textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>رسالة التحديث (إنجليزي)</label>
                        <textarea name="update_message_en" class="form-control" rows="3">{{ $version->update_message_en }}</textarea>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>ملاحظات الإصدار (عربي)</label>
                        <textarea name="release_notes_ar" class="form-control" rows="3">{{ $version->release_notes_ar }}</textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>ملاحظات الإصدار (إنجليزي)</label>
                        <textarea name="release_notes_en" class="form-control" rows="3">{{ $version->release_notes_en }}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_force_update" value="1" id="forceUpdate" {{ $version->is_force_update ? 'checked' : '' }}>
                    <label class="form-check-label" for="forceUpdate">
                        تحديث إجباري
                    </label>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" {{ $version->is_active ? 'checked' : '' }}>
                    <label class="form-check-label" for="isActive">
                        نشط (الإصدار الحالي)
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">تحديث</button>
            <a href="{{ route('admin.app-versions.index') }}" class="btn btn-secondary">إلغاء</a>
        </form>
    </div>
</div>

<script>
const platformSelect = document.getElementById('platform-select');
const channelSelect = document.getElementById('channel-select');
const versionCode = document.getElementById('version-code');
const buildNumber = document.getElementById('build-number');

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
}

syncPlatformFields();
</script>
@endsection
