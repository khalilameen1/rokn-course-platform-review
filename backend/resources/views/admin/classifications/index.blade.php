@extends('admin.layouts.app')

@section('page.title', 'صفوف الرئيسية')

@section('content')
<div class="admin-page card">
    <div class="card-header">
        <strong class="card-title">صفوف الكورسات في الرئيسية</strong>
        <a href="{{ route('admin.classifications.create') }}" class="btn btn-primary float-left">
            <i class="fa fa-plus"></i> إضافة صف
        </a>
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>العنوان بالعربية</th>
                    <th>العنوان بالإنجليزية</th>
                    <th>الظهور</th>
                    <th>الترتيب</th>
                    <th>الكورسات</th>
                    <th>تاريخ الإنشاء</th>
                    <th>العمليات</th>
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
                    <td>{{ $classification->home_courses_count }}</td>
                    <td>{{ $classification->created_at->format('Y-m-d') }}</td>
                    <td>
                        <a href="{{ route('admin.classifications.edit', $classification->id) }}" class="btn btn-warning btn-sm">
                            <i class="fa fa-edit"></i>
                        </a>
                        @if(strtolower(trim((string) auth()->user()?->role)) === 'admin')
                        <form action="{{ route('admin.classifications.destroy', $classification->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('هل أنت متأكد؟');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fa fa-trash"></i>
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center">لا توجد صفوف في الرئيسية حاليًا</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
