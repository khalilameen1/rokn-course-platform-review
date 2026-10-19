@extends('admin.layouts.app')

@section('page.title', 'المكافآت')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
<div class="fade-in admin-learning admin-learning--coins admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'المكافآت',
        'pageDescription' => 'مهام اكسب عملات والمكافآت التلقائية وحدود الخصم',
        'pageIcon' => 'fa-money',
        'pageActionUrl' => \App\Filament\Resources\CoinEarningMethodResource::getUrl(),
        'pageActionLabel' => 'مهام العملات',
        'pageActionIcon' => 'fa-plus',
    ])

    {{-- How to Use Coins Settings --}}
    <div class="card shadow-sm border-0 mb-4 coin-panel">
        <div class="card-header bg-white py-3 d-flex align-items-center">
            <i class="fa fa-info-circle coin-accent-icon ml-2"></i>
            <h6 class="mb-0 font-weight-bold">كيف يعمل الرصيد</h6>
        </div>
        <div class="card-body p-4">
            <form action="{{ route('admin.coin-earning-methods.update-settings') }}" method="POST">
                @csrf
                <input type="hidden" name="editor_version" value="{{ $settingsEditorVersion }}">
                <p class="text-muted small">يظهر الشرح عند الضغط على كيف يعمل الرصيد في صفحة مكافآتي ولا تظهر باقات شحن في التطبيق الجديد</p>
                <div class="row">
                    @foreach(['ar' => 'العربية', 'en' => 'English'] as $locale => $label)
                        <div class="col-md-6 mb-3">
                            <label for="rewards_help_{{ $locale }}">{{ $label }}</label>
                            <textarea class="form-control @error('rewards_help_'.$locale) is-invalid @enderror" id="rewards_help_{{ $locale }}" name="rewards_help_{{ $locale }}" rows="3" maxlength="600" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">{{ old('rewards_help_'.$locale, $setting?->{'rewards_help_'.$locale} ?? \App\Models\Setting::defaultRewardsHelp($locale)) }}</textarea>
                            @error('rewards_help_'.$locale)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
                <details class="mb-3">
                <summary class="text-muted">نص المحفظة للإصدارات السابقة والموقع</summary>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold" for="how_to_use_coins_ar">بالعربية</label>
                        <textarea id="how_to_use_coins_ar" name="how_to_use_coins_ar" rows="4" maxlength="12000" dir="rtl"
                            class="form-control @error('how_to_use_coins_ar') is-invalid @enderror"
                            placeholder="اشرح للطالب كيف يمكنه استخدام عملاته...">{{ old('how_to_use_coins_ar', $setting?->how_to_use_coins_ar) }}</textarea>
                        @error('how_to_use_coins_ar')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold" for="how_to_use_coins_en">بالإنجليزية</label>
                        <textarea id="how_to_use_coins_en" name="how_to_use_coins_en" rows="4" maxlength="12000" dir="ltr"
                            class="form-control @error('how_to_use_coins_en') is-invalid @enderror"
                            placeholder="Explain to the student how they can use their coins...">{{ old('how_to_use_coins_en', $setting?->how_to_use_coins_en) }}</textarea>
                        @error('how_to_use_coins_en')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                </details>
                <div class="row">
                    @foreach([
                        'reward_balance_cap' => ['أقصى رصيد مكافآت', 1200, 0],
                        'max_reward_contribution_per_course' => ['السقف القديم بالعملات وليس بالنسبة', 1200, 0],
                    ] as $field => [$label, $fallback, $minimum])
                        <div class="col-md-6 col-lg-4 mb-3">
                            <label class="form-label font-weight-bold" for="{{ $field }}">{{ $label }}</label>
                            <input
                                type="number"
                                name="{{ $field }}"
                                id="{{ $field }}"
                                min="{{ $minimum }}"
                                step="1"
                                inputmode="numeric"
                                value="{{ old($field, $setting?->{$field} ?? $fallback) }}"
                                class="form-control @error($field) is-invalid @enderror"
                                required>
                            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                    <div class="col-md-6 col-lg-4 mb-3">
                        <label class="form-label font-weight-bold" for="max_course_promotion_percent">المكافآت والكوبونات معًا بحد أقصى ٪</label>
                        <input class="form-control @error('max_course_promotion_percent') is-invalid @enderror"
                            id="max_course_promotion_percent" name="max_course_promotion_percent" type="number"
                            min="0" max="20" step="1" required
                            value="{{ old('max_course_promotion_percent', $setting?->max_course_promotion_percent ?? config('course_plans.max_promotion_percent', 20)) }}">
                        <small class="form-text text-muted">من سعر الاشتراك ويشمل الترقيات السابقة ولا يقيّد العملات المشتراة</small>
                        @error('max_course_promotion_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <hr>
                <h6 class="font-weight-bold mb-3">عرض التسجيل الموصى به</h6>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="recommended_social_provider">المنصة المميزة أعلى خيارات الدخول</label>
                        <select class="form-control" id="recommended_social_provider" name="recommended_social_provider" required>
                            @foreach($socialProviderLabels as $provider => $label)
                                <option value="{{ $provider }}" {{ old('recommended_social_provider', $setting?->recommended_social_provider ?? config('social_auth.recommended_provider')) === $provider ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="recommended_provider_bonus_coins">عملات إضافية فوق هدية التسجيل</label>
                        <input class="form-control" id="recommended_provider_bonus_coins" min="0" name="recommended_provider_bonus_coins" required type="number" value="{{ old('recommended_provider_bonus_coins', $setting?->recommended_provider_bonus_coins ?? 0) }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="recommended_provider_badge_ar">نص الشارة</label>
                        <input class="form-control" id="recommended_provider_badge_ar" maxlength="255" name="recommended_provider_badge_ar" placeholder="{{ config('social_auth.recommended_provider_badge_ar') }}" value="{{ old('recommended_provider_badge_ar', $setting?->recommended_provider_badge_ar ?? config('social_auth.recommended_provider_badge_ar')) }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="font-weight-bold" for="recommended_provider_badge_en">English badge</label>
                        <input class="form-control" dir="ltr" id="recommended_provider_badge_en" maxlength="255" name="recommended_provider_badge_en" value="{{ old('recommended_provider_badge_en', $setting?->recommended_provider_badge_en ?? config('social_auth.recommended_provider_badge_en')) }}">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary px-4 coin-form-action">
                    <i class="fa fa-save ml-1"></i> حفظ قواعد استخدام العملات
                </button>
            </form>
        </div>
    </div>

    <a class="btn btn-outline-primary mb-4" href="{{ \App\Filament\Resources\RewardRuleResource::getUrl() }}">المكافآت التلقائية</a>

</div>
@endsection

