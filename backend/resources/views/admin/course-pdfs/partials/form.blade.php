<h4 class="section-title"><i class="fa fa-language"></i> المحتوى العربي</h4>
<div class="form-group">
    <label class="form-label">العنوان <span class="required">*</span></label>
    <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
           value="" placeholder="أدخل عنوان الملف" required>
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

<h4 class="section-title mt-4"><i class="fa fa-file-pdf-o"></i> ملف PDF</h4>
<div class="form-group">
    <label class="form-label">
        اختر ملف PDF
        <span class="required" data-studio-pdf-file-required>*</span>
    </label>
    <div class="file-upload-area" id="dropZone">
        <div class="file-upload-icon"><i class="fa fa-cloud-upload"></i></div>
        <p class="file-upload-text" data-studio-pdf-file-help>اسحب الملف هنا أو انقر للاختيار</p>
        <p class="file-upload-hint">الحد الأقصى: 50 ميجابايت - PDF فقط</p>
        <input type="file" name="pdf_file" class="file-input @error('pdf_file') is-invalid @enderror"
               id="pdfFile" accept=".pdf" required>
    </div>
    <div class="file-preview" id="filePreview">
        <div class="file-preview-icon"><i class="fa fa-file-pdf-o"></i></div>
        <div class="file-preview-info">
            <div class="file-preview-name" id="fileName"></div>
            <div class="file-preview-size" id="fileSize"></div>
        </div>
        <button type="button" class="file-preview-remove" id="removeFile"><i class="fa fa-times"></i></button>
    </div>
    @error('pdf_file')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
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
                        <span class="toggle-label-text">تفعيل الملف</span>
                        <span class="toggle-label-hint">سيظهر الملف للطلاب عند التفعيل</span>
                    </div>
                    <span class="toggle-status active" id="statusBadge">
                        مفعّل
                    </span>
                </div>
            </div>
        </div>
    </div>
    <p class="form-text mt-3 mb-0">يظهر الملف داخل زر مرفقات الكورس أثناء المشاهدة</p>
</div>

<div class="form-actions">
    <button type="button" class="btn-cancel" data-studio-pdf-cancel><i class="fa fa-times"></i> إلغاء</button>
    <button type="submit" class="btn-submit"><i class="fa fa-save"></i> <span data-studio-pdf-submit-label>حفظ الملف</span></button>
</div>
