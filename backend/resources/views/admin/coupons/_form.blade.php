<div class="row form-group">
    <div class="col-md-2"><label for="course_id" class="form-control-label">نطاق الكود</label></div>
    <div class="col-md-10">
        <select class="form-control" id="course_id" name="course_id">
            @if(isset($coupon) && $coupon->course_id !== null && !$courses->contains('id', $coupon->course_id))
                <option value="{{ $coupon->course_id }}" @selected((string) old('course_id', $coupon->course_id) === (string) $coupon->course_id)>ارتباط قديم غير صالح — اختر الكورس الأصلي أو أوقف الكود</option>
            @endif
            <option value="" @selected((string) old('course_id', $coupon->course_id ?? '') === '')>كل الكورسات</option>
            @foreach($courses as $courseOption)
                <option value="{{ $courseOption->id }}" @selected((string) old('course_id', $coupon->course_id ?? '') === (string) $courseOption->id)>{{ $courseOption->name_ar }}</option>
            @endforeach
        </select>
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2"><label for="starts_at" class="form-control-label">يبدأ</label></div>
    <div class="col-md-10">
        <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="{{ old('starts_at', isset($coupon) ? \App\Support\BusinessClock::forDateTimeInput($coupon->starts_at) : '') }}">
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="name_ar" class="form-control-label">اسم الكوبون</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('name_ar', null, ['class' => 'form-control' , 'required', 'id'=>"name_ar"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2"><label for="max_redemptions" class="form-control-label">الحد الكلي للاستخدام</label></div>
    <div class="col-md-10">
        <input class="form-control" type="number" min="1" id="max_redemptions" name="max_redemptions" value="{{ old('max_redemptions', $coupon->max_redemptions ?? '') }}" placeholder="بلا حد">
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en" class="form-control-label">الاسم بالإنجليزية</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('name_en', null, ['class' => 'form-control', 'id'=>"name_en"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="code" class="form-control-label">كود الخصم</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('code', null, ['class' => 'form-control' , 'required', 'id'=>"code"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="balance" class="form-control-label">نسبة الخصم</label>
    </div>
    <div class="col-md-10">
        {!! Form::number('balance', null, ['class' => 'form-control' , 'required', 'id'=>"balance","max"=>"100","min"=>"1"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="expiry_date" class="form-control-label">تاريخ الانتهاء</label>
    </div>
    <div class="col-md-10">
        {!! Form::date('expiry_date', isset($coupon) && $coupon->expiry_date ? substr((string) $coupon->getRawOriginal('expiry_date'), 0, 10) : null, ['class' => 'form-control' , 'required', 'id'=>"expiry_date","min"=>\App\Support\BusinessClock::now()->format('Y-m-d')] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="active" class="form-control-label">الحالة</label>
    </div>
    <div class="col-md-10 ">
         
         <select class="form-control" id="active" name="active">
            <option value="0" {{ (@$coupon->active == 0)? 'selected':'' }}>غير مفعل</option>
            <option value="1"  {{ (@$coupon->active== 1)? 'selected':'' }}   >مفعل</option>
          </select>
    </div>
</div>
<!--
<div class="row form-group">
    <div class="col-md-2">
        <label for="image" class="form-control-label">الصورة</label>
    </div>
    <div class="col-md-10">
        <input id="image" name="image" class="form-control-file" type="file" required>
    </div>
</div>-->
<div class="form-actions form-group">
    <button type="submit" class="btn btn-success btn-sm">حفظ</button>
    <a href="{{ route('admin.coupons.index') }}" class="btn btn-danger btn-sm">إلغاء</a>
</div>
