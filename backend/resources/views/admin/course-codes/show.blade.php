@extends('admin.layouts.app')

@section('page.title', 'تفاصيل الكود')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-codes.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-codes-show.css') }}">
@endsection

@section('content')
<div class="admin-page content course-codes-page">
    <div class="animated fadeIn">
        <!-- Page Header -->
        <div class="page-header modern-header">
            <h1><i class="fa fa-info-circle"></i> تفاصيل الكود</h1>
        </div>

        <div class="row">
            <div class="col-lg-8 col-md-7">
                <div class="info-card">
                    <div class="info-card-header">
                        <h4><i class="fa fa-id-card"></i> معلومات الكود</h4>
                    </div>
                    <div class="info-card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="info-table">
                                    <tr>
                                        <th><i class="fa fa-barcode"></i> الكود:</th>
                                        <td>
                                            <div class="code-display">
                                                <span class="code-badge">{{ $courseCode->code }}</span>
                                                <button type="button" class="copy-code-btn" onclick="copyCodeToClipboard('{{ $courseCode->code }}', this)">
                                                    <i class="fa fa-copy"></i> نسخ
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-tag"></i> الاسم:</th>
                                        <td><strong>{{ $courseCode->name ?: 'غير محدد' }}</strong></td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-cube"></i> النوع:</th>
                                        <td>
                                            @switch($courseCode->type)
                                                @case('course')
                                                    <span class="badge badge-modern course-code-badge--primary">
                                                        <i class="fa fa-graduation-cap"></i> دورة
                                                    </span>
                                                    @break
                                                @case('lesson')
                                                    <span class="badge badge-modern course-code-badge--success">
                                                        <i class="fa fa-book"></i> درس
                                                    </span>
                                                    @break
                                                @case('multiple_lessons')
                                                    <span class="badge badge-modern course-code-badge--warning">
                                                        <i class="fa fa-list"></i> دروس متعددة
                                                    </span>
                                                    @break
                                            @endswitch
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-graduation-cap"></i> الدورة/الدرس:</th>
                                        <td>
                                            @if($courseCode->type === 'multiple_lessons' && $courseCode->lessons()->count() > 0)
                                                <div>
                                                    <strong>{{ $courseCode->course->title ?? 'دورة غير محددة' }}</strong>
                                                    <br>
                                                    <span class="badge badge-modern course-code-badge--warning mt-2">
                                                        <i class="fa fa-list"></i> {{ $courseCode->lessons()->count() }} {{ $courseCode->lessons()->count() == 1 ? 'درس' : 'دروس' }}
                                                    </span>
                                                </div>
                                            @else
                                                <strong>{{ $courseCode->target_content_name }}</strong>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-refresh"></i> الاستخدامات:</th>
                                        <td>
                                            <span class="badge badge-modern course-code-badge--{{ $courseCode->used_count >= $courseCode->max_uses ? 'danger' : 'primary' }} course-code-badge--large">
                                                {{ $courseCode->used_count }}/{{ $courseCode->max_uses }}
                                            </span>
                                            @if($courseCode->remaining_uses > 0)
                                                <br><small class="text-muted mt-1 course-code-usage-note">(متبقي: {{ $courseCode->remaining_uses }})</small>
                                            @else
                                                <br><small class="text-danger mt-1 course-code-usage-note course-code-usage-note--danger">(مستنفذ بالكامل)</small>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-building"></i> النطاقات المؤهلة:</th>
                                        <td>
                                            @php
                                                $eligibleDomains = collect($courseCode->allowed_email_domains ?? [])
                                                    ->map(fn ($domain) => ltrim(mb_strtolower(trim((string) $domain)), '@'))
                                                    ->filter()
                                                    ->unique();
                                            @endphp
                                            @forelse($eligibleDomains as $domain)
                                                <span class="badge badge-modern course-code-badge--domain" dir="ltr">{{ '@' . $domain }}</span>
                                            @empty
                                                <span class="text-muted">متاح لكل الحسابات</span>
                                            @endforelse
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="info-table">
                                    <tr>
                                        <th><i class="fa fa-calendar"></i> تاريخ البداية:</th>
                                        <td>
                                            @if($courseCode->start_date)
                                                <strong>{{ \App\Support\BusinessClock::format($courseCode->start_date, 'Y-m-d') }}</strong>
                                                <br><small class="text-muted">{{ \App\Support\BusinessClock::format($courseCode->start_date, 'H:i') }}</small>
                                            @else
                                                <span class="text-muted">غير محدد</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-calendar-times-o"></i> تاريخ الانتهاء:</th>
                                        <td>
                                            @if($courseCode->expiry_date)
                                                <strong>{{ \App\Support\BusinessClock::format($courseCode->expiry_date, 'Y-m-d') }}</strong>
                                                <br><small class="text-muted">{{ \App\Support\BusinessClock::format($courseCode->expiry_date, 'H:i') }}</small>
                                            @else
                                                <span class="text-muted">غير محدد</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-toggle-on"></i> الحالة:</th>
                                        <td>
                                            @if($courseCode->is_expired)
                                                <span class="badge badge-modern course-code-badge--danger">
                                                    <i class="fa fa-times-circle"></i> منتهي الصلاحية
                                                </span>
                                            @elseif($courseCode->is_not_yet_active)
                                                <span class="badge badge-modern course-code-badge--warning">
                                                    <i class="fa fa-clock-o"></i> لم يبدأ بعد
                                                </span>
                                            @elseif($courseCode->is_active)
                                                <span class="badge badge-modern course-code-badge--success">
                                                    <i class="fa fa-check-circle"></i> مفعل
                                                </span>
                                            @else
                                                <span class="badge badge-modern course-code-badge--muted">
                                                    <i class="fa fa-ban"></i> معطل
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-clock-o"></i> تاريخ الإنشاء:</th>
                                        <td>
                                            <strong>{{ \App\Support\BusinessClock::format($courseCode->created_at, 'Y-m-d') }}</strong>
                                            <br><small class="text-muted">{{ \App\Support\BusinessClock::format($courseCode->created_at, 'H:i:s') }}</small>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><i class="fa fa-history"></i> آخر تحديث:</th>
                                        <td>
                                            <strong>{{ \App\Support\BusinessClock::format($courseCode->updated_at, 'Y-m-d') }}</strong>
                                            <br><small class="text-muted">{{ \App\Support\BusinessClock::format($courseCode->updated_at, 'H:i:s') }}</small>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        @if($courseCode->description)
                            <div class="description-box">
                                <h5><i class="fa fa-align-right"></i> الوصف</h5>
                                <p>{{ $courseCode->description }}</p>
                            </div>
                        @endif

                        @if($courseCode->type === 'multiple_lessons' && $courseCode->lesson_ids)
                            <div class="lessons-list">
                                <h5><i class="fa fa-list"></i> الدروس المحددة ({{ $courseCode->lessons()->count() }})</h5>
                                <ul>
                                    @foreach($courseCode->lessons() as $lesson)
                                        <li><strong>{{ $lesson->title }}</strong></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-5">
                <div class="action-card">
                    <h4><i class="fa fa-cogs"></i> الإجراءات</h4>
                    <a href="{{ route('admin.course-codes.edit', $courseCode) }}" class="btn action-btn course-code-action--primary">
                        <i class="fa fa-edit"></i> تعديل الكود
                    </a>
                    <form method="POST" action="{{ route('admin.course-codes.destroy', $courseCode) }}" class="course-code-action-form">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                        <button type="submit" class="btn action-btn course-code-action--danger" onclick="return confirm('هل أنت متأكد من حذف هذا الكود؟ لا يمكن التراجع عن هذا الإجراء.')">
                            <i class="fa fa-trash"></i> حذف الكود
                        </button>
                    </form>
                    <a href="{{ route('admin.course-codes.index') }}" class="btn action-btn course-code-action--muted">
                        <i class="fa fa-arrow-right"></i> رجوع للقائمة
                    </a>
                </div>

                @if(method_exists($courseCode, 'isValid') && $courseCode->isValid())
                    <div class="status-card course-code-status-card--valid">
                        <h4 class="course-code-status-text--valid">حالة الكود</h4>
                        <div class="status-icon-wrapper">
                            <i class="fa fa-check-circle course-code-status-icon--success"></i>
                        </div>
                        <p class="course-code-status-text--valid">الكود صالح للاستخدام</p>
                    </div>
                @else
                    <div class="status-card">
                        <h4 class="course-code-status-text--danger">حالة الكود</h4>
                        @if($courseCode->is_expired)
                            <div class="status-icon-wrapper">
                                <i class="fa fa-times-circle course-code-status-icon--danger"></i>
                            </div>
                            <p class="course-code-status-text--danger">الكود منتهي الصلاحية</p>
                        @elseif($courseCode->is_not_yet_active)
                            <div class="status-icon-wrapper">
                                <i class="fa fa-clock-o course-code-status-icon--warning"></i>
                            </div>
                            <p class="course-code-status-text--warning">الكود لم يبدأ بعد</p>
                        @elseif(!$courseCode->is_active)
                            <div class="status-icon-wrapper">
                                <i class="fa fa-ban course-code-status-icon--muted"></i>
                            </div>
                            <p class="course-code-status-text--muted">الكود معطل</p>
                        @elseif($courseCode->used_count >= $courseCode->max_uses)
                            <div class="status-icon-wrapper">
                                <i class="fa fa-times-circle course-code-status-icon--danger"></i>
                            </div>
                            <p class="course-code-status-text--danger">تم استنفاذ جميع مرات الاستخدام</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>


        <!-- Usage History Section -->
        <div class="usage-section">
            <div class="row">
                <div class="col-md-12">
                    <div class="info-card">
                        <div class="info-card-header">
                            <h4><i class="fa fa-history"></i> سجل الاستخدام ({{ $usageHistory->total() }})</h4>
                        </div>
                        <div class="info-card-body course-code-info-body--flush">
                            @if($usageHistory->count() > 0)
                                <div class="usage-table-wrapper">
                                    <div class="table-responsive">
                                        <table class="usage-table">
                                            <thead>
                                                <tr>
                                                    <th width="50">#</th>
                                                    <th>المستخدم</th>
                                                    <th>تاريخ الاستخدام</th>
                                                    <th>بصمة الشبكة</th>
                                                    <th>بصمة الجهاز</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($usageHistory as $index => $usage)
                                                    <tr>
                                                        <td>
                                                            <strong>{{ ($usageHistory->firstItem() ?? 1) + $index }}</strong>
                                                        </td>
                                                        <td>
                                                            @if($usage->user)
                                                                <div class="user-info">
                                                                    <div class="user-avatar">
                                                                        {{ strtoupper(substr($usage->user->name ?: $usage->user->email, 0, 2)) }}
                                                                    </div>
                                                                    <div>
                                                                        <strong>{{ $usage->user->name ?: $usage->user->email }}</strong>
                                                                        @if($usage->user->name && $usage->user->email)
                                                                            <br><small class="text-muted">{{ $usage->user->email }}</small>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @else
                                                                <span class="text-muted"><i class="fa fa-user-times"></i> مستخدم محذوف</span>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            <strong>{{ $usage->used_at->format('Y-m-d') }}</strong>
                                                            <br><small class="text-muted">{{ $usage->used_at->format('H:i:s') }}</small>
                                                            <br><small class="text-muted">{{ \App\Support\BusinessClock::relative($usage->used_at) }}</small>
                                                        </td>
                                                        <td>
                                                            @if($usage->ip_address)
                                                                <code class="course-code-ip">{{ $usage->ip_address }}</code>
                                                            @else
                                                                <span class="text-muted">غير محدد</span>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @if($usage->user_agent)
                                                                <small class="text-muted" title="{{ $usage->user_agent }}">
                                                                    {{ Str::limit($usage->user_agent, 60) }}
                                                                </small>
                                                            @else
                                                                <span class="text-muted">غير محدد</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                @if($usageHistory->hasPages())
                                    <div class="p-3">{{ $usageHistory->links() }}</div>
                                @endif
                            @else
                                <div class="empty-state">
                                    <i class="fa fa-inbox"></i>
                                    <p>لا يوجد سجل استخدام لهذا الكود</p>
                                    <small class="text-muted">سيظهر هنا سجل الاستخدام عندما يقوم المستخدمون باستخدام هذا الكود</small>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
// Copy code to clipboard function
function copyCodeToClipboard(code, button) {
    // Modern Clipboard API
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(function() {
            showCopySuccess(button);
        }).catch(function(err) {
            fallbackCopy(code, button);
        });
    } else {
        // Fallback for older browsers
        fallbackCopy(code, button);
    }
}

// Fallback copy method
function fallbackCopy(text, button) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.top = '0';
    textarea.style.left = '0';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    try {
        const successful = document.execCommand('copy');
        if (successful) {
            showCopySuccess(button);
        } else {
            alert('فشل النسخ. يرجى نسخ الكود يدوياً.');
        }
    } catch (err) {
        alert('فشل النسخ. يرجى نسخ الكود يدوياً.');
    }

    document.body.removeChild(textarea);
}

// Show copy success feedback
function showCopySuccess(button) {
    const originalHTML = button.innerHTML;
    const originalClass = button.className;

    button.innerHTML = '<i class="fa fa-check"></i> تم النسخ!';
    button.classList.add('copied');
    button.disabled = true;

    setTimeout(function() {
        button.innerHTML = originalHTML;
        button.className = originalClass;
        button.disabled = false;
    }, 2000);
}

// Auto-scroll to usage section if there's a hash
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#usage') {
        const usageSection = document.querySelector('.usage-section');
        if (usageSection) {
            setTimeout(function() {
                usageSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 300);
        }
    }
});
</script>
@endsection

