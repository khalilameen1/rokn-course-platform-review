<!-- Left Panel -->

@php
    $sidebarData = app(\App\Services\AdminSidebarDataService::class)->forUser(auth()->user());
    $isAdministrator = $sidebarData['is_administrator'];
    $pendingSupportCount = $sidebarData['pending_support_count'];
    $dashboardHome = route('admin.dashboard');
@endphp

<aside id="left-panel" class="left-panel modern-sidebar" aria-label="القائمة الرئيسية">
    <nav class="sidebar-nav">

        <div class="modern-brand">
            <a class="brand-logo" href="{{ $dashboardHome }}" aria-label="ركن الرئيسية">
                <img class="brand-wordmark" src="{{ asset('images/rokn-wordmark.png') }}" alt="Rokn" width="112" height="37">
                <img class="brand-symbol" src="{{ asset('images/rokn-app-icon.png') }}" alt="" width="38" height="38">
            </a>
            <button
                type="button"
                id="adminSidebarClose"
                class="admin-sidebar-close"
                aria-label="إغلاق القائمة الرئيسية"
                aria-controls="left-panel"
            >
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div id="main-menu" class="main-menu">
            <ul class="modern-nav">
                <li class="nav-item{{ isRouteActive('admin.dashboard') ? ' active' : '' }}">
                    <a href="{{ $dashboardHome }}" class="nav-link" @if(isRouteActive('admin.dashboard')) aria-current="page" @endif>
                        <i class="menu-icon fa fa-dashboard"></i>
                        <span class="menu-text">{{ $isAdministrator ? 'الرئيسية' : 'مساحة المحتوى' }}</span>
                    </a>
                </li>
                <li class="menu-divider"><span>{{ $isAdministrator ? 'إدارة المحتوى' : 'صناعة الكورس' }}</span></li>
                <li class="nav-item{{ isRouteActive('admin.classifications.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.classifications.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-tags"></i>
                        <span class="menu-text">صفوف الرئيسية</span>
                    </a>
                </li>

                @if($isAdministrator)
                <li class="nav-item{{ isRouteActive('admin.levels.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.levels.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-trophy"></i>
                        <span class="menu-text">المستويات</span>
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.paths.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.paths.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-road"></i>
                        <span class="menu-text">المسارات</span>
                    </a>
                </li>
                @endif

                <li class="nav-item{{ isRouteActive('admin.courses.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.courses.index') }}" class="nav-link" @if(isRouteActive('admin.courses.*')) aria-current="page" @endif>
                        <i class="menu-icon fa fa-book"></i>
                        <span class="menu-text">الكورسات</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.teachers.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.teachers.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-users"></i>
                        <span class="menu-text">المعلمون</span>
                    </a>
                </li>
                @if($isAdministrator)
                <li class="nav-item{{ isRouteActive('admin.moderators.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.moderators.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-user-secret"></i>
                        <span class="menu-text">فريق المحتوى</span>
                    </a>
                </li>
                @endif

                @if($isAdministrator)
                <li class="menu-divider"><span>الطلاب والتقييم</span></li>
                <li class="nav-item{{ isRouteActive('admin.users.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.users.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-user-circle"></i>
                        <span class="menu-text">الطلاب</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.student-progress.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.student-progress.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-bar-chart-o"></i>
                        <span class="menu-text">تقدم الطلاب</span>
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.product-operations.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.product-operations.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-check-square-o"></i>
                        <span class="menu-text">مركز تشغيل المنتج</span>
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.playback-operations.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.playback-operations.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-play-circle"></i>
                        <span class="menu-text">مراقبة الفيديو</span>
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.user-sessions.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.user-sessions.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-mobile"></i>
                        <span class="menu-text">جلسات الأجهزة</span>
                    </a>
                </li>
                @endif

                <li class="nav-item{{ isRouteActive('admin.project-submissions.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.project-submissions.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-tasks"></i>
                        <span class="menu-text">مراجعة المشاريع</span>
                    </a>
                </li>

                @if($isAdministrator)
                <li class="menu-divider"><span>المالية والمبيعات</span></li>

                <li class="nav-item{{ isRouteActive('admin.course-codes.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.course-codes.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-key"></i>
                        <span class="menu-text">إدارة الأكواد</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.orders.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.orders.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-shopping-cart"></i>
                        <span class="menu-text">المشتريات</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.payment-reconciliation-findings.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.payment-reconciliation-findings.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-balance-scale"></i>
                        <span class="menu-text">مراجعة تسوية المدفوعات</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.packages.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.packages.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-cubes"></i>
                        <span class="menu-text">الباقات</span>
                    </a>
                </li>
                @endif

                @if($isAdministrator)
                <li class="nav-item{{ isRouteActive('admin.coin-earning-methods.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.coin-earning-methods.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-money"></i>
                        <span class="menu-text">طرق ربح العملات</span>
                    </a>
                </li>


                <li class="nav-item{{ isRouteActive('admin.operating-costs.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.operating-costs.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-calculator"></i>
                        <span class="menu-text">مراكز التكلفة</span>
                    </a>
                </li>

                <!-- Settings Section -->
                <li class="menu-divider"><span>الإعدادات</span></li>
                <li class="nav-item{{ isRouteActive('admin.design-settings.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.design-settings.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-paint-brush"></i>
                        <span class="menu-text">هوية التطبيق</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.product-analytics.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.product-analytics.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-line-chart"></i>
                        <span class="menu-text">تحليلات المنتج</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.settings') ? ' active' : '' }}">
                    <a href="{{ route('admin.settings') }}" class="nav-link">
                        <i class="menu-icon fa fa-cog"></i>
                        <span class="menu-text">إعدادات التطبيق</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.about') || isRouteActive('admin.privacy') || isRouteActive('admin.policy') ? ' active' : '' }}">
                    <a href="{{ route('admin.about') }}" class="nav-link">
                        <i class="menu-icon fa fa-file-text-o"></i>
                        <span class="menu-text">صفحات التطبيق</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.app-versions.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.app-versions.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-mobile"></i>
                        <span class="menu-text">إصدارات التطبيق</span>
                    </a>
                </li>

                @endif
                @if($isAdministrator)
                <li class="menu-divider"><span>التواصل</span></li>

                <li class="nav-item{{ isRouteActive('admin.feedback.*') || isRouteActive('admin.contacts.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.feedback.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-comments"></i>
                        <span class="menu-text">رسائل الدعم</span>
                        @if($pendingSupportCount > 0)
                            <span class="notification-badge">{{ $pendingSupportCount > 99 ? '99+' : $pendingSupportCount }}</span>
                        @endif
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.notifications.*') || isRouteActive('admin.admin_notifications.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.notifications.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-bell"></i>
                        <span class="menu-text">إشعارات الطلاب</span>
                    </a>
                </li>
                @endif

            </ul>
        </div>
    </nav>
</aside>
