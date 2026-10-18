<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminResponsiveShellContractTest extends TestCase
{
    public function test_shared_shell_exposes_accessible_drawer_controls(): void
    {
        $layout = $this->source('resources/views/admin/layouts/app.blade.php');
        $header = $this->source('resources/views/admin/includes/header.blade.php');
        $aside = $this->source('resources/views/admin/includes/aside.blade.php');

        self::assertStringContainsString('class="admin-shell-root"', $layout);
        self::assertStringContainsString('<body class="admin-shell">', $layout);
        self::assertStringContainsString('id="adminSidebarOverlay"', $layout);
        self::assertStringContainsString('aria-hidden="true"', $layout);

        self::assertMatchesRegularExpression('/<button[^>]+id="menuToggle"[^>]*>/s', $header);
        self::assertStringContainsString('aria-controls="left-panel"', $header);
        self::assertStringContainsString('aria-expanded="false"', $header);
        self::assertStringContainsString('$headerUser?->profile_image_url', $header);
        self::assertStringNotContainsString('PublicDiskUrl::from', $header);

        self::assertStringContainsString('aria-label="القائمة الرئيسية"', $aside);
        self::assertStringContainsString('id="adminSidebarClose"', $aside);
        self::assertStringContainsString('aria-label="إغلاق القائمة الرئيسية"', $aside);
    }

    public function test_shell_css_uses_bounded_desktop_flex_and_mobile_off_canvas_layout(): void
    {
        $css = $this->source('public/admin/assets/css/admin-shell.css');
        $globalCss = $this->source('public/admin/assets/css/custom-global.css');

        self::assertStringContainsString('body.admin-shell {', $css);
        self::assertStringContainsString('display: flex;', $css);
        self::assertStringContainsString('overflow-x: clip;', $css);
        self::assertStringContainsString('body.admin-shell #right-panel.right-panel', $css);
        self::assertStringContainsString('min-width: 0;', $css);
        self::assertStringContainsString('@media (max-width: 768px)', $css);
        self::assertStringContainsString('visibility: hidden;', $css);
        self::assertStringContainsString('body.admin-shell.sidebar-open aside.left-panel.modern-sidebar', $css);
        self::assertStringContainsString('.admin-sidebar-overlay', $css);

        self::assertMatchesRegularExpression('/\.table-responsive\s*\{[^}]*overflow-x:\s*auto;/s', $globalCss);
    }

    public function test_shell_script_manages_drawer_state_focus_and_keyboard_dismissal(): void
    {
        $script = $this->source('public/admin/assets/js/main.js');

        self::assertStringContainsString("window.matchMedia('(max-width: 768px)')", $script);
        self::assertStringContainsString('function setMobileSidebar(open, restoreFocus)', $script);
        self::assertStringContainsString("body.classList.toggle('sidebar-open', shouldOpen)", $script);
        self::assertStringContainsString("sidebar?.toggleAttribute('inert', !shouldOpen)", $script);
        self::assertStringContainsString("rightPanel?.toggleAttribute('inert', shouldOpen)", $script);
        self::assertStringContainsString("sidebar?.setAttribute('aria-modal', 'true')", $script);
        self::assertStringContainsString("sidebarOverlay?.addEventListener('click'", $script);
        self::assertStringContainsString("event.key === 'Escape'", $script);
        self::assertStringContainsString("event.key !== 'Tab'", $script);
        self::assertStringContainsString('focusTarget?.focus()', $script);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);
        self::assertNotFalse($source);

        return $source;
    }
}
