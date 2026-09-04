@php($current = $admin_notification ?? null)
@if($current)<input name="editor_version" type="hidden" value="{{ $editorVersion }}">@endif
<div class="alert alert-info">
    اكتب كأنك تكلم طالبًا واحدًا. المتغيرات المتاحة: <code>{coins}</code> لعدد العملات،
    <code>{course}</code> للكورس، <code>{task}</code> للمهمة،
    <code>{lesson}</code> للمقطع، و<code>{project}</code> للمشروع.
</div>
<div class="row">
    <div class="col-md-6 form-group">
        <label for="surface">مكان الاستخدام</label>
        @if($current?->isSystemTemplate())
            <select class="form-control" id="surface" name="surface" required>
                @foreach(\App\Models\AdminNotification::SURFACES as $value => $label)
                    <option value="{{ $value }}" {{ old('surface', $current->surface) === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        @else
            <input id="surface" name="surface" type="hidden" value="announcement">
            <div class="form-control text-muted">{{ \App\Models\AdminNotification::SURFACES['announcement'] }}</div>
        @endif
        @error('surface')<small class="text-danger">{{ $message }}</small>@enderror
    </div>
    <div class="col-md-6 form-group">
        <label for="system_key">مفتاح الحدث</label>
        @if($current?->isSystemTemplate())
            <input class="form-control" dir="ltr" id="system_key" name="system_key" readonly type="text" value="{{ $current->system_key }}">
            <small class="form-text text-muted">مرتبط بهذا الحدث داخل التطبيق</small>
        @else
            <input id="system_key" name="system_key" type="hidden" value="">
            <div class="form-control text-muted">إعلان يدوي</div>
            <small class="form-text text-muted">الأحداث الآلية الحالية تُعدّل من بطاقاتها ولا تحتاج مفتاحًا جديدًا</small>
        @endif
    </div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="title_ar">العنوان العربي</label><input class="form-control" id="title_ar" maxlength="80" name="title_ar" required type="text" value="{{ old('title_ar', $current?->title_ar) }}"></div>
    <div class="col-md-6 form-group"><label for="title_en">English title <small class="text-muted">اختياري</small></label><input class="form-control" dir="ltr" id="title_en" maxlength="80" name="title_en" type="text" value="{{ old('title_en', $current?->title_en) }}"></div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="description_ar">النص العربي</label><textarea class="form-control" id="description_ar" maxlength="240" name="description_ar" required rows="4">{{ old('description_ar', $current?->description_ar) }}</textarea></div>
    <div class="col-md-6 form-group"><label for="description_en">English copy <small class="text-muted">اختياري</small></label><textarea class="form-control" dir="ltr" id="description_en" maxlength="240" name="description_en" rows="4">{{ old('description_en', $current?->description_en) }}</textarea></div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="action_label_ar">نص الزر الأساسي</label><input class="form-control @error('action_label_ar') is-invalid @enderror" id="action_label_ar" maxlength="80" name="action_label_ar" type="text" value="{{ old('action_label_ar', $current?->action_label_ar) }}">@error('action_label_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-6 form-group"><label for="secondary_action_label_ar">نص الإغلاق أو التأجيل</label><input class="form-control" id="secondary_action_label_ar" maxlength="80" name="secondary_action_label_ar" type="text" value="{{ old('secondary_action_label_ar', $current?->secondary_action_label_ar) }}"></div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="action_label_en">Primary action (English)</label><input class="form-control" dir="ltr" id="action_label_en" maxlength="80" name="action_label_en" type="text" value="{{ old('action_label_en', $current?->action_label_en) }}"></div>
    <div class="col-md-6 form-group"><label for="secondary_action_label_en">Secondary action (English)</label><input class="form-control" dir="ltr" id="secondary_action_label_en" maxlength="80" name="secondary_action_label_en" type="text" value="{{ old('secondary_action_label_en', $current?->secondary_action_label_en) }}"></div>
</div>
<div class="form-group"><label for="link">الوجهة داخل التطبيق</label><input class="form-control @error('link') is-invalid @enderror" dir="ltr" id="link" name="link" type="text" value="{{ old('link', $current?->link) }}" placeholder="/wallet أو /course/52">@error('link')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
<div class="row">
    <div class="col-md-4 form-group"><label for="priority">الأولوية</label><input class="form-control" id="priority" min="0" max="1000" name="priority" required type="number" value="{{ old('priority', $current?->priority ?? 100) }}"><small class="text-muted">الرقم الأقل يظهر أولًا.</small></div>
    <div class="col-md-4 form-group"><label for="cooldown_hours">مدة التهدئة بالساعات</label><input class="form-control" id="cooldown_hours" min="0" max="8760" name="cooldown_hours" required type="number" value="{{ old('cooldown_hours', $current?->cooldown_hours ?? 72) }}"></div>
    <div class="col-md-4 form-group"><label for="image">الصورة (اختيارية)</label><input accept="image/*" class="form-control-file" id="image" name="image" type="file">@if($current?->public_image_url)<img alt="معاينة الصورة الحالية" class="notification-template-preview" src="{{ $current->public_image_url }}"><label class="d-block mt-2"><input name="remove_image" type="checkbox" value="1"> حذف الصورة الحالية</label>@endif</div>
</div>
<div class="row">
    <div class="col-md-6 form-group"><label for="starts_at">يبدأ في (اختياري)</label><input class="form-control" id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', \App\Support\BusinessClock::forDateTimeInput($current?->starts_at)) }}"></div>
    <div class="col-md-6 form-group"><label for="ends_at">ينتهي في (اختياري)</label><input class="form-control" id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at', \App\Support\BusinessClock::forDateTimeInput($current?->ends_at)) }}"></div>
</div>
<div class="d-flex flex-wrap align-items-center mb-4 admin-gap">
    <label class="mb-0"><input name="is_active" type="hidden" value="0"><input name="is_active" type="checkbox" value="1" {{ old('is_active', $current?->is_active ?? true) ? 'checked' : '' }}> مفعّل</label>
    <label class="mb-0"><input name="is_dismissible" type="hidden" value="0"><input name="is_dismissible" type="checkbox" value="1" {{ old('is_dismissible', $current?->is_dismissible ?? true) ? 'checked' : '' }}> يمكن للطالب إغلاقه</label>
</div>
<div class="form-actions form-group">
    <button type="submit" class="btn btn-success"><i class="fa fa-check ml-1"></i> حفظ القالب</button>
    <a href="{{ route('admin.admin_notifications.index') }}" class="btn btn-light">إلغاء</a>
</div>
