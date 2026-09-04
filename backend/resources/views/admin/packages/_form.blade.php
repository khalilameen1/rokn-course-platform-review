@php
    $current = $package ?? null;
    $storeContractIssued = $current && (filled($current->google_product_id) || filled($current->apple_product_id));
@endphp
@error('package')<div class="alert alert-danger">{{ $message }}</div>@enderror
@error('channels')<div class="alert alert-danger">{{ $message }}</div>@enderror
<div class="row form-group">
    <div class="col col-md-3"><label for="name_ar" class="form-control-label">الاسم بالعربية</label></div>
    <div class="col-12 col-md-9"><input type="text" id="name_ar" name="name_ar" maxlength="255" class="form-control" value="{{ old('name_ar', $current?->name_ar) }}" required></div>
</div>
<div class="row form-group">
    <div class="col col-md-3"><label for="name_en" class="form-control-label">الاسم بالإنجليزية</label></div>
    <div class="col-12 col-md-9"><input type="text" id="name_en" name="name_en" maxlength="255" class="form-control" value="{{ old('name_en', $current?->name_en) }}" required></div>
</div>
<div class="row form-group">
    <div class="col col-md-3"><label for="price" class="form-control-label">السعر بالجنيه</label></div>
    <div class="col-12 col-md-9"><input type="number" id="price" name="price" min="0.01" step="0.01" class="form-control" value="{{ old('price', $current?->price) }}" required></div>
</div>
<div class="row form-group">
    <div class="col col-md-3"><label for="coins" class="form-control-label">عملات ركن</label></div>
    <div class="col-12 col-md-9">
        <input type="number" id="coins" name="coins" min="1" step="1" class="form-control" value="{{ old('coins', $current?->coins) }}" @readonly($storeContractIssued) required>
        @if($storeContractIssued)<small class="form-text text-muted">عدد العملات ثابت بعد ربط منتج متجر. أنشئ باقة جديدة لتغيير الرصيد.</small>@endif
    </div>
</div>
<div class="row form-group">
    <div class="col col-md-3"><label for="sort_order" class="form-control-label">ترتيب الظهور</label></div>
    <div class="col-12 col-md-9"><input type="number" id="sort_order" name="sort_order" min="0" max="10000" class="form-control" value="{{ old('sort_order', $current?->sort_order ?? 100) }}"></div>
</div>
<div class="row form-group">
    <div class="col col-md-3"><span class="form-control-label">الإتاحة</span></div>
    <div class="col-12 col-md-9">
        <input type="hidden" name="is_active" value="0">
        <label class="mr-3"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $current?->is_active ?? true))> ظاهرة للطالب</label>
        <input type="hidden" name="direct_enabled" value="0">
        <label><input type="checkbox" name="direct_enabled" value="1" @checked(old('direct_enabled', $current?->direct_enabled ?? true))> كاشير</label>
    </div>
</div>
@foreach([
    'google' => ['Google Play', 'rokn.coins.4200'],
    'apple' => ['App Store', 'com.rokn.coins.4200'],
] as $channel => [$label, $placeholder])
    @php($productField = $channel.'_product_id')
    @php($enabledField = $channel.'_enabled')
    <div class="row form-group">
        <div class="col col-md-3"><label for="{{ $productField }}" class="form-control-label">منتج {{ $label }}</label></div>
        <div class="col-12 col-md-9">
            <input type="text" id="{{ $productField }}" name="{{ $productField }}" maxlength="191" class="form-control" value="{{ old($productField, $current?->{$productField}) }}" placeholder="{{ $placeholder }}" @readonly($current && filled($current->{$productField}))>
            <input type="hidden" name="{{ $enabledField }}" value="0">
            <label class="mt-2"><input type="checkbox" name="{{ $enabledField }}" value="1" @checked(old($enabledField, $current?->{$enabledField}))> متاحة عبر {{ $label }}</label>
        </div>
    </div>
@endforeach
