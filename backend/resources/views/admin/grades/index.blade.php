@extends('admin.layouts.app')

@section('page.title', 'المراحل الدراسية')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.grades.partials._dynamic_styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css" integrity="sha384-JMAAyYCa30ppXdV7yVd0xvCueC8MkzDGdJhVfdB5a0cLZB632nCcG+t8jtFM/3rf" crossorigin="anonymous">
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/grades-index.css') }}">
@endsection

@section('content')
    @php($isAdministrator = strtolower(trim((string) auth()->user()?->role)) === 'admin')
    <div class="container-fluid grades-module admin-page">
        <div class="row">
            <div class="col-12">
                <!-- Header Section -->
                <div class="card border-0 shadow-sm fade-in">
                    <div class="grades-header">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h2 class="mb-2">
                                    <i class="fa fa-graduation-cap ml-2"></i>
                                    إدارة المراحل الدراسية
                                </h2>
                                <p class="mb-0 opacity-75">إدارة وتنظيم المراحل الدراسية والكورسات المرتبطة بها</p>
                            </div>
                            @if($isAdministrator)
                            <div class="col-md-6">
                                <div class="text-left">
                                    <a href="{{ route('admin.grades.create') }}" class="btn btn-secondary btn-modern">
                                        <i class="fa fa-plus ml-1"></i>
                                        إضافة مرحلة دراسية جديدة
                                    </a>
                                </div>
                            </div>
                            @endif
                        </div>

                        <!-- Statistics -->
                        <div class="grades-stats row mt-3">
                            <div class="col-md-3 col-6">
                                <div class="stat-item">
                                    <span class="stat-number">{{ $grades->count() }}</span>
                                    <span class="stat-label">إجمالي المراحل</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-item">
                                    <span class="stat-number">{{ $grades->where('type', 'primary')->count() }}</span>
                                    <span class="stat-label">المرحلة الابتدائية</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-item">
                                    <span class="stat-number">{{ $grades->where('type', 'preparatory')->count() }}</span>
                                    <span class="stat-label">المرحلة الإعدادية</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-item">
                                    <span class="stat-number">{{ $grades->where('type', 'secondary')->count() }}</span>
                                    <span class="stat-label">المرحلة الثانوية</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <!-- Grades Table -->
                        <div class="table-responsive">
                            <table class="table table-modern" id="gradesTable">
                                <thead>
                                    <tr>
                                        <th>المرحلة الدراسية</th>
                                        <th>النوع</th>
                                        <th>البلد</th>
                                        <th>عدد الكورسات</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($grades as $grade)
                                        <tr>
                                            <td>
                                                <div>
                                                    @if($grade->name_ar)
                                                        <div class="grade-name">{{ $grade->name_ar }}</div>
                                                    @elseif($grade->name_en)
                                                        <div class="grade-name">{{ $grade->name_en }}</div>
                                                    @endif
                                                    @if($grade->description_ar)
                                                        <p class="grade-description">{{ Str::limit($grade->description_ar, 60) }}</p>
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                @if($grade->type == 'preparatory')
                                                    <span class="grade-type-badge badge-primary-custom text-white">إعدادي</span>
                                                @elseif($grade->type == 'secondary')
                                                    <span class="grade-type-badge badge-success-custom text-white">ثانوي</span>
                                                @elseif($grade->type == 'primary')
                                                    <span class="grade-type-badge badge-info-custom text-white">ابتدائي</span>
                                                @elseif($grade->type == 'university')
                                                    <span class="grade-type-badge badge-university-custom text-white">جامعي</span>
                                                @elseif($grade->type == 'general')
                                                    <span class="grade-type-badge badge-general-custom text-white">عام</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="d-flex align-items-center">
                                                    {{ $grade->country }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="courses-count">
                                                    {{ $grade->courses->count() }} كورس
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="{{ route('admin.grades.courses', $grade->id) }}"
                                                       class="btn btn-primary btn-modern btn-sm"
                                                       title="عرض الكورسات">
                                                        <i class="fa fa-book"></i>
                                                        الكورسات
                                                    </a>
                                                    @if($isAdministrator)
                                                        <a href="{{ route('admin.grades.edit', $grade->id) }}"
                                                           class="btn btn-secondary btn-modern btn-sm"
                                                           title="تعديل المرحلة">
                                                            <i class="fa fa-edit"></i>
                                                            تعديل
                                                        </a>
                                                        <button type="button"
                                                                data-grade-delete
                                                                data-grade-id="{{ $grade->id }}"
                                                                data-grade-name="{{ $grade->name_ar }}"
                                                                class="btn btn-danger btn-modern btn-sm"
                                                                title="حذف المرحلة">
                                                            <i class="fa fa-trash"></i>
                                                            حذف
                                                        </button>
                                                    @endif
                                                </div>

                                                @if($isAdministrator)
                                                    <form hidden id="deleteForm{{$grade->id}}"
                                                          action="{{ route('admin.grades.destroy', $grade->id) }}" method="post">
                                                        <input name="_method" type="hidden" value="DELETE">
                                                        @csrf
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fa fa-graduation-cap fa-3x mb-3 d-block"></i>
                                                    <h5>لا توجد مراحل دراسية</h5>
                                                    <p>ابدأ بإضافة المراحل الدراسية لتنظيم الكورسات</p>
                                                    @if($isAdministrator)
                                                        <a href="{{ route('admin.grades.create') }}" class="btn btn-secondary btn-modern">
                                                            <i class="fa fa-plus ml-1"></i>
                                                            إضافة مرحلة دراسية
                                                        </a>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js" integrity="sha384-ficRBwtap/VLzILv81vIvgp30PoJYnlCm96tPpNYHXAf+h9SIThOZxxIzRUzbpAh" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js" integrity="sha384-bX64nQ/u/Jovgh0rdhdtHy2BMWv9TOOds6b4reiVcJ0KcA76JdIxmwar1pN2NsUj" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js" integrity="sha384-nLoOnA/BDh8A/jxqtckg4DumuCGOBYUnNJLZdQz/zfYNp3wcjGSoWTAzgko06G/2" crossorigin="anonymous"></script>
<script>
$(document).ready(function() {
    let gradesTable = null;
    if ($.fn.DataTable) {
        gradesTable = $('#gradesTable').DataTable({
            "language": {
                "url": "{{ asset('admin/assets/vendor/datatables/i18n/ar.json') }}"
            },
            "order": [[ 0, "asc" ]],
            "pageLength": 10,
            "responsive": true,
            "dom": 'rtip',
            "columnDefs": [
                { "orderable": false, "targets": [4] }
            ]
        });
    }

    // Custom search
    $('#gradesSearch').on('keyup', function() {
        if (gradesTable) {
            gradesTable.search(this.value).draw();
        }
    });

    $('[data-grade-delete]').on('click', function() {
        confirmDelete(this.dataset.gradeId, this.dataset.gradeName);
    });
});

// Confirm delete function
function confirmDelete(gradeId, gradeName) {
    const form = document.getElementById('deleteForm' + gradeId);
    if (!form) {
        return;
    }

    if (typeof window.Swal === 'undefined') {
        if (window.confirm(`هل أنت متأكد من حذف المرحلة الدراسية "${gradeName}"؟`)) {
            form.submit();
        }
        return;
    }

    Swal.fire({
        title: 'تأكيد الحذف',
        text: `هل أنت متأكد من حذف المرحلة الدراسية "${gradeName}"؟`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'نعم، احذف',
        cancelButtonText: 'إلغاء',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}

// Enhanced Button Interactions
document.querySelectorAll('.btn-modern').forEach(btn => {
    // Add ripple effect
    btn.addEventListener('click', function() {
        if (!this.classList.contains('btn-danger')) {
            this.classList.remove('is-rippling');
            void this.offsetWidth;
            this.classList.add('is-rippling');

            // Add loading state
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fa fa-spinner fa-spin ml-1"></i> جاري التحميل...';

            setTimeout(() => {
                this.classList.remove('is-rippling');
                this.innerHTML = originalText;
            }, 2000);
        }
    });
});

// Enhanced Table Interactions
document.querySelectorAll('.table-modern tbody tr').forEach(row => {
    row.addEventListener('click', function(e) {
        // Don't trigger if clicking on buttons
        if (e.target.closest('.btn-modern')) return;

        // Add selection effect
        document.querySelectorAll('.table-modern tbody tr').forEach(r => r.classList.remove('selected'));
        this.classList.add('selected');

        // Show quick actions (optional)
        showQuickActions(this);
    });
});

function showQuickActions(row) {
    // Remove existing quick actions
    document.querySelectorAll('.quick-actions').forEach(qa => qa.remove());
    document.querySelectorAll('.has-quick-actions').forEach(item => item.classList.remove('has-quick-actions'));

    // Create quick actions
    const quickActions = document.createElement('div');
    quickActions.className = 'quick-actions';

    // Add quick action buttons
    const editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.setAttribute('aria-label', 'تعديل المرحلة');
    editBtn.innerHTML = '<i class="fa fa-edit"></i>';
    editBtn.className = 'btn btn-sm btn-light quick-action-button';
    editBtn.onclick = () => {
        const editLink = row.querySelector('a[href*="edit"]');
        if (editLink) editLink.click();
    };

    const coursesBtn = document.createElement('button');
    coursesBtn.type = 'button';
    coursesBtn.setAttribute('aria-label', 'عرض كورسات المرحلة');
    coursesBtn.innerHTML = '<i class="fa fa-book"></i>';
    coursesBtn.className = 'btn btn-sm btn-light quick-action-button';
    coursesBtn.onclick = () => {
        const coursesLink = row.querySelector('a[href*="courses"]');
        if (coursesLink) coursesLink.click();
    };

    if (row.querySelector('a[href*="edit"]')) quickActions.appendChild(editBtn);
    quickActions.appendChild(coursesBtn);

    row.classList.add('has-quick-actions');
    row.appendChild(quickActions);

    // Auto-hide after 5 seconds
    setTimeout(() => {
        quickActions.remove();
        row.classList.remove('has-quick-actions');
    }, 5000);
}

// Enhanced Search with Live Results
let searchTimeout;
document.getElementById('gradesSearch')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const searchTerm = this.value.toLowerCase();

    // Show loading indicator
    const searchIcon = document.querySelector('.search-box .fa-search');
    if (searchIcon) {
        searchIcon.className = 'fa fa-spinner fa-spin';
    }

    searchTimeout = setTimeout(() => {
        $('#gradesTable').DataTable().search(searchTerm).draw();

        // Restore search icon
        if (searchIcon) {
            searchIcon.className = 'fa fa-search';
        }

        // Add search result highlighting
        highlightSearchResults(searchTerm);
    }, 300);
});

function highlightSearchResults(term) {
    // Remove existing highlights
    document.querySelectorAll('.highlight').forEach(el => {
        el.outerHTML = el.innerHTML;
    });

    if (!term) return;

    // Highlight matching text
    document.querySelectorAll('.table-modern tbody td').forEach(cell => {
        const text = cell.textContent;
        const regex = new RegExp(`(${term})`, 'gi');

        if (regex.test(text)) {
            cell.innerHTML = text.replace(regex, '<span class="highlight">$1</span>');
        }
    });
}

// Add keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+N or Cmd+N for new grade
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        const createLink = document.querySelector('a[href*="create"]');
        if (createLink) createLink.click();
    }

    // Escape to clear search
    if (e.key === 'Escape') {
        const searchInput = document.getElementById('gradesSearch');
        if (searchInput) {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
        }

        // Clear selection
        document.querySelectorAll('.selected').forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('.quick-actions').forEach(qa => qa.remove());
    }
});
</script>
@endsection
