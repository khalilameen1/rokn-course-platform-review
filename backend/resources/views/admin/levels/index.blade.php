@extends('admin.layouts.app')

@section('page.title', 'مستويات الدورات')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/levels-dashboard.css') }}">
@endsection

@section('content')
@php($canManageLevels = strtolower(trim((string) optional(auth()->user())->role)) === 'admin')
<div class="admin-page levels-page card">
    <div class="card-header">
        <strong class="card-title">قائمة المستويات</strong>
        @if($canManageLevels)
        <a href="{{ route('admin.levels.create') }}" class="btn btn-primary float-left">
            <i class="fa fa-plus"></i> إضافة مستوى
        </a>
        @endif
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الشعار / الوسام</th>
                    <th>الاسم (AR)</th>
                    <th>الاسم (EN)</th>
                    <th>الترتيب</th>
                    <th>تاريخ الإنشاء</th>
                    @if($canManageLevels)
                        <th>العمليات</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($levels as $level)
                <tr>
                    <td>{{ $level->id }}</td>
                    <td>
                        @if($level->badge_image_url)
                            <img src="{{ $level->badge_image_url }}" alt="{{ $level->name_en }}" class="levels-page__badge levels-page__badge--list">
                        @else
                            <span class="badge badge-secondary">لا يوجد</span>
                        @endif
                    </td>
                    <td>{{ $level->name_ar }}</td>
                    <td>{{ $level->name_en }}</td>
                    <td>{{ $level->order }}</td>
                    <td>{{ $level->created_at->format('Y-m-d') }}</td>
                    @if($canManageLevels)
                    <td>
                        <a href="{{ route('admin.levels.edit', $level->id) }}" class="btn btn-warning btn-sm">
                            <i class="fa fa-edit"></i>
                        </a>
                        <form action="{{ route('admin.levels.destroy', $level->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('هل أنت متأكد؟');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fa fa-trash"></i>
                            </button>
                        </form>
                    </td>
                    @endif
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $canManageLevels ? 7 : 6 }}" class="text-center">لا توجد مستويات حالياً</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
