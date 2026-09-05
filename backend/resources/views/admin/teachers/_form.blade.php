<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="name_ar">الاسم (عربي) <span class="text-danger">*</span></label>
            <input type="text" name="name_ar" id="name_ar" class="form-control @error('name_ar') is-invalid @enderror" value="{{ old('name_ar', $teacher->name_ar ?? '') }}" required>
            @error('name_ar')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="name_en">الاسم (إنجليزي)</label>
            <input type="text" name="name_en" id="name_en" class="form-control @error('name_en') is-invalid @enderror" value="{{ old('name_en', $teacher->name_en ?? '') }}">
            @error('name_en')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
</div>

@if($canManageCredentials)
@php($manageCredentials = (bool) old('manage_credentials', false))
<div class="form-group">
    <div class="custom-control custom-checkbox">
        <input type="checkbox" name="manage_credentials" value="1" class="custom-control-input" id="manage_credentials" autocomplete="off" {{ $manageCredentials ? 'checked' : '' }}>
        <label class="custom-control-label" for="manage_credentials">تعديل بيانات حساب الدخول</label>
    </div>
</div>
<div id="teacherCredentialFields" {{ $manageCredentials ? '' : 'hidden' }}>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label for="email">البريد الإلكتروني <span class="text-muted">(اختياري)</span></label>
                <input type="email" name="email" id="email" autocomplete="off" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $teacher->email ?? '') }}" {{ $manageCredentials ? '' : 'disabled' }}>
                @error('email')
                    <span class="invalid-feedback">{{ $message }}</span>
                @enderror
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label for="phone">رقم الهاتف <span class="text-muted">(اختياري)</span></label>
                <input type="text" name="phone" id="phone" autocomplete="off" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $teacher->phone ?? '') }}" {{ $manageCredentials ? '' : 'disabled' }}>
                @error('phone')
                    <span class="invalid-feedback">{{ $message }}</span>
                @enderror
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label for="password">كلمة المرور <span class="text-muted">(اختياري)</span></label>
                <input type="password" name="password" id="password" autocomplete="new-password" class="form-control @error('password') is-invalid @enderror" {{ $manageCredentials ? '' : 'disabled' }}>
                @error('password')
                    <span class="invalid-feedback">{{ $message }}</span>
                @enderror
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label for="password_confirmation">تأكيد كلمة المرور</label>
                <input type="password" name="password_confirmation" id="password_confirmation" autocomplete="new-password" class="form-control" {{ $manageCredentials ? '' : 'disabled' }}>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="job_title">المسمى الوظيفي</label>
            <input type="text" name="job_title" id="job_title" class="form-control @error('job_title') is-invalid @enderror" value="{{ old('job_title', $teacher->job_title ?? '') }}">
            @error('job_title')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="bio_ar">نبذة عن المعلم (عربي)</label>
            <textarea name="bio_ar" id="bio_ar" class="form-control @error('bio_ar') is-invalid @enderror" rows="4">{{ old('bio_ar', $teacher->bio_ar ?? '') }}</textarea>
            @error('bio_ar')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="bio_en">نبذة عن المعلم (إنجليزي)</label>
            <textarea name="bio_en" id="bio_en" class="form-control @error('bio_en') is-invalid @enderror" rows="4">{{ old('bio_en', $teacher->bio_en ?? '') }}</textarea>
            @error('bio_en')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
</div>

@if($canManageCredentials)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('manage_credentials');
    const fields = document.getElementById('teacherCredentialFields');
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

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="image">الصورة الشخصية</label>
            <input type="file" name="image" id="image" class="form-control @error('image') is-invalid @enderror">
            @error('image')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
            @if(isset($teacher) && $teacher->profile_image_url)
                <div class="mt-2">
                    <img src="{{ $teacher->profile_image_url }}" alt="Current Image" width="100" class="rounded">
                </div>
            @endif
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <div class="custom-control custom-checkbox mt-4">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" class="custom-control-input" id="active" {{ old('active', $teacher->active ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="active">مفعل</label>
            </div>
        </div>
    </div>
</div>
