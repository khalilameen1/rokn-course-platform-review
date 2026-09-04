<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AdminIdentityViewsTest extends TestCase
{
    private const VIEW_FAMILIES = [
        'users',
        'design-settings',
        'grades',
        'app-versions',
    ];

    public function test_identity_views_do_not_embed_css(): void
    {
        foreach ($this->viewFiles() as $view => $path) {
            $source = file_get_contents($path);

            self::assertNotFalse($source, $view);
            self::assertStringNotContainsString('<style', $source, $view);
            self::assertDoesNotMatchRegularExpression('/<[^>]+\sstyle\s*=/i', $source, $view);
            self::assertDoesNotMatchRegularExpression('/[\'"]style[\'"]\s*=>/i', $source, $view);
            self::assertDoesNotMatchRegularExpression(
                '/createElement\([\'"]style[\'"]\)/i',
                $source,
                $view
            );
        }
    }

    public function test_interactive_screens_load_page_or_family_scoped_assets(): void
    {
        $contracts = [
            'users/create.blade.php' => ['users-theme.css', 'users-editor.css', 'users-form.css', 'admin-page'],
            'users/edit.blade.php' => ['users-theme.css', 'users-editor.css', 'users-form.css', 'admin-page'],
            'users/index.blade.php' => ['users-theme.css', 'users-index.css', 'admin-page'],
            'users/show.blade.php' => ['users-theme.css', 'users-show.css', 'admin-page'],
            'design-settings/index.blade.php' => ['design-settings.css', 'admin-page'],
            'grades/create.blade.php' => ['grades-theme.css', 'grades-create.css', 'grades-form.css', 'admin-page'],
            'grades/edit.blade.php' => ['grades-theme.css', 'grades-edit.css', 'grades-form.css', 'admin-page'],
            'grades/index.blade.php' => ['grades-theme.css', 'grades-index.css', 'admin-page'],
            'grades/courses.blade.php' => ['grades-theme.css', 'grades-courses.css', 'admin-page'],
            'app-versions/create.blade.php' => ['app-versions.css', 'admin-card'],
            'app-versions/edit.blade.php' => ['app-versions.css', 'admin-card'],
            'app-versions/index.blade.php' => ['app-versions.css', 'admin-card'],
        ];

        foreach ($contracts as $view => $needles) {
            $source = $this->composedViewSource($view);

            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $source, "{$view}: {$needle}");
            }
        }
    }

    public function test_routes_form_fields_and_interactions_remain_present(): void
    {
        $source = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            array_values($this->viewFiles())
        ));
        $source .= (string) file_get_contents(
            $this->projectRoot().'/resources/views/admin/partials/identity-theme.blade.php'
        );
        $source .= (string) file_get_contents(
            $this->projectRoot().'/public/admin/assets/js/admin-identity-theme.js'
        );

        foreach ([
            'admin.users.store',
            'admin.users.update',
            'admin.users.deactive',
            'admin.users.reset-device',
            'admin.notifications.create',
            'admin.users.notes.store',
            'admin.users.notes.delete',
            'admin.design-settings.store',
            'admin.grades.store',
            'admin.grades.update',
            'admin.grades.destroy',
            'admin.grades.courses',
            'admin.app-versions.store',
            'admin.app-versions.update',
            'admin.app-versions.destroy',
            'admin.app-versions.toggle-active',
        ] as $route) {
            self::assertStringContainsString($route, $source, $route);
        }

        foreach ([
            "Form::text('name'",
            "Form::email('email'",
            "Form::text('phone'",
            "Form::text('name_ar'",
            "Form::text('name_en'",
            'name="platform"',
            'name="distribution_channel"',
            'name="version_name"',
            'name="version_code"',
            'name="build_number"',
            'name="download_url"',
            'name="color_1"',
            'name="color_2"',
            'name="color_3"',
            'name="color_4"',
        ] as $field) {
            self::assertStringContainsString($field, $source, $field);
        }
        self::assertStringContainsString('name="editor_version"', $source);

        foreach ([
            'function toggleAccordion',
            'function updateDemoColors',
            'function confirmDelete',
            'function highlightSearchResults',
            'function viewStudents',
            'function syncPlatformFields',
            "localStorage.getItem('darkMode')",
            'data-progress-value',
            'aria-valuenow',
        ] as $interaction) {
            self::assertStringContainsString($interaction, $source, $interaction);
        }
    }

    public function test_identity_stylesheets_use_admin_tokens_and_scoped_entry_points(): void
    {
        $stylesheets = glob($this->projectRoot().'/public/admin/assets/css/{users-,grades-}*.css', GLOB_BRACE);
        self::assertIsArray($stylesheets);
        $stylesheets[] = $this->projectRoot().'/public/admin/assets/css/design-settings.css';
        $stylesheets[] = $this->projectRoot().'/public/admin/assets/css/app-versions.css';

        $source = '';
        foreach ($stylesheets as $stylesheet) {
            self::assertFileExists($stylesheet);
            $css = file_get_contents($stylesheet);
            self::assertNotFalse($css);
            self::assertStringContainsString('scoped', strtolower($css), $stylesheet);
            $source .= $css;
        }

        self::assertStringContainsString('--rokn-admin-primary', $source);
        self::assertStringContainsString('--rokn-admin-radius', $source);
        self::assertStringContainsString('.app-versions-page', $source);
        self::assertStringContainsString('.grades-module', $source);
        self::assertStringContainsString('.users-page', $source);
        self::assertStringContainsString('.design-settings-wrapper', $source);
    }

    public function test_identity_families_share_one_theme_runtime(): void
    {
        foreach (['users', 'grades'] as $family) {
            $partial = (string) file_get_contents(
                $this->projectRoot().'/resources/views/admin/'.$family.'/partials/_dynamic_styles.blade.php'
            );
            self::assertStringContainsString("@include('admin.partials.identity-theme'", $partial, $family);
            self::assertStringNotContainsString('function adjustBrightness', $partial, $family);
        }

        $shared = (string) file_get_contents(
            $this->projectRoot().'/resources/views/admin/partials/identity-theme.blade.php'
        );
        self::assertStringContainsString('admin-identity-theme.js', $shared);

        $runtime = (string) file_get_contents(
            $this->projectRoot().'/public/admin/assets/js/admin-identity-theme.js'
        );
        self::assertStringContainsString('data-progress-value', $runtime);
        self::assertStringContainsString("localStorage.getItem('darkMode')", $runtime);
    }

    /** @return array<string, string> */
    private function viewFiles(): array
    {
        $files = [];

        foreach (self::VIEW_FAMILIES as $family) {
            $directory = $this->projectRoot().'/resources/views/admin/'.$family;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[$family.'/'.$relative] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    private function composedViewSource(string $view): string
    {
        $source = (string) file_get_contents($this->projectRoot().'/resources/views/admin/'.$view);

        if (str_starts_with($view, 'users/')) {
            $source .= (string) file_get_contents(
                $this->projectRoot().'/resources/views/admin/users/partials/_dynamic_styles.blade.php'
            );
        } elseif (str_starts_with($view, 'grades/')) {
            $source .= (string) file_get_contents(
                $this->projectRoot().'/resources/views/admin/grades/partials/_dynamic_styles.blade.php'
            );
        }

        return $source;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
