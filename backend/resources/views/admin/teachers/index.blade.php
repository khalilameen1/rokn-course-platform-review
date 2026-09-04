@extends('admin.layouts.app')

@section('page.title', 'المعلمون')

@section('content')
<div class="admin-page animated fadeIn">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <strong class="card-title">قائمة المعلمين</strong>
                    <a href="{{ route('admin.teachers.create') }}" class="btn btn-primary btn-sm float-left">
                        <i class="fa fa-plus"></i> إضافة معلم جديد
                    </a>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <form action="{{ route('admin.teachers.index') }}" method="GET" class="form-inline">
                            <div class="form-group mb-2">
                                <input type="text" name="search" class="form-control" placeholder="{{ $canManageCredentials ? 'بحث بالاسم أو البريد أو الهاتف' : 'بحث باسم المحاضر' }}" value="{{ request('search') }}">
                            </div>
                            <button type="submit" class="btn btn-secondary mb-2 mr-2">بحث</button>
                        </form>
                    </div>

                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الصورة</th>
                                <th>الاسم</th>
                                @if($canManageCredentials)
                                    <th>البريد الإلكتروني</th>
                                    <th>رقم الهاتف</th>
                                @endif
                                <th>عدد الكورسات</th>
                                <th>الحالة</th>
                                <th>العمليات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($teachers as $teacher)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>
                                    @if($teacher->profile_image_url)
                                        <img src="{{ $teacher->profile_image_url }}" width="50" class="rounded-circle" alt="{{ $teacher->name_ar ?? $teacher->name_en }}">
                                    @else
                                        <img src="{{ asset('assets/images/users/user.png') }}" width="50" class="rounded-circle" alt="">
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $teacher->name_ar ?? $teacher->name_en }}</div>
                                    <small class="text-muted">{{ $teacher->job_title }}</small>
                                </td>
                                @if($canManageCredentials)
                                    <td>{{ $teacher->email }}</td>
                                    <td>{{ $teacher->phone }}</td>
                                @endif
                                <td>
                                    <span class="badge badge-info">{{ $teacher->teaching_courses_count }}</span>
                                </td>
                                <td>
                                    @if($teacher->active)
                                        <span class="badge badge-success">نشط</span>
                                    @else
                                        <span class="badge badge-danger">غير نشط</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="{{ route('admin.teachers.show', $teacher->id) }}" class="btn btn-info btn-sm" title="عرض">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                        <a href="{{ route('admin.teachers.edit', $teacher->id) }}" class="btn btn-primary btn-sm" title="تعديل">
                                            <i class="fa fa-edit"></i>
                                        </a>
                                        <form action="{{ route('admin.teachers.deactive', $teacher->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="expected_active" value="{{ $teacher->active ? 1 : 0 }}">
                                            <button type="submit" class="btn btn-warning btn-sm" title="{{ $teacher->active ? 'تعطيل' : 'تفعيل' }}">
                                                <i class="fa fa-power-off"></i>
                                            </button>
                                        </form>
                                        @if($canDeleteTeacher)
                                            <form action="{{ route('admin.teachers.destroy', $teacher->id) }}" method="POST" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('هل أنت متأكد من الحذف؟')" title="حذف">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="{{ $canManageCredentials ? 8 : 6 }}" class="text-center">لا يوجد معلمين</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="mt-3">
                        {{ $teachers->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
