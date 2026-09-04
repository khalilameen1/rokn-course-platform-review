@php($editedCourse = $course ?? null)
<div class="form-section" id="course-editor-image">
    @include('admin.courses.partials.publishing-area-issues', ['area' => 'image'])
    <h2 class="section-title">
        <div class="section-icon"><i class="fa fa-image"></i></div>
        صورة الكورس
    </h2>

    <div class="form-group-modern">
        <label class="form-label-modern"><i class="fa fa-camera label-icon"></i>صورة الكورس</label>
        @if($editedCourse?->image)
            <div class="course-editor__current-image">
                <img src="{{ $editedCourse->image }}" alt="{{ $editedCourse->name_ar }}" class="current-image">
                <div class="course-editor__image-status"><i class="fa fa-check-circle"></i> الصورة الحالية</div>
            </div>
        @endif
        <label class="file-upload-area" for="image">
            <div class="upload-icon"><i class="fa fa-cloud-upload"></i></div>
            <div class="upload-text">{{ $editedCourse?->image ? 'اضغط لاختيار صورة جديدة أو اسحبها هنا' : 'اضغط لاختيار صورة أو اسحبها هنا' }}</div>
            <div class="upload-subtext">PNG أو JPG أو WebP حتى 6MB وبحد أدنى 640×360</div>
            <input type="file" name="image" id="image" class="file-input-hidden" accept="image/jpeg,image/png,image/webp" data-max-bytes="6291456">
        </label>
        <div id="imagePreview"></div>
        @if ($errors->has('image'))
            <div class="invalid-feedback"><i class="fa fa-exclamation-circle"></i>{{ $errors->first('image') }}</div>
        @endif
    </div>
</div>
