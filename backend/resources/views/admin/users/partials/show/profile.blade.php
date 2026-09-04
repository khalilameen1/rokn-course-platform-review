<div class="profile-card-modern">
    <div class="profile-header">
        <div class="profile-status">
            <span class="status-badge-large {{ $user->active ? 'active' : 'inactive' }}">
                <i class="fa {{ $user->active ? 'fa-check-circle' : 'fa-times-circle' }}"></i>
                {{ $user->active ? 'مفعل' : 'غير مفعل' }}
            </span>
        </div>
        <img class="profile-avatar" src="{{ $user->image ?: '/images/avatar/customer_blank.png' }}" alt="{{ $user->name }}">
        <h2 class="profile-name">{{ $user->name }}</h2>
        <div class="profile-email"><i class="fa fa-envelope"></i> {{ $user->email }}</div>
        <div class="profile-phone"><i class="fa fa-phone"></i> {{ $user->phone }}</div>
        @if($user->socialAccounts->isNotEmpty())
            @php($providerLabels = ['google' => 'Google', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'apple' => 'Apple'])
            <div class="profile-phone">
                <i class="fa fa-sign-in"></i>
                {{ $user->socialAccounts->map(fn ($account) => $providerLabels[$account->provider] ?? ucfirst($account->provider))->implode(' · ') }}
            </div>
        @endif
    </div>

    <div class="profile-body">
        <div class="study-info">
            <h6 class="study-info__heading"><i class="fa fa-database"></i> رصيد العملات</h6>
            <span class="stat-badge stat-badge-primary">الإجمالي {{ number_format($walletSummary['total_balance']) }}</span>
            <span class="stat-badge stat-badge-success">مدفوع {{ number_format($walletSummary['purchased_balance']) }}</span>
            <span class="stat-badge stat-badge-info">مكافآت {{ number_format($walletSummary['reward_balance']) }}</span>
            <span class="stat-badge stat-badge-light">المتاح لكورس جديد {{ number_format($walletSummary['course_spendable_balance']) }}</span>
        </div>

        @if($user->interests->isNotEmpty())
            <div class="study-info study-info--interests">
                <h6 class="study-info__heading"><i class="fa fa-tags"></i> الاهتمامات</h6>
                @foreach($user->interests as $interest)
                    <span class="study-badge-large study-badge-large--interest">{{ $interest->name_ar }}</span>
                @endforeach
            </div>
        @endif

        <div class="profile-actions">
            <a href="{{ route('admin.users.edit', $user->id) }}" class="btn-action-modern btn-edit">
                <i class="fa fa-pencil-square"></i> تعديل البيانات
            </a>
            <form action="{{ route('admin.users.deactive', $user->id) }}" method="POST" class="admin-inline-form">
                @csrf
                @method('PATCH')
                <input type="hidden" name="expected_active" value="{{ $user->active ? 1 : 0 }}">
                <input type="hidden" name="state_version" value="{{ $accountStateVersion }}">
                <button type="submit" class="btn-action-modern btn-toggle {{ !$user->active ? 'activate' : '' }}">
                    <i class="fa {{ $user->active ? 'fa-ban' : 'fa-check-circle' }}"></i>
                    {{ $user->active ? 'تعطيل الحساب' : 'تفعيل الحساب' }}
                </button>
            </form>
            @if($user->locked_device_id && $deviceLoginPolicy === \App\Services\DeviceLoginService::POLICY_SINGLE_PERMANENT)
                <form action="{{ route('admin.users.reset-device', $user->id) }}" method="POST" class="admin-inline-form" onsubmit="return confirm('إعادة تعيين جهاز الطالب؟')">
                    @csrf
                    <input type="hidden" name="state_version" value="{{ $deviceStateVersion }}">
                    <input type="hidden" name="expected_policy" value="{{ $deviceLoginPolicy }}">
                    <button type="submit" class="btn-action-modern btn-reset-device"><i class="fa fa-refresh"></i> إعادة تعيين الجهاز</button>
                </form>
            @endif
        </div>
    </div>
</div>
