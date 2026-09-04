<!DOCTYPE html>
<html lang="ar" dir="rtl" class="admin-shell-root">
<head>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ config('settings.site_name_ar') }} | @yield('page.title')</title>
    <meta name="description" content="{{ config('settings.site_name_ar') }} - لوحة التحكم">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">

    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/normalize.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/themify-icons.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/scss/style.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/custom-global.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/js/vendor/select2/select2.min.css') }}">
    @yield('styles')
    @stack('styles')
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-shell.css') }}">
</head>
<body class="admin-shell">
@auth
    <a class="admin-skip-link" href="#main-content">انتقل إلى المحتوى الرئيسي</a>
    @include('admin.includes.aside')
    <button
        type="button"
        id="adminSidebarOverlay"
        class="admin-sidebar-overlay"
        aria-label="إغلاق القائمة الرئيسية"
        aria-hidden="true"
        tabindex="-1"
    ></button>
    <div id="right-panel" class="right-panel">
        @include('admin.includes.header')
        @include('admin.includes.alert')

        @hasSection('breadcrumbs')
            <div class="breadcrumbs">@yield('breadcrumbs')</div>
        @endif
        <main class="content mt-3" id="main-content" tabindex="-1">
            <div id="app">
            @yield('content')
            </div>
        </main>
    </div>
@endauth
<!-- Scripts -->
<script src="{{ versioned_asset('js/app.js') }}"></script>
<script src="{{ versioned_asset('admin/assets/js/vendor/select2/select2.min.js') }}"></script>
<script src="{{ versioned_asset('admin/assets/js/main.js') }}"></script>
<script src="{{ versioned_asset('admin/assets/js/request.js') }}"></script>

@yield('scripts')
@stack('scripts')
</body>
</html>

