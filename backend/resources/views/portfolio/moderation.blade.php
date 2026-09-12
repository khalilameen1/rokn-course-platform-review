@extends('admin.layouts.app')

@section('page.title', 'مراجعة المعارض')

@section('content')
@php($reviewLabels = ['pending' => 'بانتظار المراجعة', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'suspended' => 'موقوف'])
<div class="admin-page">
    @include('admin.partials.page-header', ['pageTitle' => 'مراجعة المعارض', 'pageDescription' => 'اعتماد النسخة المحددة قبل إتاحتها للآخرين دون حذف الأعمال الخاصة', 'pageIcon' => 'fa-check-square-o'])
    @include('admin.partials.support-inbox-tabs', ['supportSource' => 'portfolio'])
    <nav class="admin-actions mb-4" aria-label="حالة المراجعة">
        @foreach($reviewLabels as $value => $label)
            <a class="btn {{ $status === $value ? 'btn-primary' : 'btn-light' }}" href="{{ route('admin.portfolio-reviews.index', ['status' => $value]) }}" @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="card admin-card"><div class="table-responsive">
        <table class="table admin-table"><thead><tr><th>صاحب المعرض</th><th>الأعمال المحددة</th><th>النسخة</th><th>المراجعة</th></tr></thead><tbody>
            @forelse($owners as $owner)
                <tr><td>{{ $owner->name }}</td><td>{{ $owner->selected_projects_count }}</td><td>{{ $owner->portfolio_sharing_revision }}</td><td><a href="{{ route('admin.portfolio-preview.show', $owner) }}">معاينة ومراجعة</a></td></tr>
            @empty
                <tr><td colspan="4">لا توجد معارض بهذه الحالة</td></tr>
            @endforelse
        </tbody></table>
    </div><div class="card-body">{{ $owners->links() }}</div></div>
</div>
@endsection
