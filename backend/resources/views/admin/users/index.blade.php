@extends('admin.layouts.app')

@section('page.title', 'الطلاب')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.users.partials._dynamic_styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/users-index.css') }}">
@endsection

@section('content')
    <div class="users-container animated fadeIn admin-page users-page">

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                <i class="fa fa-check-circle"></i> {{ session('success') }}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        @endif

        <div class="row">
            <div class="col-12">
                <div class="modern-card">
                    <div class="card-header-modern">
                        <h4>
                            <i class="fa fa-users"></i>
                            إدارة الطلاب
                        </h4>
                        <div class="header-actions">
                            <span class="student-count-badge">
                                <i class="fa fa-database"></i>
                                {{ $users->total() }} طالب
                            </span>
                            <a href="{{ route('admin.notifications.create') }}" class="btn-create-student">
                                <i class="fa fa-bell"></i>
                                إرسال إشعار للجميع
                            </a>
                        </div>
                    </div>

                    <!-- Search Section -->
                    <div class="search-section">
                        <form method="GET" action="{{ route('admin.users.index') }}">
                            <div class="row">
                                <div class="col-lg-4 col-md-12 mb-2">
                                    <div class="search-input-group">
                                        <input type="text" name="search" class="form-control" placeholder="🔍 البحث بالاسم، البريد، أو الجوال..." value="{{ request('search') }}">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6 col-sm-6 mb-2">
                                    <div class="search-input-group">
                                        <select name="active" class="form-control">
                                            <option value="">جميع الحالات</option>
                                            <option value="1" {{ request('active') == '1' ? 'selected' : '' }}>✓ مفعل</option>
                                            <option value="0" {{ request('active') == '0' ? 'selected' : '' }}>✗ غير مفعل</option>
                                        </select>
                                    </div>
                                </div>
                                

                                <div class="col-lg-1 col-md-6 col-sm-6 mb-2">
                                    <button type="submit" class="btn btn-modern btn-modern-primary">
                                        <i class="fa fa-search"></i> <span class="d-none d-lg-inline">بحث</span>
                                    </button>
                                </div>
                                <div class="col-lg-2 col-md-6 col-sm-6 mb-2">
                                    <a href="{{ route('admin.users.index') }}" class="btn btn-modern btn-modern-secondary">
                                        <i class="fa fa-refresh"></i> <span class="d-none d-lg-inline">إعادة تعيين</span>
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Table Container -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>الطالب</th>
                                        <th>الجوال</th>
                                 
                                        <th>الحالة</th>
                                        <th>ملاحظة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($users as $user)
                                        <tr>
                                            <td><a href="{{ route('admin.users.show', $user->id) }}" class="user-names"><strong>#{{ $user->id }}</strong></a></td>
                                            <td>
                                                <div class="user-data">
                                                    <img src="{{ $user->image ? $user->image : '/images/avatar/customer_blank.png' }}" alt="{{ $user->name }}" class="user-avatar">
                                                    <div>
                                                        <a href="{{ route('admin.users.show', $user->id) }}" class="user-names">{{ $user->name }}</a>
                                                        <small class="d-block text-muted user-email">{{ $user->email }}</small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong class="admin-value--ltr d-inline-block">{{ $user->phone }}</strong>
                                            </td>
                                            
                                            <td>
                                                <span class="badge-modern {{ $user->active ? 'badge-success-modern' : 'badge-danger-modern' }}">
                                                    <i class="fa {{ $user->active ? 'fa-check-circle' : 'fa-times-circle' }}"></i>
                                                    {{ $user->active ? 'مفعل' : 'غير مفعل' }}
                                                </span>
                                            </td>
                                            <td class="notes-cell">
                                                <div class="note-container">
                                                    @if($user->latestNote)
                                                        <div class="note-card">
                                                            <div class="note-text">
                                                                <i class="fa fa-sticky-note note-icon"></i>
                                                                <span>{{ Str::limit($user->latestNote->note, 25) }}</span>
                                                            </div>
                                                            <div class="note-date">
                                                                <i class="fa fa-clock-o"></i>
                                                                <span>{{ \App\Support\BusinessClock::format($user->latestNote->created_at, 'Y-m-d') }}</span>
                                                            </div>
                                                            @if(strlen($user->latestNote->note) > 25)
                                                                <div class="note-tooltip">{{ $user->latestNote->note }}</div>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <div class="note-empty">
                                                            <i class="fa fa-file-text-o note-empty-icon"></i>
                                                            <span class="note-empty-text">لا توجد ملاحظات</span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                <div class="dropdown action-dropdown">
                                                    <button class="action-btn" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                        <i class="fa fa-cog"></i>
                                                    </button>
                                                    <div class="dropdown-menu dropdown-menu-modern">
                                                        <a class="dropdown-item-modern" href="{{ route('admin.users.show', $user->id) }}">
                                                            <i class="fa fa-eye"></i>
                                                            <span>عرض التفاصيل</span>
                                                        </a>
                                                        <a class="dropdown-item-modern" href="{{ route('admin.users.edit', $user->id) }}">
                                                            <i class="fa fa-edit"></i>
                                                            <span>تعديل البيانات</span>
                                                        </a>
                                                        <a class="dropdown-item-modern" href="{{ route('admin.student-progress.show', $user->id) }}">
                                                            <i class="fa fa-bar-chart-o"></i>
                                                            <span>تقدم الطالب</span>
                                                        </a>
                                                        <form action="{{ route('admin.users.deactive', $user->id) }}" method="POST" class="m-0">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="expected_active" value="{{ $user->active ? 1 : 0 }}">
                                                            <input type="hidden" name="state_version" value="{{ $accountStateVersions[$user->id] }}">
                                                            <button type="submit" class="dropdown-item-modern border-0 w-100 text-right bg-transparent">
                                                                <i class="fa {{ $user->active ? 'fa-ban' : 'fa-check' }}"></i>
                                                                <span>{{ $user->active ? 'تعطيل الحساب' : 'تفعيل الحساب' }}</span>
                                                            </button>
                                                        </form>
                                                        <div class="dropdown-divider"></div>
                                                        <button
                                                            class="dropdown-item-modern border-0 w-100 text-right bg-transparent"
                                                            type="button"
                                                            data-toggle="modal"
                                                            data-target="#addNoteModal"
                                                            data-note-action="{{ route('admin.users.notes.store', $user->id) }}"
                                                            data-student-name="{{ $user->name }}"
                                                        >
                                                            <i class="fa fa-plus-circle"></i>
                                                            <span>إضافة ملاحظة</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <i class="fa fa-users"></i>
                                                    <h4>لا توجد نتائج</h4>
                                                    <p>لم يتم العثور على أي طلاب مطابقين لمعايير البحث</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>


                    <!-- Pagination Links -->
                    <div class="d-flex justify-content-center users-pagination">
                        {{ $users->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addNoteModal" tabindex="-1" role="dialog" aria-labelledby="addNoteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content modal-content-modern">
                <form method="POST" action="" data-student-note-form>
                    @csrf
                    <input type="hidden" name="authoring_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <div class="modal-header modal-header-modern">
                        <h5 class="modal-title" id="addNoteModalLabel"><i class="fa fa-sticky-note"></i> إضافة ملاحظة</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body modal-body-modern">
                        <label for="student-note" class="font-weight-bold" data-student-note-label>الملاحظة</label>
                        <textarea name="note" id="student-note" class="form-control form-control-modern" rows="5" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-modern btn-modern-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-modern btn-modern-primary">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script src="{{ versioned_asset('admin/assets/js/users-index.js') }}"></script>
@endsection
