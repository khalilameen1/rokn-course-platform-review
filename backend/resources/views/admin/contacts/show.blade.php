@extends('admin.layouts.app')
@section('page.title', $contact->isAccountDeletionRequest() ? 'طلب حذف حساب' : 'تفاصيل الرسالة')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')

<div class="contact-page admin-learning admin-learning--contacts">
    <div class="contact-top">
        <div>
            <h1>{{ $contact->isAccountDeletionRequest() ? 'طلب حذف حساب' : 'تفاصيل الرسالة' }}</h1>
        </div>
        <a class="contact-back" href="{{ route('admin.contacts.index') }}"><i class="fa fa-arrow-right"></i> رسائل الموقع</a>
    </div>

    @if(session('success'))<div class="flash flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="flash flash-error"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @unless($contact->read)
        <form method="POST" action="{{ route('admin.contacts.read', $contact) }}" class="mb-3">
            @csrf
            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
            <button class="btn-rokn btn-muted-rokn" type="submit">تحديد كمقروءة</button>
        </form>
    @endunless

    <section class="contact-card">
        <div class="contact-grid">
            <div class="contact-field"><span>الاسم</span><strong>{{ $contact->name ?: '—' }}</strong></div>
            <div class="contact-field"><span>تاريخ الطلب</span><strong>{{ $contact->created_at ? \App\Support\BusinessClock::format($contact->created_at) : '—' }}</strong></div>
            <div class="contact-field"><span>البريد الإلكتروني</span><a href="mailto:{{ $contact->email }}">{{ $contact->email ?: '—' }}</a></div>
            <div class="contact-field"><span>رقم الهاتف</span><a href="tel:{{ $contact->phone }}">{{ $contact->phone ?: '—' }}</a></div>
        </div>
    </section>

    @if($contact->isAccountDeletionRequest())
        @php
            $status = $contact->resolution_status ?: \App\Models\Contact::RESOLUTION_PENDING;
            $statusLabel = $status === \App\Models\Contact::RESOLUTION_PROCESSING ? 'قيد المعالجة' : ($contact->isResolved() ? 'مغلق' : 'جديد');
            $statusClass = $status === \App\Models\Contact::RESOLUTION_PROCESSING ? 'status-processing' : ($contact->isResolved() ? 'status-closed' : 'status-pending');
            $outcomeLabels = [
                'self_service_completed' => 'حذف صاحب الحساب حسابه من التطبيق',
                'no_account_found' => 'لا يوجد حساب مطابق بعد التحقق',
                'duplicate' => 'طلب مكرر',
                'withdrawn' => 'صاحب الطلب تراجع عنه',
                'manual_verified_deletion' => 'حُذف الحساب بعد التحقق من صاحبه',
            ];
            $outcome = data_get($contact->resolution_metadata, 'outcome');
        @endphp
        <section class="contact-card">
            <div class="request-head">
                <div>
                    <h2>مسار معالجة واضح وقابل للمراجعة</h2>
                    <p>لا يحذف هذا المسار أي حساب تلقائيًا. الحذف يتم من داخل حساب المستخدم بعد التحقق من هويته، ثم تُغلق التذكرة بالنتيجة الصحيحة.</p>
                </div>
                <span class="status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>

            <div class="request-note">{{ $contact->message }}</div>

            @if($deletionUser)
                <div class="account-match">
                    <strong>يوجد حساب مطابق للبريد</strong>
                    <div class="mt-2"><a href="{{ route('admin.users.show', $deletionUser) }}">{{ $deletionUser->name }} · رقم {{ $deletionUser->id }}</a></div>
                    <small class="text-muted">يمكن لصاحب الحساب حذفه من التطبيق. إن تعذر دخوله فتحقق من هويته ثم نفّذ الطلب من المسار الآمن أدناه.</small>
                </div>
            @elseif(!$contact->isResolved())
                <div class="account-match"><strong>لا يوجد حساب نشط مطابق للبريد حاليًا</strong><div class="text-muted mt-1">راجع البريد مع صاحب الطلب قبل اختيار نتيجة الإغلاق.</div></div>
            @endif

            @if($contact->isResolved())
                <div class="resolved-grid">
                    <div class="contact-field"><span>النتيجة</span><strong>{{ $outcomeLabels[$outcome] ?? 'مغلقة بعد المراجعة' }}</strong></div>
                    <div class="contact-field"><span>أغلقها</span><strong>{{ optional($contact->resolver)->name ?: 'مسؤول غير متاح' }}</strong></div>
                    <div class="contact-field"><span>وقت الإغلاق</span><strong>{{ $contact->resolved_at ? \App\Support\BusinessClock::format($contact->resolved_at) : '—' }}</strong></div>
                </div>
                @if(data_get($contact->resolution_metadata, 'note'))
                    <div class="account-match"><strong>ملاحظة المعالجة</strong><div class="mt-1">{{ data_get($contact->resolution_metadata, 'note') }}</div></div>
                @endif
            @else
                <div class="workflow-actions">
                    <div class="workflow-box">
                        <h3>ابدأ المعالجة</h3>
                        <p>سجّل أن أحد أفراد الفريق استلم الطلب وبدأ التحقق منه.</p>
                        <form method="POST" action="{{ route('admin.contacts.processing', $contact) }}">
                            @csrf
                            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                            <button class="btn-rokn btn-primary-rokn" type="submit" {{ $contact->isProcessing() ? 'disabled' : '' }}>
                                {{ $contact->isProcessing() ? 'الطلب قيد المعالجة' : 'بدء المعالجة' }}
                            </button>
                        </form>
                    </div>
                    @if($contact->isProcessing() && $deletionUser)
                        <div class="workflow-box">
                            <h3>تنفيذ الحذف بعد التحقق</h3>
                            <p>استخدم هذا المسار فقط إذا تعذر على صاحب الحساب الدخول إلى التطبيق وتم التحقق من هويته.</p>
                            <form method="POST" action="{{ route('admin.contacts.execute-account-deletion', $contact) }}">
                                @csrf
                                <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                                <input name="account_email" type="email" dir="ltr" autocomplete="off" placeholder="اكتب بريد الحساب للتأكيد" required>
                                <textarea name="verification_note" minlength="8" maxlength="500" placeholder="كيف تحققت من صاحب الحساب؟" required>{{ old('verification_note') }}</textarea>
                                <label class="confirm-row"><input type="checkbox" name="confirm_identity" value="1" required><span>تحققت من أن مقدم الطلب هو صاحب الحساب</span></label>
                                <label class="confirm-row"><input type="checkbox" name="confirm_delete" value="1" required><span>أفهم أن الحذف نهائي وسيُنهي كل جلسات الحساب</span></label>
                                <button class="btn-rokn btn-danger-rokn" type="submit">حذف الحساب نهائيًا</button>
                            </form>
                        </div>
                    @endif
                    <div class="workflow-box">
                        <h3>إغلاق الطلب بعد المراجعة</h3>
                        <form method="POST" action="{{ route('admin.contacts.close-deletion-request', $contact) }}">
                            @csrf
                            <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                            <select name="outcome" required>
                                <option value="">اختر النتيجة</option>
                                @foreach($outcomeLabels as $value => $label)<option value="{{ $value }}" {{ old('outcome') === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach
                            </select>
                            <textarea name="resolution_note" maxlength="500" placeholder="ملاحظة داخلية مختصرة (اختياري)">{{ old('resolution_note') }}</textarea>
                            <label class="confirm-row"><input type="checkbox" name="confirm_close" value="1" required><span>راجعت الطلب واخترت نتيجة تعكس ما حدث فعلًا.</span></label>
                            <button class="btn-rokn btn-muted-rokn" type="submit">حفظ النتيجة وإغلاق الطلب</button>
                        </form>
                    </div>
                </div>
            @endif
        </section>
    @else
        <section class="contact-card">
            <div class="request-head"><div><h2>محتوى الرسالة</h2></div></div>
            <div class="request-note">{{ $contact->message }}</div>
            <div class="normal-actions">
                <a class="btn-rokn btn-primary-rokn" href="mailto:{{ $contact->email }}">الرد عبر البريد</a>
                <form method="POST" action="{{ route('admin.contacts.destroy', $contact) }}" onsubmit="return confirm('هل تريد حذف هذه الرسالة؟')">
                    @csrf @method('DELETE')
                    <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                    <button class="btn-rokn btn-danger-rokn" type="submit">حذف الرسالة</button>
                </form>
            </div>
        </section>
    @endif
</div>
@endsection
