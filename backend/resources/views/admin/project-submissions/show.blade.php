@extends('admin.layouts.app')

@section('page.title', 'مراجعة محاولة مشروع')

@section('content')
@php
    $section = optional($submission->project)->section;
    $course = optional($section)->course;
    $effortLabels = ['valid' => 'صالح للمراجعة', 'invalid' => 'غير كافٍ', 'unknown' => 'لم يُفحص بعد'];
    $feedbackLevelLabels = ['pass_only' => 'عبور فقط', 'report' => 'تقرير', 'enhanced' => 'تقرير ومتابعة'];
    $reportStatusLabels = [
        'not_included' => 'غير متاح في هذه الفئة', 'not_requested' => 'يصدر بعد قبول المشروع',
        'queued' => 'قيد الإعداد', 'ready' => 'جاهز', 'failed' => 'تعذّر إصداره',
    ];
    $messageStatusLabels = [
        'queued' => 'في الانتظار', 'sent' => 'أُرسل', 'streaming' => 'يُكتب الآن',
        'completed' => 'مكتمل', 'failed' => 'تعذّر', 'cancelled' => 'أُلغي',
    ];
    $reviewSourceLabels = [
        'admin_manual' => 'مراجعة بشرية', 'ai' => 'تقييم ركن',
        'effort_guard' => 'فحص كفاية التسليم', 'graceful_fallback' => 'عبور تلقائي عند تعذّر التقرير',
    ];
@endphp

<div class="admin-page animated fadeIn">
    @include('admin.partials.page-header', [
        'pageTitle' => optional($section)->title ?: 'مشروع #' . $submission->project_id,
        'pageDescription' => 'مراجعة محاولة الطالب وسجل القرار المرتبط بها.',
        'pageIcon' => 'fa-file-text-o',
        'pageActionUrl' => route('admin.project-submissions.index'),
        'pageActionLabel' => 'العودة للمحاولات',
        'pageActionIcon' => 'fa-arrow-right',
        'pageActionClass' => 'btn-light',
    ])

    <div class="row">
        <div class="col-lg-8">
            <div class="card admin-card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>بيانات المحاولة</strong>
                    @include('admin.partials.status-badge', ['badgeStatus' => $submission->review_status])
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <div class="admin-detail-label">الطالب</div>
                            @if($isAdministrator)
                                <div class="admin-detail-value">{{ optional($submission->user)->name ?: 'حساب محذوف' }}</div><small class="text-muted">{{ optional($submission->user)->email }}</small>
                            @else
                                <div class="admin-detail-value">هوية مخفية</div>
                            @endif
                        </div>
                        <div class="col-md-6 mb-3"><div class="admin-detail-label">الكورس</div><div class="admin-detail-value">{{ optional($course)->title ?: 'غير متاح' }}</div></div>
                        <div class="col-md-6 mb-3"><div class="admin-detail-label">رقم المحاولة</div><div class="admin-detail-value admin-code">{{ $submission->public_id }}</div></div>
                        <div class="col-md-3 mb-3"><div class="admin-detail-label">حالة التسليم</div><div class="admin-detail-value">{{ $effortLabels[$submission->effort_status] ?? 'غير معروفة' }}</div></div>
                        <div class="col-md-3 mb-3"><div class="admin-detail-label">وقت الإرسال</div><div class="admin-detail-value">{{ $submission->submitted_at ? \App\Support\BusinessClock::format($submission->submitted_at) : '—' }}</div></div>
                    </div>

                    <h5>النص المرسل</h5>
                    <div class="admin-copy mb-4">{{ $submission->submission_text ?: 'لا يوجد نص مرفق.' }}</div>

                    <h5>ملفات المشروع</h5>
                    @if($submission->aiInputAttachments->isNotEmpty())
                        @foreach($submission->aiInputAttachments as $attachment)
                            <div class="border rounded p-3 mb-2 d-flex flex-wrap justify-content-between align-items-center admin-gap">
                                <div><strong>{{ $attachment->original_file_name }}</strong><br><small class="text-muted">{{ $attachment->mime_type }} · {{ number_format($attachment->size_bytes / 1024, 1) }} KB</small></div>
                                <a href="{{ route('admin.project-submissions.attachments.download', [$submission, $attachment]) }}" class="btn btn-outline-primary"><i class="fa fa-download"></i> تنزيل</a>
                            </div>
                        @endforeach
                    @elseif($submission->submission_file)
                        <a href="{{ route('admin.project-submissions.download', $submission) }}" class="btn btn-outline-primary"><i class="fa fa-download"></i> {{ $submission->original_file_name ?: 'تنزيل الملف' }}</a>
                    @elseif(in_array($submission->review_status, [\App\Models\ProjectSubmission::STATUS_PASSED, \App\Models\ProjectSubmission::STATUS_NEEDS_RESUBMISSION], true))
                        <p class="text-muted">حُذفت ملفات التسليم بعد اكتمال المراجعة.</p>
                    @else
                        <p class="text-muted">لا يوجد ملف مرفق.</p>
                    @endif
                </div>
            </div>

            @if((bool) ($submissionState['report_enabled'] ?? false))
                <div class="card admin-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>تقرير ركن ومحادثة المشروع</strong>
                        <span class="badge badge-light">{{ $feedbackLevelLabels[$submissionState['feedback_level'] ?? ''] ?? 'تقرير المشروع' }}</span>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light mb-3">
                            {{ $reportStatusLabels[$submissionState['report_status'] ?? ''] ?? 'حالة التقرير غير معروفة' }}
                        </div>
                        @forelse($threadMessages as $message)
                            <div class="border rounded p-3 mb-3 {{ $message->role === 'user' ? 'border-primary' : '' }}">
                                <div class="d-flex flex-wrap justify-content-between admin-gap mb-2">
                                    <strong>{{ $message->role === 'user' ? 'الطالب' : 'ركن' }}</strong>
                                    <small class="text-muted">{{ $messageStatusLabels[$message->status] ?? 'حالة غير معروفة' }} · {{ $message->created_at ? \App\Support\BusinessClock::format($message->created_at, 'Y-m-d H:i:s') : '—' }}</small>
                                </div>
                                @if($message->body)<div class="admin-copy mb-2">{{ $message->body }}</div>@endif
                                @foreach($message->getRelation('inputAttachments') as $attachment)
                                    <a class="btn btn-sm btn-outline-primary mb-2" href="{{ route('admin.project-submissions.attachments.download', [$submission, $attachment]) }}">
                                        <i class="fa fa-download"></i> {{ $attachment->original_file_name }}
                                    </a>
                                @endforeach
                                @if($isAdministrator && $message->relationLoaded('usageEvent') && $message->usageEvent)
                                    <div class="small text-muted mt-2">
                                        AI {{ $message->usageEvent->status }} · {{ number_format((int) $message->usageEvent->total_tokens) }} توكن · ${{ number_format((float) $message->usageEvent->cost_usd, 6) }}
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted mb-0">لم يصدر تقرير بعد</p>
                        @endforelse
                    </div>
                </div>
            @endif

            @if($isLatestAttempt && ($submission->review_status === 'pending' || ($submission->review_status === 'passed' && $submission->review_source === 'graceful_fallback')))
                <div class="card admin-card mb-4">
                    <div class="card-header"><strong>قرار المراجعة</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.project-submissions.pass', $submission) }}" class="mb-4" onsubmit="return confirm('تأكيد قبول هذه المحاولة؟ سيتم تحديث تقدم الطالب.');">
                            @csrf
                            <div class="form-group"><label for="pass-feedback">ملاحظة القبول (اختيارية)</label><textarea id="pass-feedback" name="feedback" rows="3" maxlength="2000" class="form-control">{{ old('feedback') }}</textarea></div>
                            <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> قبول المحاولة</button>
                        </form>
                        @if($submission->review_status === 'pending')
                            <hr>
                            <form method="POST" action="{{ route('admin.project-submissions.reject', $submission) }}" onsubmit="return confirm('تأكيد طلب إعادة إرسال المحاولة؟');">
                                @csrf
                                <div class="form-group"><label for="reject-feedback">سبب طلب إعادة الإرسال</label><textarea id="reject-feedback" name="feedback" rows="4" minlength="3" maxlength="2000" required class="form-control">{{ old('feedback') }}</textarea><small class="form-text text-muted">يظهر هذا النص للطالب، لذلك اكتب توجيهًا واضحًا وقابلًا للتنفيذ.</small></div>
                                <button type="submit" class="btn btn-danger"><i class="fa fa-repeat"></i> طلب إعادة الإرسال</button>
                            </form>
                        @else
                            <p class="text-muted mb-0">تم فتح المقطع التالي للطالب ويثبت قبولك المراجعة البشرية</p>
                        @endif
                    </div>
                </div>
            @elseif(!$isLatestAttempt)
                <div class="alert alert-light mb-4">هذه محاولة سابقة<br>راجع المحاولة الأحدث لاتخاذ القرار</div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card admin-card mb-4">
                <div class="card-header"><strong>سجل القرار</strong></div>
                <div class="card-body">
                    <div class="admin-detail-label">مصدر القرار</div><div class="admin-detail-value mb-3">{{ $submission->review_source ? ($reviewSourceLabels[$submission->review_source] ?? 'قرار مسجل') : 'لم يصدر قرار بعد' }}</div>
                    <div class="admin-detail-label">المراجع</div><div class="admin-detail-value mb-3">{{ optional($submission->reviewer)->name ?: 'مراجعة آلية / غير محدد' }}</div>
                    <div class="admin-detail-label">وقت القرار</div><div class="admin-detail-value mb-3">{{ $submission->reviewed_at ? \App\Support\BusinessClock::format($submission->reviewed_at, 'Y-m-d H:i:s') : '—' }}</div>
                    <div class="admin-detail-label">النتيجة</div><div class="admin-detail-value mb-3">{{ $submission->score !== null ? $submission->score . '/100' : '—' }}</div>
                    <div class="admin-detail-label">الملاحظة المسجلة</div><div class="mb-3">{{ $submission->feedback ?: '—' }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
