<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminLoginViewTest extends TestCase
{
    private string $source;

    private string $stylesheet;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/auth/login.blade.php');
        $stylesheet = file_get_contents($root.'/public/admin/assets/css/login.css');

        self::assertNotFalse($source);
        self::assertNotFalse($stylesheet);

        $this->source = $source;
        $this->stylesheet = $stylesheet;
    }

    public function test_login_view_uses_versioned_scoped_admin_assets_without_inline_styles(): void
    {
        self::assertStringNotContainsString('<style', $this->source);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $this->source);
        self::assertDoesNotMatchRegularExpression('/\.style(?:\.|\[)/', $this->source);

        foreach (['custom-global.css', 'login.css'] as $asset) {
            self::assertStringContainsString("versioned_asset('admin/assets/css/{$asset}')", $this->source);
        }

        self::assertStringContainsString('<body class="rokn-login">', $this->source);
        self::assertStringContainsString('body.rokn-login', $this->stylesheet);
        self::assertStringContainsString('var(--rokn-admin-primary, #2563eb)', $this->stylesheet);
        self::assertStringContainsString('var(--rokn-admin-primary-dark, #172554)', $this->stylesheet);
    }

    public function test_login_form_preserves_authentication_and_validation_contracts(): void
    {
        foreach ([
            '<html lang="ar" dir="rtl">',
            '<form method="POST" action="{{ route(\'login\') }}" id="loginForm">',
            '@csrf',
            'name="email"',
            'value="{{ old(\'email\') }}"',
            'autocomplete="email"',
            "@error('email')",
            'name="password"',
            'autocomplete="current-password"',
            "@error('password')",
            'name="remember"',
            "{{ old('remember') ? 'checked' : '' }}",
            'type="submit"',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->source);
        }

        self::assertMatchesRegularExpression('/name="email"[\s\S]*?\brequired\b/', $this->source);
        self::assertMatchesRegularExpression('/name="password"[\s\S]*?\brequired\b/', $this->source);
        self::assertStringContainsString("session('status')", $this->source);
        self::assertStringContainsString('$errors->any()', $this->source);
    }

    public function test_login_interactions_and_motion_contracts_remain_available(): void
    {
        foreach ([
            "getElementById('togglePassword')",
            "getElementById('loginForm')",
            "classList.add('loading')",
            'fa-eye-slash',
            'aria-pressed',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->source);
        }

        foreach ([
            '@keyframes rokn-login-float',
            '@keyframes rokn-login-slide-up',
            '@keyframes rokn-login-shake',
            '@keyframes rokn-login-spin',
            '@media (prefers-reduced-motion: reduce)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->stylesheet);
        }
    }
}
