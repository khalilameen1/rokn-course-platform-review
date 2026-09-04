<div class="section-card-modern">
    <div class="section-header-modern">
        <h3 class="section-title"><i class="fa fa-sticky-note"></i> الملاحظات</h3>
        <button class="btn-action-modern btn-edit" data-toggle="modal" data-target="#addNoteModal">
            <i class="fa fa-plus-circle"></i> إضافة ملاحظة
        </button>
    </div>
    <div class="section-body">
        @forelse($notes as $note)
            <div class="note-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="note-content">
                        <p class="note-text">{{ $note->note }}</p>
                        <div class="note-meta">
                            <span><i class="fa fa-clock-o"></i> {{ \App\Support\BusinessClock::format($note->created_at) }}</span>
                            @if($note->createdBy)
                                <span><i class="fa fa-user"></i> {{ $note->createdBy->name }}</span>
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.users.notes.delete', $note->id) }}" class="admin-inline-form" onsubmit="return confirm('حذف هذه الملاحظة؟')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-delete-note" aria-label="حذف الملاحظة"><i class="fa fa-trash"></i></button>
                    </form>
                </div>
            </div>
        @empty
            <div class="empty-state-modern"><i class="fa fa-sticky-note"></i><h4>لا توجد ملاحظات</h4></div>
        @endforelse

        @if($notes->hasPages())
            <div class="d-flex justify-content-center mt-3">{{ $notes->links() }}</div>
        @endif
    </div>
</div>
