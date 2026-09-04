@php
    $headerUser = auth()->user();
    $headerIsAdministrator = app(\App\Auth\AdminPermissionMatrix::class)
        ->isAdministrator($headerUser?->role);
    $headerNotificationState = app(\App\Services\AdminHeaderNotificationService::class)->for($headerUser);
    $headerNotifications = $headerNotificationState['items'];
    $headerUnreadCount = $headerNotificationState['unread_count'];
    $headerProfileImage = $headerUser?->profile_image_url;
    if (!$headerProfileImage && ($legacyProfileImage = $headerUser?->getRawOriginal('image'))) {
        $headerProfileImage = filter_var($legacyProfileImage, FILTER_VALIDATE_URL)
            ? (str_starts_with(strtolower((string) $legacyProfileImage), 'https://')
                ? $legacyProfileImage
                : null)
            : asset(ltrim($legacyProfileImage, '/'));
    }
    $headerProfileImage = $headerProfileImage ?: asset('images/avatar/customer_blank.png');
@endphp
<header id="header" class="modern-header">

    <div class="modern-header-content">
        <!-- Right Side: Menu Toggle & Notifications -->
        <div class="header-right">
            <button
                type="button"
                id="menuToggle"
                class="modern-menu-toggle"
                aria-label="فتح القائمة الرئيسية"
                aria-controls="left-panel"
                aria-expanded="false"
            >
                <i class="fa fa-bars" aria-hidden="true"></i>
            </button>

            @if($headerIsAdministrator)
            <div class="modern-notification">
                <button
                    type="button"
                    class="notification-btn"
                    id="notificationToggle"
                    aria-label="الإشعارات"
                    aria-controls="notificationMenu"
                    aria-expanded="false"
                >
                    <i class="fa fa-bell" aria-hidden="true"></i>
                    @if($headerUnreadCount > 0)
                        <span class="notification-badge">{{ $headerUnreadCount > 99 ? '99+' : $headerUnreadCount }}</span>
                    @endif
                </button>

                <div class="notification-dropdown" id="notificationMenu" aria-hidden="true">
                    <div class="notification-header">
                        <h6>{{ $headerUnreadCount ? $headerUnreadCount . ' إشعارات جديدة' : 'لا توجد إشعارات جديدة' }}</h6>
                    </div>
                    <div class="notification-list">
                        @forelse($headerNotifications as $notification)
                            <a class="notification-item" href="{{ $notification['url'] }}">
                                <div class="notification-content">
                                    <p>{{ $notification['label'] }}</p>
                                </div>
                                <div class="notification-icon">
                                    <i class="fa fa-user-plus"></i>
                                </div>
                            </a>
                        @empty
                            <div class="notification-empty">
                                <i class="fa fa-bell-slash"></i>
                                <p>لا توجد إشعارات جديدة</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
            @endif

            <button type="button" class="dark-mode-toggle" id="darkModeToggle" title="تبديل الوضع الليلي" aria-label="تبديل الوضع الليلي">
                <i class="fa fa-moon-o" aria-hidden="true"></i>
                <i class="fa fa-sun-o" aria-hidden="true"></i>
            </button>

        </div>

        <!-- Left Side: User Profile -->
        <div class="header-left">
            <div class="modern-user-menu">
                <button
                    type="button"
                    class="user-profile-btn"
                    id="userMenuToggle"
                    aria-label="قائمة الحساب"
                    aria-controls="userMenu"
                    aria-expanded="false"
                >
                    <div class="user-info">
                        <span class="user-name">{{ $headerUser->name ?? 'المسؤول' }}</span>
                        <span class="user-role">{{ $headerIsAdministrator ? 'مسؤول النظام' : 'محرر المحتوى' }}</span>
                    </div>
                    <div class="user-avatar-wrapper">
                        <img src="{{ $headerProfileImage }}" alt="صورة {{ $headerUser->name ?? 'المستخدم' }}">
                    </div>
                </button>

                <div class="user-dropdown" id="userMenu" aria-hidden="true">
                    <a class="user-dropdown-item" href="{{ route('admin.admin_data') }}">
                        <span>تعديل بيانات الدخول</span>
                        <i class="fa fa-user"></i>
                    </a>
                    <a class="user-dropdown-item logout" href="#" onclick="event.preventDefault(); document.getElementById('logoutForm').submit();">
                        <span>تسجيل خروج</span>
                        <i class="fa fa-sign-out"></i>
                    </a>
                    <form class="d-none" id="logoutForm" action="{{ route('logout') }}" method="post">
                        @csrf
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
