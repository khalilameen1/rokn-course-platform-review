<h4 class="section-title"><i class="fa fa-language"></i> المحتوى العربي</h4>
<div class="form-group">
    <label class="form-label">العنوان <span class="required">*</span></label>
    <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
           value="" placeholder="عنوان المرفق" required>
    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="form-group">
    <label class="form-label">الوصف</label>
    <textarea name="description" class="form-control @error('description') is-invalid @enderror"
              rows="3" placeholder="أدخل وصف الملف (اختياري)"></textarea>
    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<h4 class="section-title mt-4"><i class="fa fa-globe"></i> المحتوى الإنجليزي (اختياري)</h4>
<div class="form-group">
    <label class="form-label">Title (English)</label>
    <input type="text" name="title_en" class="form-control @error('title_en') is-invalid @enderror"
           value="" placeholder="Enter file title in English">
    @error('title_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="form-group">
    <label class="form-label">Description (English)</label>
    <textarea name="description_en" class="form-control @error('description_en') is-invalid @enderror"
              rows="3" placeholder="Enter file description in English"></textarea>
    @error('description_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<h4 class="section-title mt-4"><i class="fa fa-paperclip"></i> المرفق</h4>
<div class="attachment-source-controls">
    <div class="form-group">
        <label class="form-label" for="attachmentSource">نوع المرفق</label>
        <select name="source_type" id="attachmentSource" class="form-control" required>
            <option value="upload">ملف</option>
            <option value="external">رابط</option>
        </select>
        @error('source_type')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="attachmentPlatform">يُستخدم على</label>
        <select name="platform" id="attachmentPlatform" class="form-control" required>
            <option value="mobile">الهاتف</option>
            <option value="computer">الكمبيوتر</option>
        </select>
        @error('platform')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>
</div>
<p class="form-text attachment-delivery-hint" data-attachment-delivery-hint>سيحفظ الطالب الملف على الهاتف</p>

<div class="form-group" data-attachment-upload-fields>
    <label class="form-label">
        اختر الملف
        <span class="required" data-studio-pdf-file-required>*</span>
    </label>
    <div class="file-upload-area" id="dropZone">
        <div class="file-upload-icon"><i class="fa fa-cloud-upload"></i></div>
        <p class="file-upload-text" data-studio-pdf-file-help>اسحب الملف هنا أو انقر للاختيار</p>
        <p class="file-upload-hint">مستندات أو صور أو ZIP حتى 50 ميجابايت</p>
        <input type="file" name="pdf_file" class="file-input @error('pdf_file') is-invalid @enderror"
               id="pdfFile" accept="{{ implode(',', array_map(fn ($extension) => '.'.$extension, config('course_attachments.allowed_upload_extensions', ['pdf']))) }}" required>
    </div>
    <div class="file-preview" id="filePreview">
        <div class="file-preview-icon"><i class="fa fa-file-o"></i></div>
        <div class="file-preview-info">
            <div class="file-preview-name" id="fileName"></div>
            <div class="file-preview-size" id="fileSize"></div>
        </div>
        <button type="button" class="file-preview-remove" id="removeFile" aria-label="إزالة الملف المختار"><i class="fa fa-times"></i></button>
    </div>
    @error('pdf_file')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>

<div class="form-group" data-attachment-external-fields hidden>
    <label class="form-label" for="attachmentExternalUrl">رابط المرفق <span class="required">*</span></label>
    <input type="url" name="external_url" id="attachmentExternalUrl" class="form-control"
           dir="ltr" inputmode="url" placeholder="https://" pattern="https://.+" maxlength="2000" disabled
           aria-describedby="attachmentExternalUrlHint">
    <span class="form-text" id="attachmentExternalUrlHint">رابط مباشر أو مشاركة عامة من Google Drive أو Dropbox</span>
    @error('external_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>

<h4 class="section-title mt-4"><i class="fa fa-cog"></i> الإعدادات</h4>
<div class="settings-card">
    <div class="row">
        <div class="col-6">
            <div class="form-group">
                <label class="form-label">الترتيب</label>
                <input type="number" name="order" class="form-control @error('order') is-invalid @enderror"
                       value="{{ $maxOrder + 1 }}" min="0">
                <span class="form-text">ترتيب الملف في القائمة</span>
                @error('order')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="col-6">
            <div class="form-group">
                <label class="form-label">الحالة</label>
                <div class="toggle-switch-container mt-2">
                    <label class="toggle-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="isActive" value="1"
                               checked>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <span class="toggle-label-text">إظهار المرفق</span>
                        <span class="toggle-label-hint">سيظهر للطلاب عند التفعيل</span>
                    </div>
                    <span class="toggle-status active" id="statusBadge">
                        مفعّل
                    </span>
                </div>
            </div>
        </div>
    </div>
    <p class="form-text mt-3 mb-0">يظهر داخل مرفقات الكورس أثناء المشاهدة</p>
</div>

<div class="form-actions">
    <button type="button" class="btn-cancel" data-studio-pdf-cancel><i class="fa fa-times"></i> إلغاء</button>
    <button type="submit" class="btn-submit"><i class="fa fa-save"></i> <span data-studio-pdf-submit-label>حفظ المرفق</span></button>
</div>
