@extends('admin.layouts.app')

@section('page.title', 'إدارة الأكواد')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-codes.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/course-codes-index.css') }}">
@endsection

@section('content')
<div class="admin-page content course-codes-page">
    <div class="animated fadeIn">
        <!-- Page Header -->
        <div class="page-header modern-header">
            <div class="header-content">
                <div class="header-text">
                    <h1><i class="fa fa-code"></i> إدارة الأكواد</h1>
                    <p>إدارة وتتبع أكواد الدورات والدروس بسهولة وفعالية</p>
                </div>
                <div class="header-buttons">
                    <a href="{{ route('admin.course-codes.create') }}" class="btn">
                        <i class="fa fa-plus-circle"></i> إنشاء كود جديد
                    </a>
                    <a href="{{ route('admin.course-codes.export') }}" class="btn">
                        <i class="fa fa-download"></i> تصدير CSV
                    </a>
                    <a href="{{ route('admin.course-codes.export-pdf') }}" class="btn">
                        <i class="fa fa-file-pdf-o"></i> تصدير PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filter-section">
            <div class="filter-section-header" onclick="toggleFilterSection()">
                <h5>
                    <i class="fa fa-filter"></i>
                    البحث والتصفية
                </h5>
                <i class="fa fa-chevron-down toggle-icon" id="filter-toggle-icon"></i>
            </div>
            <div class="filter-section-body" id="filter-section-body">
                <form method="GET" action="{{ route('admin.course-codes.index') }}">
                    <div class="filter-grid">
                    <div class="form-group">
                        <input type="text" name="code" class="form-control" placeholder="🔍 البحث بالكود" value="{{ request('code') }}">
                    </div>
                    <div class="form-group">
                        <input type="text" name="name" class="form-control" placeholder="🔍 البحث بالاسم" value="{{ request('name') }}">
                    </div>
                    <div class="form-group">
                        <select name="type" class="form-control">
                            <option value="">جميع الأنواع</option>
                            <option value="course" {{ request('type') == 'course' ? 'selected' : '' }}>دورة</option>
                            <option value="lesson" {{ request('type') == 'lesson' ? 'selected' : '' }}>درس</option>
                            <option value="multiple_lessons" {{ request('type') == 'multiple_lessons' ? 'selected' : '' }}>دروس متعددة</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <select name="course_id" class="form-control">
                            <option value="">جميع الدورات</option>
                            @foreach($courses as $course)
                                <option value="{{ $course->id }}" {{ request('course_id') == $course->id ? 'selected' : '' }}>
                                    {{ $course->name_ar }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <select name="status" class="form-control">
                            <option value="">جميع الحالات</option>
                            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>مفعل</option>
                            <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>معطل</option>
                            <option value="expired" {{ request('status') == 'expired' ? 'selected' : '' }}>منتهي الصلاحية</option>
                            <option value="not_yet_active" {{ request('status') == 'not_yet_active' ? 'selected' : '' }}>لم يبدأ بعد</option>
                        </select>
                    </div>
                </div>
                    <div class="filter-buttons">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-search"></i> بحث
                        </button>
                        <a href="{{ route('admin.course-codes.index') }}" class="btn btn-secondary">
                            <i class="fa fa-refresh"></i> إعادة تعيين
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modern Table -->
        <form id="bulk-action-form" method="POST" action="{{ route('admin.course-codes.bulk-action') }}">
            @csrf
            <div class="modern-table">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th width="30">
                                    <input type="checkbox" id="select-all">
                                </th>
                                <th class="text-center">الكود</th>
                                <th class="text-center">الاسم</th>
                                <th class="text-center">النوع</th>
                                <th class="text-center">الدورة/الدرس</th>
                                <th class="text-center">النطاقات المؤهلة</th>
                                <th class="text-center">تاريخ البداية</th>
                                <th class="text-center">تاريخ الانتهاء</th>
                                <th class="text-center">الاستخدامات</th>
                                <th class="text-center course-code-status-column">الحالة</th>
                                <th class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($courseCodes as $code)
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_codes[]" value="{{ $code->id }}" class="code-checkbox">
                                    <input type="hidden" name="editor_versions[{{ $code->id }}]" value="{{ $editorVersions->get($code->id) }}">
                                </td>
                                <td>
                                    <div class="course-code-value">
                                        <span class="badge badge-modern badge-info-modern">{{ $code->code }}</span>
                                        <button type="button" class="btn btn-sm btn-secondary copy-code-btn"
                                                onclick="copyToClipboard('{{ $code->code }}', this)"
                                                title="نسخ الكود">
                                            <i class="fa fa-copy"></i>
                                        </button>
                                    </div>
                                </td>
                                <td><strong>{{ $code->name ?: 'غير محدد' }}</strong></td>
                                <td>
                                    @switch($code->type)
                                        @case('course')
                                            <span class="badge badge-modern badge-primary-modern">دورة</span>
                                            @break
                                        @case('lesson')
                                            <span class="badge badge-modern badge-danger-modern">نوع قديم متوقف</span>
                                            @break
                                        @case('multiple_lessons')
                                            <span class="badge badge-modern badge-danger-modern">نوع قديم متوقف</span>
                                            @break
                                    @endswitch
                                    @if($code->isInstitutionalGrant())
                                        <span class="badge badge-modern course-code-grant-badge">منحة كلية</span>
                                    @endif
                                </td>
                                <td>
                                    @if($code->type === 'multiple_lessons')
                                        <div class="course-code-restrictions">
                                            <strong>{{ $code->course ? $code->course->name_ar : 'دورة محذوفة' }}</strong>
                                            <br>
                                            <small class="text-muted">
                                                <a href="{{ route('admin.course-codes.show', $code) }}" class="text-info" title="عرض التفاصيل">
                                                    <i class="fa fa-info-circle"></i>
                                                </a>
                                                {{ count($code->lesson_ids ?? []) }} درس
                                            </small>
                                        </div>
                                    @else
                                        {{ $code->target_content_name }}
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $eligibleDomains = collect($code->allowed_email_domains ?? [])
                                            ->map(fn ($domain) => ltrim(mb_strtolower(trim((string) $domain)), '@'))
                                            ->filter()
                                            ->unique();
                                    @endphp
                                    @forelse($eligibleDomains as $domain)
                                        <span class="badge badge-modern badge-info-modern" dir="ltr">{{ '@' . $domain }}</span>
                                    @empty
                                        <span class="text-muted">كل الحسابات</span>
                                    @endforelse
                                </td>
                                <td>
                                    @if($code->start_date)
                                        <small>{{ \App\Support\BusinessClock::format($code->start_date, 'Y-m-d') }}</small>
                                    @else
                                        <span class="text-muted">غير محدد</span>
                                    @endif
                                </td>
                                <td>
                                    @if($code->expiry_date)
                                        <small>{{ \App\Support\BusinessClock::format($code->expiry_date, 'Y-m-d') }}</small>
                                    @else
                                        <span class="text-muted">غير محدد</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-modern {{ $code->used_count >= $code->max_uses ? 'badge-danger-modern' : 'badge-info-modern' }}">
                                        {{ $code->used_count }}/{{ $code->max_uses }}
                                    </span>
                                </td>
                                <td class="course-code-status-column">
                                    {{--
                                        DYNAMIC STATUS CALCULATION (No Database Update Required)
                                        Status is calculated on-the-fly based on:
                                        1. is_expired: expiry_date < now() (from model accessor)
                                        2. is_not_yet_active: start_date > now() (from model accessor)
                                        3. used_count >= max_uses (from database field)
                                        4. is_active: manual admin toggle (from database field)

                                        Priority order:
                                        1. Expired (highest priority - red badge)
                                        2. Not yet active (yellow badge)
                                        3. Max uses reached (red badge)
                                        4. Active (green badge)
                                        5. Inactive (gray badge - manual disable)
                                    --}}
                                    @if($code->type !== 'course')
                                        <span class="badge badge-modern badge-danger-modern">متوقف نهائيًا</span>
                                    @elseif($code->is_expired)
                                        <span class="badge badge-modern badge-danger-modern">منتهي الصلاحية</span>
                                    @elseif($code->is_not_yet_active)
                                        <span class="badge badge-modern badge-warning-modern">لم يبدأ بعد</span>
                                    @elseif($code->used_count >= $code->max_uses)
                                        <span class="badge badge-modern badge-danger-modern">مستخدم</span>
                                    @elseif($code->is_active)
                                        <span class="badge badge-modern badge-success-modern">مفعل</span>
                                    @else
                                        <span class="badge badge-modern badge-danger-modern">معطل</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="{{ route('admin.course-codes.show', $code) }}" class="btn btn-sm btn-primary" title="عرض">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                        @if($code->type === 'course')
                                            <a href="{{ route('admin.course-codes.edit', $code) }}" class="btn btn-sm btn-secondary" title="تعديل">
                                                <i class="fa fa-edit"></i>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center course-code-empty">
                                    <i class="fa fa-inbox course-code-empty__icon"></i>
                                    <p class="text-muted mt-3">لا توجد أكواد</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions">
            <div class="bulk-actions-content">
                <label class="course-code-bulk-label">
                    <i class="fa fa-tasks"></i> الإجراءات الجماعية:
                </label>
                <select name="action" class="form-control" required>
                    <option value="">اختر الإجراء</option>
                    <option value="delete">حذف المحدد</option>
                </select>
                <button type="submit" class="btn btn-warning" onclick="return confirmBulkAction()">
                    <i class="fa fa-bolt"></i> تطبيق
                </button>
                <span id="selected-count" class="course-code-selected-count">لم يتم تحديد أي عنصر</span>
            </div>
        </div>
    </form>

        <!-- Pagination -->
        <div class="d-flex justify-content-center mt-4 mb-4">
            {{ $courseCodes->appends(request()->query())->links() }}
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
// Toggle filter section
function toggleFilterSection() {
    const filterBody = document.getElementById('filter-section-body');
    const toggleIcon = document.getElementById('filter-toggle-icon');

    filterBody.classList.toggle('show');
    toggleIcon.classList.toggle('rotated');

    // Save state to localStorage
    localStorage.setItem('filterSectionOpen', filterBody.classList.contains('show'));
}

// Restore filter section state on page load
document.addEventListener('DOMContentLoaded', function() {
    const filterBody = document.getElementById('filter-section-body');
    const toggleIcon = document.getElementById('filter-toggle-icon');
    const isOpen = localStorage.getItem('filterSectionOpen');

    // Open by default if there are active filters or if previously opened
    const hasActiveFilters = '{{ request()->anyFilled(["code", "name", "type", "course_id", "status"]) }}';
    if (isOpen === 'true' || hasActiveFilters) {
        filterBody.classList.add('show');
        toggleIcon.classList.add('rotated');
    }
});

// Global copy function
function copyToClipboard(text, button) {

    // Method 1: Modern Clipboard API
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            showCopySuccess(button);
        }).catch(function(err) {
            fallbackCopy(text, button);
        });
    } else {
        // Method 2: Fallback for older browsers
        fallbackCopy(text, button);
    }
}

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
            alert('فشل النسخ');
        }
    } catch (err) {
        alert('فشل النسخ: ' + err.message);
    }

    document.body.removeChild(textarea);
}

function showCopySuccess(button) {
    const originalHtml = button.innerHTML;
    const originalClass = button.className;

    button.innerHTML = '<i class="fa fa-check"></i>';
    button.classList.add('copied');
    button.disabled = true;

    setTimeout(function() {
        button.innerHTML = originalHtml;
        button.className = originalClass;
        button.disabled = false;
    }, 2000);
}

// DOM Content Loaded for checkboxes
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('select-all');
    const codeCheckboxes = document.querySelectorAll('.code-checkbox');
    const selectedCountSpan = document.getElementById('selected-count');


    // Update selected count and select-all state
    function updateSelectedCount() {
        const checkedBoxes = document.querySelectorAll('.code-checkbox:checked');
        const checkedCount = checkedBoxes.length;
        const totalCount = codeCheckboxes.length;

        // Update count text
        if (checkedCount === 0) {
            selectedCountSpan.textContent = 'لم يتم تحديد أي عنصر';
            selectedCountSpan.style.color = '#6c757d';
        } else {
            selectedCountSpan.textContent = 'تم تحديد ' + checkedCount + ' من ' + totalCount + ' عنصر';
            selectedCountSpan.style.color = '#2563eb';
        }

        // Update select-all checkbox state
        if (selectAllCheckbox) {
            if (checkedCount === totalCount && totalCount > 0) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedCount > 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            }
        }
    }

    // Select all functionality
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;

            codeCheckboxes.forEach(function(checkbox) {
                checkbox.checked = isChecked;
            });

            updateSelectedCount();
        });
    }

    // Individual checkbox change
    codeCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateSelectedCount();
        });
    });

    // Initial update
    updateSelectedCount();
});

// Confirm bulk action
function confirmBulkAction() {
    const checkedCount = document.querySelectorAll('.code-checkbox:checked').length;

    if (checkedCount === 0) {
        alert('يرجى تحديد عنصر واحد على الأقل');
        return false;
    }

    const action = document.querySelector('select[name="action"]').value;
    if (!action) {
        alert('يرجى اختيار الإجراء المطلوب');
        return false;
    }

    // Only delete action is available now
    return confirm('هل أنت متأكد من حذف ' + checkedCount + ' عنصر؟');
}
</script>
@endsection
