@extends('admin.layouts.app')

@section('page.title', 'تعديل بيانات الدخول')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/settings-admin-data.css') }}">
@endsection

@section('content')

<div class="admin-page admin-data-wrapper">
    <div class="animated-bg">
        <div class="bg-circle"></div>
        <div class="bg-circle"></div>
        <div class="bg-circle"></div>
    </div>

    <div class="admin-data-container">
        <div class="admin-data-card">
            <div class="card-header-modern">
                <div class="header-icon">
                    <i class="fa fa-user-circle-o"></i>
                </div>
                <h2>تعديل بيانات الدخول</h2>
                <p>تحديث حسابك في لوحة التحكم</p>
            </div>

            <div class="card-body-modern">
                {!! Form::open(['method' => 'POST', 'route' => ['admin.update_admin_data'], 'id' => 'adminDataForm']) !!}

                <div class="form-group-styled">
                    <label for="email">
                        <span class="label-icon">
                            <i class="fa fa-envelope"></i>
                        </span>
                        البريد الإلكتروني
                    </label>
                    <div class="input-wrapper">
                        <i class="fa fa-envelope input-icon"></i>
                        {!! Form::text('email', auth()->user()->email, ['class' => 'form-control-styled', 'required', 'id'=>"email", 'placeholder'=>"example@domain.com"]) !!}
                    </div>
                    <div class="helper-text">
                        <i class="fa fa-info-circle"></i>
                        <span>سيتم استخدام هذا البريد لتسجيل الدخول</span>
                    </div>
                </div>

                <div class="form-group-styled">
                    <label for="password">
                        <span class="label-icon">
                            <i class="fa fa-lock"></i>
                        </span>
                        كلمة السر الجديدة
                    </label>
                    <div class="input-wrapper">
                        <i class="fa fa-lock input-icon"></i>
                        <input class="form-control-styled" type="password" name="password" id="password" placeholder="أدخل كلمة سر قوية">
                        <i class="fa fa-eye password-toggle" id="togglePassword"></i>
                    </div>
                    <div class="helper-text">
                        <i class="fa fa-info-circle"></i>
                        <span>اتركه فارغاً إذا كنت لا تريد تغيير كلمة السر</span>
                    </div>
                </div>

                <div class="submit-btn-wrapper">
                    <button type="submit" class="btn-submit-modern">
                        <i class="fa fa-save btn-icon"></i>
                        <span>حفظ التغييرات</span>
                    </button>
                </div>

                {!! Form::close() !!}
            </div>
        </div>
    </div>
</div>

<script>
    // Password toggle functionality
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const icon = this;

        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });

    // Form submission with loading state
    document.getElementById('adminDataForm').addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalContent = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<div class="loading-spinner"></div><span>جاري الحفظ...</span>';

        // Re-enable after a delay (in case of validation errors)
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalContent;
        }, 3000);
    });

    // Add input validation styling
    document.querySelectorAll('.form-control-styled').forEach(input => {
        input.addEventListener('blur', function() {
            this.classList.toggle('is-valid', this.value.trim() !== '');
        });

        input.addEventListener('input', function() {
            this.classList.remove('is-valid');
        });
    });
</script>
@endsection


