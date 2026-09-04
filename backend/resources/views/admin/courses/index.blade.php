@extends('admin.layouts.app')

@section('page.title', 'إدارة الكورسات')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.courses.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/courses-index.css') }}">
@endsection

@section('content')
<div class="admin-page fade-in">
    <!-- Header Section -->
    <div class="courses-management-header">
        <div class="header-content">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
                <div>
                    <h1 class="mb-2">
                        <i class="fa fa-book ml-2"></i>
                        إدارة الكورسات
                    </h1>
                    <p class="mb-0 opacity-75">إدارة شاملة لجميع الكورسات التعليمية</p>
                </div>
                <div class="courses-actions">
                    <a href="{{ route('admin.courses.create') }}" class="btn-modern btn-secondary-modern">
                        <i class="fa fa-plus"></i>
                        إضافة كورس جديد
                    </a>
                </div>
            </div>

            <!-- Statistics Overview -->
            <div class="stats-overview">
                <div class="stat-card">
                    <span class="stat-number">{{ $courses->total() }}</span>
                    <span class="stat-label">إجمالي الكورسات</span>
                </div>
                
            </div>
        </div>
    </div>

    @include('admin.courses.partials.index.course-grid')
    @if ($courses->hasPages())
        <div class="mt-4 d-flex justify-content-center">
            {{ $courses->links() }}
        </div>
    @endif
</div>

@endsection

@section('scripts')
@include('admin.courses.partials.index.scripts')
@endsection
