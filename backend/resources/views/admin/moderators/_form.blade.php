@csrf
@if(isset($moderator)) @method('PUT') @endif
<div class="form-group">
    <label for="name_ar">الاسم بالعربية</label>
    <input id="name_ar" name="name_ar" class="form-control" required maxlength="255" value="{{ old('name_ar', $moderator->name_ar ?? '') }}">
</div>
<div class="form-group">
    <label for="name_en">الاسم بالإنجليزية</label>
    <input id="name_en" name="name_en" class="form-control" maxlength="255" value="{{ old('name_en', $moderator->name_en ?? '') }}">
</div>
@php($editingCredentials = !isset($moderator) || (bool) old('manage_credentials', false))
@if(isset($moderator))
<div class="form-group">
    <div class="custom-control custom-checkbox">
        <input id="manage_credentials" name="manage_credentials" value="1" type="checkbox" class="custom-control-input" autocomplete="off" {{ $editingCredentials ? 'checked' : '' }}>
        <label class="custom-control-label" for="manage_credentials">تعديل بيانات الدخول</label>
    </div>
</div>
@endif
<div id="moderatorCredentialFields" {{ $editingCredentials ? '' : 'hidden' }}>
    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="email">البريد المستخدم للدخول</label>
            <input id="email" name="email" type="email" class="form-control" required autocomplete="off" value="{{ old('email', $moderator->email ?? '') }}" {{ $editingCredentials ? '' : 'disabled' }}>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="password">كلمة المرور {{ isset($moderator) ? '(اختياري)' : '' }}</label>
            <input id="password" name="password" type="password" class="form-control" minlength="10" {{ isset($moderator) ? '' : 'required' }} autocomplete="new-password" {{ $editingCredentials ? '' : 'disabled' }}>
        </div>
        <div class="form-group col-md-6">
            <label for="password_confirmation">تأكيد كلمة المرور</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" minlength="10" {{ isset($moderator) ? '' : 'required' }} autocomplete="new-password" {{ $editingCredentials ? '' : 'disabled' }}>
        </div>
    </div>
</div>
<div class="form-group">
    <label for="phone">الهاتف (اختياري)</label>
    <input id="phone" name="phone" class="form-control" maxlength="20" value="{{ old('phone', $moderator->phone ?? '') }}">
</div>
<input type="hidden" name="active" value="0">
<label class="mb-3"><input type="checkbox" name="active" value="1" {{ old('active', $moderator->active ?? true) ? 'checked' : '' }}> الحساب نشط</label>

@if(isset($moderator))
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('manage_credentials');
    const fields = document.getElementById('moderatorCredentialFields');
    if (!toggle || !fields) return;

    const sync = () => {
        fields.hidden = !toggle.checked;
        fields.querySelectorAll('input').forEach(input => {
            input.disabled = !toggle.checked;
        });
    };
    toggle.addEventListener('change', sync);
    sync();
});
</script>
@endif
