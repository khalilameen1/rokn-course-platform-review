<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>دخول لوحة ركن</title>

    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/font-awesome.min.css') }}">
    <link
        rel="stylesheet"
        href="{{ versioned_asset('admin/assets/css/custom-global.css') }}"
    >
    <link
        rel="stylesheet"
        href="{{ versioned_asset('admin/assets/css/login.css') }}"
    >
</head>
<body class="rokn-login">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-icon">
                    <i class="fa fa-graduation-cap" aria-hidden="true"></i>
                </div>
                <h1 class="login-title">لوحة إدارة ركن</h1>
                <p class="login-subtitle">دخول الأدمن أو فريق المحتوى</p>
            </div>

            <div class="login-body">
                @if (session('status'))
                    <div class="alert alert-success" role="status">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any() && !$errors->has('email') && !$errors->has('password'))
                    <div class="alert alert-danger" role="alert">
                        @foreach ($errors->all() as $error)
                            {{ $error }}
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" id="loginForm">
                    @csrf

                    <div class="form-group">
                        <label for="email" class="form-label">البريد الإلكتروني</label>
                        <div class="input-wrapper">
                            <input
                                id="email"
                                type="email"
                                class="form-control @error('email') is-invalid @enderror"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autocomplete="email"
                                autofocus
                                placeholder="أدخل بريدك الإلكتروني"
                                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                            >
                            <i class="fa fa-envelope input-icon" aria-hidden="true"></i>
                            @error('email')
                                <span class="invalid-feedback" id="email-error">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">كلمة المرور</label>
                        <div class="input-wrapper">
                            <input
                                id="password"
                                type="password"
                                class="form-control has-toggle @error('password') is-invalid @enderror"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="أدخل كلمة المرور"
                                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                            >
                            <i class="fa fa-lock input-icon" aria-hidden="true"></i>
                            <button
                                type="button"
                                class="password-toggle"
                                id="togglePassword"
                                aria-label="إظهار كلمة المرور"
                                aria-controls="password"
                                aria-pressed="false"
                            >
                                <i class="fa fa-eye" aria-hidden="true"></i>
                            </button>
                            @error('password')
                                <span class="invalid-feedback" id="password-error">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <div class="remember-section">
                        <div class="custom-checkbox">
                            <input
                                type="checkbox"
                                name="remember"
                                id="remember"
                                {{ old('remember') ? 'checked' : '' }}
                            >
                            <label for="remember">تذكرني</label>
                        </div>
                        <a href="{{ route('password.request') }}">نسيت كلمة المرور؟</a>
                    </div>

                    <button type="submit" class="btn-login" aria-live="polite">
                        <i class="fa fa-sign-in" aria-hidden="true"></i>
                        تسجيل الدخول
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        togglePassword.addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            const shouldShowPassword = passwordInput.type === 'password';

            passwordInput.type = shouldShowPassword ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !shouldShowPassword);
            icon.classList.toggle('fa-eye-slash', shouldShowPassword);
            this.setAttribute('aria-pressed', shouldShowPassword ? 'true' : 'false');
            this.setAttribute(
                'aria-label',
                shouldShowPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'
            );
        });

        // Add loading animation on form submit
        document.getElementById('loginForm').addEventListener('submit', function() {
            const button = this.querySelector('.btn-login');
            button.classList.add('loading');
            button.innerHTML = '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> جاري التحميل...';
        });
    </script>
</body>
</html>
