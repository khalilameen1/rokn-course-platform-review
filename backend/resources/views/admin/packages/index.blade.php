@extends('admin.layouts.app')

@section('page.title', 'الباقات')

@section('content')
@include('admin.payments.partials.navigation')
<div class="admin-page card">
    <div class="card-header">
        <strong class="card-title">قائمة الباقات</strong>
        <a href="{{ route('admin.packages.create') }}" class="btn btn-primary float-left">
            <i class="fa fa-plus"></i> إضافة باقة
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم (AR)</th>
                    <th>الاسم (EN)</th>
                    <th>السعر</th>
                    <th>العملات (Coins)</th>
                    <th>قنوات الشراء</th>
                    <th>العمليات</th>
                    <th>الصافي المؤكد</th>
                    <th>تاريخ الإنشاء</th>
                    <th>الإدارة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($packages as $package)
                <tr>
                    <td>{{ $package->id }}</td>
                    <td>{{ $package->name_ar }}</td>
                    <td>{{ $package->name_en }}</td>
                    <td>{{ $package->price }}</td>
                    <td>{{ $package->coins }}</td>
                    <td>
                        <span class="badge badge-{{ $package->is_active ? 'success' : 'secondary' }}">{{ $package->is_active ? 'ظاهرة' : 'متوقفة' }}</span>
                        <span class="badge badge-{{ $package->direct_enabled ? 'success' : 'secondary' }}">كاشير</span>
                        <span class="badge badge-{{ $package->google_enabled ? 'success' : 'secondary' }}">Play</span>
                        <span class="badge badge-{{ $package->apple_enabled ? 'success' : 'secondary' }}">App Store</span>
                    </td>
                    <td><a href="{{ route('admin.orders.index', ['package_id' => $package->id]) }}">{{ number_format($package->orders_count) }}</a></td>
                    <td>{{ number_format((float) ($package->confirmed_net_amount ?? 0), 2) }} جنيه</td>
                    <td>{{ $package->created_at->format('Y-m-d') }}</td>
                    <td>
                        <a href="{{ route('admin.packages.show', $package->id) }}" class="btn btn-info btn-sm">
                            <i class="fa fa-eye"></i>
                        </a>
                        <a href="{{ route('admin.packages.edit', $package->id) }}" class="btn btn-warning btn-sm">
                            <i class="fa fa-edit"></i>
                        </a>
                        <form action="{{ route('admin.packages.destroy', $package->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('هل أنت متأكد؟');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="editor_version" value="{{ $editorVersions->get($package->id) }}">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fa fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="10" class="text-center">لا توجد باقات حالياً</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="mt-4">{{ $packages->links() }}</div>
    </div>
</div>
@endsection
