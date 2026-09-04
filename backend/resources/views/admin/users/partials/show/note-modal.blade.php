<div class="modal fade" id="addNoteModal" tabindex="-1" role="dialog" aria-labelledby="addNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content modal-content-modern">
            <form method="POST" action="{{ route('admin.users.notes.store', $user->id) }}" id="studentNoteForm">
                @csrf
                <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="modal-header modal-header-modern">
                    <h5 class="modal-title" id="addNoteModalLabel"><i class="fa fa-sticky-note"></i> إضافة ملاحظة</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body modal-body-modern">
                    <label for="note" class="font-weight-bold">الملاحظة</label>
                    <textarea name="note" id="note" class="form-control" rows="5" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
            @include('admin.partials.course-authoring-draft', ['formId' => 'studentNoteForm'])
        </div>
    </div>
</div>
