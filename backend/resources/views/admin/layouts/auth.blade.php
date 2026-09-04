<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('page.title') | {{ config('settings.site_name_ar', 'Rokn') }}</title>
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/normalize.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/custom-global.css') }}">
</head>
<body class="admin-auth-shell">
    <main class="admin-auth-card">
        <header class="admin-auth-card__header">
            <h1>@yield('auth.title')</h1>
            <p>@yield('auth.description')</p>
        </header>
        <div class="admin-auth-card__body">
            @yield('content')
        </div>
    </main>
</body>
</html>
