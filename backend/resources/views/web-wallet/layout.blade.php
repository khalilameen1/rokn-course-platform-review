<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#090d16">
    <title>@yield('title', 'شحن رصيدك') | ركن</title>
    <link rel="stylesheet" href="{{ asset('css/web-wallet.css') }}">
</head>
<body>
<main class="wallet-shell">
    <header class="wallet-header">
        <a href="{{ route('web-wallet.index') }}" aria-label="ركن شحن الرصيد" class="brand">
            <img src="{{ asset('images/rokn-wordmark.png') }}" alt="ROKN" width="112" height="36">
        </a>
        @if($student)
            <form action="{{ route('web-wallet.logout') }}" method="post">@csrf
                <button class="text-button" type="submit">تبديل الحساب</button>
            </form>
        @endif
    </header>
    @if(session('error'))<p class="notice" role="alert">{{ session('error') }}</p>@endif
    @if($errors->any())<p class="notice" role="alert">{{ $errors->first() }}</p>@endif
    @if($student)
        <section class="account" aria-label="الحساب الذي سيستلم الرصيد">
            @if($student->profile_image_url)
                <img class="avatar" src="{{ $student->profile_image_url }}" alt="" width="44" height="44" referrerpolicy="no-referrer">
            @else
                <span class="avatar avatar-letter" aria-hidden="true">{{ mb_substr($student->name ?: 'ركن', 0, 1) }}</span>
            @endif
            <div class="account-name">
                <strong>{{ $student->name ?: 'حسابك في ركن' }}</strong>
                <small dir="auto">{{ $student->email }}</small>
                <small class="account-uid"><bdi>UID: {{ $student->getKey() }}</bdi></small>
            </div>
            <div class="balance"><small>رصيدك</small><strong>{{ number_format($balance) }} <span>عملة</span></strong></div>
        </section>
    @endif
    @yield('content')
    <footer class="wallet-footer">
        <a href="{{ route('terms') }}">شروط الاستخدام</a>
        <a href="{{ route('privacy') }}">الخصوصية</a>
        <a href="{{ route('contact') }}">تواصل معنا</a>
    </footer>
</main>
<script src="{{ asset('js/web-wallet.js') }}" defer></script>
</body>
</html>
