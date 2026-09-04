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
<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="email">البريد الإلكتروني <span class="text-danger">*</span></label>
            <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $teacher->email ?? '') }}" required>
            @error('email')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="phone">رقم الهاتف <span class="text-danger">*</span></label>
            <input type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $teacher->phone ?? '') }}" required>
            @error('phone')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
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
    @if($canManageCredentials)
    <div class="col-md-6">
        <div class="form-group">
            <label for="password">
                كلمة المرور
                @if(isset($teacher))
                    <span class="text-muted">(اختياري)</span>
                @else
                    <span class="text-danger">*</span>
                @endif
            </label>
            <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" {{ isset($teacher) ? '' : 'required' }}>
            @error('password')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
    @endif
</div>

@if($canManageCredentials)
<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="password_confirmation">تأكيد كلمة المرور</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control">
        </div>
    </div>
</div>
@endif

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

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="image">الصورة الشخصية</label>
            <input type="file" name="image" id="image" class="form-control @error('image') is-invalid @enderror">
            @error('image')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
            @if(isset($teacher) && $teacher->profile_image)
                <div class="mt-2">
                    <img src="{{ $teacher->profile_image_url }}" alt="Current Image" width="100" class="rounded">
                </div>
            @endif
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <div class="custom-control custom-checkbox mt-4">
                <input type="checkbox" name="active" class="custom-control-input" id="active" {{ old('active', $teacher->active ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="active">مفعل</label>
            </div>
        </div>
    </div>
</div>
