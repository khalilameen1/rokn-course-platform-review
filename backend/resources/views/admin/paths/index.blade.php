@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row page-titles">
        <div class="col-md-5 align-self-center">
            <h4 class="text-themecolor">المسارات</h4>
        </div>
        <div class="col-md-7 align-self-center text-end">
            <div class="d-flex justify-content-end align-items-center">
            
                @if(strtolower(trim((string) auth()->user()?->role)) === 'admin')
                <a href="{{ route('admin.paths.create') }}" class="btn btn-info d-none d-lg-block m-l-15 text-white">
                    <i class="fa fa-plus-circle"></i> إضافة مسار جديد
                </a>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex no-block m-b-10">
                        <h4 class="card-title">قائمة المسارات</h4>
                    </div>
                    
                    <form action="{{ route('admin.paths.index') }}" method="GET" class="m-b-20">
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" placeholder="بحث بعنوان المسار..." value="{{ request('search') }}">
                            <button class="btn btn-info text-white" type="submit">بحث</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>العنوان (عربي)</th>
                                    <th>العنوان (إنجليزي)</th>
                                    <th>الاهتمامات</th>
                                    <th>عدد الدورات</th>
                                    @if(strtolower(trim((string) auth()->user()?->role)) === 'admin')<th>العمليات</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($paths as $path)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $path->title_ar }}</td>
                                        <td>{{ $path->title_en }}</td>
                                        <td>
                                            @foreach($path->interests as $interest)
                                                <span class="badge bg-info">{{ $interest->name_ar }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $path->courses_count ?? $path->courses()->count() }}</td>
                                        @if(strtolower(trim((string) auth()->user()?->role)) === 'admin')<td>
                                            <a href="{{ route('admin.paths.edit', $path->id) }}" class="btn btn-sm btn-warning text-white">
                                                <i class="fa fa-edit"></i> تعديل
                                            </a>
                                            <form action="{{ route('admin.paths.destroy', $path->id) }}" method="POST" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger text-white" onclick="return confirm('هل أنت متأكد من الحذف؟')">
                                                    <i class="fa fa-trash"></i> حذف
                                                </button>
                                            </form>
                                        </td>@endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ strtolower(trim((string) auth()->user()?->role)) === 'admin' ? 6 : 5 }}" class="text-center">لا يوجد مسارات حالياً</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        {{ $paths->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
