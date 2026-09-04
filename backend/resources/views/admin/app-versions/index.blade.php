@extends('admin.layouts.app')

@section('page.title', 'إصدارات التطبيق')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/app-versions.css') }}">
@endsection

@section('content')
<div class="card admin-card app-versions-page">
    <div class="card-header">
        <strong class="card-title">قائمة إصدارات التطبيق</strong>
        <a href="{{ route('admin.app-versions.create') }}" class="btn btn-primary float-left">
            <i class="fa fa-plus"></i> إضافة إصدار جديد
        </a>
    </div>
    <div class="card-body">
        @php($channelLabels = ['play' => 'Google Play', 'direct' => 'Android مباشر', 'appstore' => 'App Store'])
        <div class="row mb-3">
            @foreach($releaseReadiness['channels'] as $channel => $status)
                <div class="col-md-4 mb-2">
                    <div class="alert mb-0 {{ $status['ready'] ? 'alert-success' : 'alert-warning' }}">
                        <strong>{{ $channelLabels[$channel] ?? $channel }}</strong>
                        <div>
                            @if($status['ready'])
                                رابط التحديث جاهز
                            @elseif($status['reason'] === 'invalid_download_url')
                                رابط الإصدار النشط لا يطابق القناة
                            @else
                                لا يوجد إصدار نشط صالح
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المنصة</th>
                        <th>قناة التوزيع</th>
                        <th>اسم الإصدار</th>
                        <th>كود الإصدار / رقم البناء</th>
                        <th>تحديث إجباري</th>
                        <th>الحالة</th>
                        <th>تاريخ الإنشاء</th>
                        <th>العمليات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($versions as $version)
                    <tr>
                        <td>{{ $version->id }}</td>
                        <td>
                            <span class="badge badge-{{ $version->platform == 'android' ? 'success' : 'info' }}">
                                {{ ucfirst($version->platform) }}
                            </span>
                        </td>
                        <td>
                            @php($channel = $version->distribution_channel ?: 'قديم غير محدد')
                            <span class="badge badge-secondary">
                                {{ ['play' => 'Google Play', 'direct' => 'Android مباشر', 'appstore' => 'App Store'][$channel] ?? $channel }}
                            </span>
                        </td>
                        <td>{{ $version->version_name }}</td>
                        <td>{{ $version->platform == 'android' ? $version->version_code : $version->build_number }}</td>
                        <td>
                            <span class="badge badge-{{ $version->is_force_update ? 'danger' : 'secondary' }}">
                                {{ $version->is_force_update ? 'نعم' : 'لا' }}
                            </span>
                        </td>
                        <td>
                            <form action="{{ route('admin.app-versions.toggle-active', $version->id) }}" method="POST">
                                @csrf
                                <input type="hidden" name="editor_version" value="{{ $editorVersions->get($version->id) }}">
                                <button type="submit" class="btn btn-sm btn-{{ $version->is_active ? 'success' : 'secondary' }}">
                                    {{ $version->is_active ? 'نشط' : 'غير نشط' }}
                                </button>
                            </form>
                        </td>
                        <td>{{ $version->created_at->format('Y-m-d') }}</td>
                        <td>
                            @if($version->distribution_channel)
                                <a href="{{ route('admin.app-versions.edit', $version->id) }}" class="btn btn-warning btn-sm" aria-label="تعديل الإصدار">
                                    <i class="fa fa-edit"></i>
                                </a>
                            @else
                                <span class="text-muted small">سجل قديم</span>
                            @endif
                            <form action="{{ route('admin.app-versions.destroy', $version->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('هل أنت متأكد؟');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="editor_version" value="{{ $editorVersions->get($version->id) }}">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center">لا توجد إصدارات حالياً</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $versions->links() }}
        </div>
    </div>
</div>
@endsection
