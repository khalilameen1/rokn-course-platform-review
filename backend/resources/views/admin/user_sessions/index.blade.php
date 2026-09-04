@extends('admin.layouts.app')

@section('page.title', 'جلسات الأجهزة')

@section('content')
<div class="admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'جلسات الأجهزة',
        'pageDescription' => 'جلسات الدخول النشطة دون عناوين IP أو معرّفات إعلانية.',
        'pageIcon' => 'fa-mobile',
        'pageActionUrl' => route('admin.product-operations.index'),
        'pageActionLabel' => 'مركز التشغيل',
        'pageActionIcon' => 'fa-dashboard',
    ])

    @if(!$ready)
        <div class="alert alert-warning">شغّل migrations أولًا لتفعيل سجل الجلسات الجديد.</div>
    @else
        <div class="row mb-3">
            @foreach($platforms as $platform => $total)
                <div class="col-sm-6 col-lg-3 mb-3">
                    @include('admin.partials.metric-card', [
                        'metricLabel' => strtoupper($platform),
                        'metricValue' => number_format($total),
                        'metricIcon' => $platform === 'android' ? 'fa-android' : ($platform === 'ios' ? 'fa-apple' : 'fa-mobile'),
                    ])
                </div>
            @endforeach
        </div>

        <div class="card admin-card">
            <div class="table-responsive">
                <table class="table table-hover admin-table mb-0 text-right">
                    <thead class="thead-light">
                    <tr><th>الطالب</th><th>الجهاز</th><th>إصدار التطبيق</th><th>آخر نشاط</th><th>تنتهي</th><th></th></tr>
                    </thead>
                    <tbody>
                    @forelse($sessions as $session)
                        <tr>
                            <td><strong>{{ $session->user?->name ?: 'حساب محذوف' }}</strong><br><small class="text-muted">{{ $session->user?->email }}</small></td>
                            @php($isTablet = ($session->device_class ?? null) === 'tablet')
                            <td>
                                <i class="fa {{ $isTablet ? 'fa-tablet' : 'fa-mobile' }} ml-1"></i>
                                {{ $isTablet ? 'جهاز لوحي' : 'هاتف' }}
                                @if(in_array($session->platform, ['android', 'ios'], true))
                                    <small class="text-muted">{{ $session->platform === 'ios' ? 'iOS' : 'Android' }}</small>
                                @endif
                            </td>
                            <td>{{ $session->app_version ?: '—' }} @if($session->app_build)<small class="text-muted">({{ $session->app_build }})</small>@endif</td>
                            <td>{{ \App\Support\BusinessClock::relative($session->last_used_at ?: $session->issued_at) ?: '—' }}</td>
                            <td>{{ \App\Support\BusinessClock::relative($session->expired_at) ?: '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.user-sessions.destroy', $session->session_id) }}" onsubmit="return confirm('إنهاء هذه الجلسة فقط؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"><i class="fa fa-sign-out"></i> إنهاء الجلسة</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">لا توجد جلسات نشطة مسجلة بعد.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $sessions->links() }}</div>
    @endif
</div>
@endsection
