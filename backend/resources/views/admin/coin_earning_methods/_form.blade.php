@php
    $current = $coinEarningMethod ?? null;
    $activeValue = (string) old('is_active', ($current?->is_active ?? true) ? '1' : '0');
    $externalValue = (string) old('requires_external_visit', ($current?->requires_external_visit ?? true) ? '1' : '0');
@endphp
@error('coin_earning_method')<div class="alert alert-danger">{{ $message }}</div>@enderror
<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label" for="title_ar">العنوان بالعربية</label>
        <input id="title_ar" type="text" maxlength="255" name="title_ar" class="form-control @error('title_ar') is-invalid @enderror" value="{{ old('title_ar', $current?->title_ar) }}" required>
        @error('title_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label" for="title_en">العنوان بالإنجليزية</label>
        <input id="title_en" type="text" maxlength="255" name="title_en" class="form-control @error('title_en') is-invalid @enderror" value="{{ old('title_en', $current?->title_en) }}" required>
        @error('title_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="coins_amount">عدد العملات</label>
        <input id="coins_amount" type="number" min="1" name="coins_amount" class="form-control @error('coins_amount') is-invalid @enderror" value="{{ old('coins_amount', $current?->coins_amount) }}" required>
        @error('coins_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="action_key">مفتاح الإجراء</label>
        <input id="action_key" type="text" maxlength="255" name="action_key" class="form-control @error('action_key') is-invalid @enderror" value="{{ old('action_key', $current?->action_key) }}" placeholder="مثال: instagram" required>
        @error('action_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="is_active">الحالة</label>
        <select id="is_active" name="is_active" class="form-control">
            <option value="1" @selected($activeValue === '1')>نشطة</option>
            <option value="0" @selected($activeValue === '0')>متوقفة</option>
        </select>
    </div>
</div>
<div class="row mt-2">
    <div class="col-md-8 mb-3">
        <label class="form-label" for="action_url">رابط المهمة الخارجية</label>
        <input id="action_url" type="url" maxlength="2000" name="action_url" class="form-control @error('action_url') is-invalid @enderror" value="{{ old('action_url', $current?->action_url) }}" placeholder="https://instagram.com/rokn">
        <small class="form-text text-muted">اتركه فارغًا لمهام السوشيال التي تستخدم رابط الحساب من إعدادات التطبيق</small>
        @error('action_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="requires_external_visit">طريقة المطالبة</label>
        <select id="requires_external_visit" name="requires_external_visit" class="form-control">
            <option value="0" @selected($externalValue === '0')>داخل التطبيق</option>
            <option value="1" @selected($externalValue === '1')>زيارة الرابط ثم العودة</option>
        </select>
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="verification_delay_seconds">مهلة العودة بالثواني</label>
        <input id="verification_delay_seconds" type="number" min="0" max="300" name="verification_delay_seconds" class="form-control" value="{{ old('verification_delay_seconds', $current?->verification_delay_seconds ?? 3) }}">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="campaign_key">مفتاح الحملة</label>
        <input id="campaign_key" type="text" maxlength="80" name="campaign_key" class="form-control @error('campaign_key') is-invalid @enderror" value="{{ old('campaign_key', $current?->campaign_key) }}" placeholder="launch-2026-instagram">
        @error('campaign_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="starts_at">بداية الحملة</label>
        <input id="starts_at" type="datetime-local" name="starts_at" class="form-control" value="{{ old('starts_at', \App\Support\BusinessClock::forDateTimeInput($current?->starts_at)) }}">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="ends_at">نهاية الحملة</label>
        <input id="ends_at" type="datetime-local" name="ends_at" class="form-control" value="{{ old('ends_at', \App\Support\BusinessClock::forDateTimeInput($current?->ends_at)) }}">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="total_claim_limit">إجمالي المطالبات</label>
        <input id="total_claim_limit" type="number" min="1" max="10000000" name="total_claim_limit" class="form-control" value="{{ old('total_claim_limit', $current?->total_claim_limit) }}" placeholder="بلا سقف">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="sort_order">ترتيب الظهور</label>
        <input id="sort_order" type="number" min="0" max="10000" name="sort_order" class="form-control" value="{{ old('sort_order', $current?->sort_order ?? 100) }}">
    </div>
</div>
<div class="alert alert-info py-2">التوقيت بتوقيت القاهرة وكل حساب يستلم الحملة مرة واحدة</div>
