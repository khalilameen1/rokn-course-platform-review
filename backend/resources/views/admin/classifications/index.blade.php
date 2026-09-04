@extends('admin.layouts.app')

@section('page.title', 'التصنيفات')

@section('content')
<div class="admin-page card">
    <div class="card-header">
        <strong class="card-title">قائمة التصنيفات</strong>
        @if(strtolower(trim((string) auth()->user()?->role)) === 'admin')
        <a href="{{ route('admin.classifications.create') }}" class="btn btn-primary float-left">
            <i class="fa fa-plus"></i> إضافة تصنيف
        </a>
        @endif
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم (AR)</th>
                    <th>الاسم (EN)</th>
                    <th>صف الرئيسية</th>
                    <th>الترتيب</th>
                    <th>تاريخ الإنشاء</th>
                    @if(strtolower(trim((string) auth()->user()?->role)) === 'admin')<th>العمليات</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse($classifications as $classification)
                <tr>
                    <td>{{ $classification->id }}</td>
                    <td>{{ $classification->name_ar }}</td>
                    <td>{{ $classification->name_en }}</td>
                    <td>{{ $classification->show_on_home ? 'نعم' : 'لا' }}</td>
                    <td>{{ $classification->home_order }}</td>
                    <td>{{ $classification->created_at->format('Y-m-d') }}</td>
                    @if(strtolower(trim((string) auth()->user()?->role)) === 'admin')<td>
                        <a href="{{ route('admin.classifications.edit', $classification->id) }}" class="btn btn-warning btn-sm">
                            <i class="fa fa-edit"></i>
                        </a>
                        <form action="{{ route('admin.classifications.destroy', $classification->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('هل أنت متأكد؟');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fa fa-trash"></i>
                            </button>
                        </form>
                    </td>@endif
                </tr>
                @empty
                <tr>
                    <td colspan="{{ strtolower(trim((string) auth()->user()?->role)) === 'admin' ? 7 : 6 }}" class="text-center">لا توجد تصنيفات حالياً</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
