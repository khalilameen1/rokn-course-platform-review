<div class="form-section" id="course-editor-details">
    @include('admin.courses.partials.publishing-area-issues', ['area' => 'details'])
    <h2 class="section-title">
        <div class="section-icon"><i class="fa fa-info-circle"></i></div>
        المعلومات الأساسية
    </h2>

    <div class="form-row">
        <div class="form-group-modern{{ isset($enableEnglish) && $enableEnglish ? '' : ' form-group-full' }}">
            <label for="name_ar" class="form-label-modern">
                <i class="fa fa-font label-icon"></i>
                اسم الكورس
                <span class="required-asterisk">*</span>
            </label>
            {!! Form::text('name_ar', null, [
                'class' => 'form-control-modern' . ($errors->has('name_ar') ? ' is-invalid' : ''),
                'id' => 'name_ar',
                'placeholder' => 'أدخل اسم الكورس باللغة العربية',
                'required'
            ]) !!}
            @if ($errors->has('name_ar'))
                <div class="invalid-feedback"><i class="fa fa-exclamation-circle"></i>{{ $errors->first('name_ar') }}</div>
            @endif
            <div class="form-help">اختر اسمًا واضحًا ومفهومًا للكورس</div>
        </div>

        @if(isset($enableEnglish) && $enableEnglish)
        <div class="form-group-modern">
            <label for="name_en" class="form-label-modern"><i class="fa fa-font label-icon"></i>اسم الكورس بالإنجليزية</label>
            {!! Form::text('name_en', null, [
                'class' => 'form-control-modern' . ($errors->has('name_en') ? ' is-invalid' : ''),
                'id' => 'name_en',
                'placeholder' => 'Enter course name in English'
            ]) !!}
            @if ($errors->has('name_en'))
                <div class="invalid-feedback"><i class="fa fa-exclamation-circle"></i>{{ $errors->first('name_en') }}</div>
            @endif
        </div>
        @endif
    </div>

    <div class="form-row">
        <div class="form-group-modern{{ isset($enableEnglish) && $enableEnglish ? '' : ' form-group-full' }}">
            <label for="description_ar" class="form-label-modern"><i class="fa fa-align-left label-icon"></i>وصف الكورس</label>
            {!! Form::textarea('description_ar', null, [
                'class' => 'form-control-modern' . ($errors->has('description_ar') ? ' is-invalid' : ''),
                'id' => 'description_ar',
                'placeholder' => 'اكتب ما سيتعلمه الطالب',
                'rows' => 4
            ]) !!}
            @if ($errors->has('description_ar'))
                <div class="invalid-feedback"><i class="fa fa-exclamation-circle"></i>{{ $errors->first('description_ar') }}</div>
            @endif
        </div>

        @if(isset($enableEnglish) && $enableEnglish)
        <div class="form-group-modern">
            <label for="description_en" class="form-label-modern"><i class="fa fa-align-left label-icon"></i>وصف الكورس بالإنجليزية</label>
            {!! Form::textarea('description_en', null, [
                'class' => 'form-control-modern' . ($errors->has('description_en') ? ' is-invalid' : ''),
                'id' => 'description_en',
                'placeholder' => 'Write the course description',
                'rows' => 4
            ]) !!}
            @if ($errors->has('description_en'))
                <div class="invalid-feedback"><i class="fa fa-exclamation-circle"></i>{{ $errors->first('description_en') }}</div>
            @endif
        </div>
        @endif
    </div>

    <div class="form-row">
        <div class="form-group-modern">
            <label for="search_keywords_ar" class="form-label-modern">كلمات البحث العربية</label>
            {!! Form::textarea('search_keywords_ar', null, ['class' => 'form-control-modern', 'id' => 'search_keywords_ar', 'rows' => 2, 'maxlength' => 2000, 'placeholder' => 'مرادفات وعبارات يبحث بها الطالب']) !!}
            <div class="form-help">افصل الكلمات والعبارات بمسافة أو فاصلة</div>
        </div>
        <div class="form-group-modern">
            <label for="search_keywords_en" class="form-label-modern">كلمات البحث الإنجليزية</label>
            {!! Form::textarea('search_keywords_en', null, ['class' => 'form-control-modern', 'id' => 'search_keywords_en', 'rows' => 2, 'maxlength' => 2000]) !!}
        </div>
    </div>
</div>
