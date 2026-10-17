
<input type="hidden" name="id" value="{{ isset($user) ? $user->id : null }}">

<!-- Basic Information Section -->
<div class="form-section">
    <h4 class="form-section-title">
        <i class="fa fa-user-circle"></i>
        المعلومات الأساسية
    </h4>
    
    <div class="form-group-modern">
        <label class="form-label-modern">
            <i class="fa fa-user"></i>
            الاسم الكامل
            <span class="required">*</span>
        </label>
        <div class="input-group-modern">
            {!! Form::text('name', null, ['class' => 'form-control-modern form-control-modern--padded' , 'required', 'id'=>"name", 'placeholder'=>"أدخل الاسم الكامل"] )!!}
        </div>
    </div>

    <div class="form-group-modern">
        <label class="form-label-modern">
            <i class="fa fa-envelope"></i>
            البريد الإلكتروني
            <span class="required">*</span>
        </label>
        <div class="input-group-modern">
            {!! Form::email('email', null, ['class' => 'form-control-modern form-control-modern--padded' , 'required', 'id'=>"email", 'placeholder'=>"example@domain.com"] )!!}
        </div>
    </div>

    <div class="form-group-modern">
        <label class="form-label-modern">
            <i class="fa fa-phone"></i>
            رقم الجوال
            <span class="required">*</span>
        </label>
        <div class="input-group-modern">
            {!! Form::text('phone', null, ['class' => 'form-control-modern form-control-modern--padded' , 'required', 'id'=>"phone", 'dir' => 'ltr', 'placeholder'=>"05xxxxxxxx"] )!!}
        </div>
    </div>

</div>



<!-- Form Actions -->
<div class="form-actions-modern">
    <a href="{{ route('admin.users.index') }}" class="btn-form-modern btn-form-cancel">
        <i class="fa fa-times"></i>
        إلغاء
    </a>
    <button type="submit" class="btn-form-modern btn-form-submit">
        <i class="fa fa-save"></i>
        {{ isset($user) ? 'تحديث البيانات' : 'حفظ الطالب' }}
    </button>
</div>

