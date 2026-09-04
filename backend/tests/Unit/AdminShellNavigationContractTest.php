<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminShellNavigationContractTest extends TestCase
{
    public function test_each_daily_task_has_one_shell_entry(): void
    {
        $aside = $this->read('resources/views/admin/includes/aside.blade.php');
        $header = $this->read('resources/views/admin/includes/header.blade.php');

        self::assertSame(1, substr_count($aside, "route('admin.settings')"));
        self::assertStringNotContainsString("route('admin.settings')", $header);
        self::assertStringNotContainsString("route('admin.admin_data')", $aside);
        self::assertSame(1, substr_count($header, "route('admin.admin_data')"));

        self::assertSame(1, substr_count($aside, "route('admin.feedback.index')"));
        self::assertStringNotContainsString("route('admin.contacts.index')", $aside);
        self::assertStringContainsString('رسائل الدعم', $aside);
        self::assertStringNotContainsString('اتصل بنا', $aside.$header);
    }

    public function test_support_sources_are_reached_from_the_single_inbox(): void
    {
        $feedback = $this->read('resources/views/admin/feedback/index.blade.php');
        $contacts = $this->read('resources/views/admin/contacts/index.blade.php');
        $tabs = $this->read('resources/views/admin/partials/support-inbox-tabs.blade.php');

        foreach ([$feedback, $contacts] as $surface) {
            self::assertStringContainsString("admin.partials.support-inbox-tabs", $surface);
        }

        self::assertSame(1, substr_count($tabs, "route('admin.feedback.index')"));
        self::assertSame(1, substr_count($tabs, "route('admin.contacts.index')"));
        self::assertStringContainsString('رسائل التطبيق', $tabs);
        self::assertStringContainsString('رسائل الموقع وطلبات حذف الحساب', $tabs);
    }

    public function test_hosting_provider_placeholder_is_not_shipped_with_the_application(): void
    {
        self::assertFileDoesNotExist($this->root().'/default.html');
    }

    public function test_retired_student_platform_dashboard_has_no_route_or_surface(): void
    {
        $routes = $this->read('routes/web.php');
        $settingsController = $this->read('app/Http/Controllers/Admin/SettingsController.php');

        self::assertStringNotContainsString("name('student-platform')", $routes);
        self::assertStringNotContainsString('function studentPlatform', $settingsController);
        self::assertFileDoesNotExist($this->root().'/resources/views/admin/settings/student-platform.blade.php');
        self::assertFileDoesNotExist($this->root().'/public/admin/assets/css/settings-student-platform.css');
    }

    public function test_identity_editor_does_not_expose_unused_powered_by_fields(): void
    {
        $view = $this->read('resources/views/admin/design-settings/index.blade.php');
        $controller = $this->read('app/Http/Controllers/Admin/DesignSettingController.php');

        self::assertStringNotContainsString('powered_by_titles', $view.$controller);
        self::assertStringNotContainsString('powered_by_urls', $view.$controller);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root().'/'.$path);
        self::assertNotFalse($contents);

        return $contents;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
