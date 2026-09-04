<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AdminInlineStyleContractTest extends TestCase
{
    private const STYLE_BLOCK_ALLOW_LIST = [
        'course-codes/pdf.blade.php',
        'course-codes/partials/_dynamic_styles.blade.php',
        'courses/partials/_dynamic_styles.blade.php',
        'home/partials/_dynamic_styles.blade.php',
        'orders/partials/_dynamic_styles.blade.php',
        'urgent-tasks/partials/_dynamic_styles.blade.php',
    ];

    public function test_interactive_admin_views_have_no_inline_styles(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views/admin';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $styleBlocks = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $source = file_get_contents($file->getPathname());
            self::assertNotFalse($source);
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $relative);
            self::assertDoesNotMatchRegularExpression('/[\'\"]style[\'\"]\s*=>/i', $source, $relative);

            if (stripos($source, '<style') !== false) {
                $styleBlocks[] = $relative;
            }
        }

        sort($styleBlocks);
        $allowList = self::STYLE_BLOCK_ALLOW_LIST;
        sort($allowList);
        self::assertSame($allowList, $styleBlocks);
    }

    #[DataProvider('extractedScreens')]
    public function test_extracted_screens_load_their_dashboard_asset(
        string $view,
        string $asset,
        array $contracts
    ): void {
        $source = $this->viewSource($view);
        self::assertStringContainsString($asset, $source);
        self::assertStringContainsString('admin-page', $source);
        foreach ($contracts as $contract) {
            self::assertStringContainsString($contract, $source);
        }
    }

    public function test_course_attachments_are_edited_inside_the_course_studio(): void
    {
        $show = $this->viewSource('courses/show.blade.php');
        $panel = $this->viewSource('courses/partials/show/course-attachments-panel.blade.php');

        self::assertStringContainsString('course-attachments-panel', $show);
        self::assertStringContainsString('id="studioCourseAttachments"', $panel);
        self::assertStringContainsString('$coursePdfStoreUrl', $panel);
        self::assertStringContainsString('$coursePdfReorderUrl', $panel);
        self::assertStringContainsString("admin.course-pdfs.partials.form", $panel);
    }

    public function test_shared_shell_styles_load_after_page_styles(): void
    {
        $layout = $this->viewSource('layouts/app.blade.php');
        $stackPosition = strpos($layout, "@stack('styles')");
        $shellPosition = strpos($layout, 'admin/assets/css/admin-shell.css');

        self::assertNotFalse($stackPosition);
        self::assertNotFalse($shellPosition);
        self::assertGreaterThan($stackPosition, $shellPosition);

        $header = $this->viewSource('includes/header.blade.php');
        $aside = $this->viewSource('includes/aside.blade.php');
        $alert = $this->viewSource('includes/alert.blade.php');
        self::assertStringContainsString('id="logoutForm"', $header);
        self::assertStringContainsString('class="d-none" id="logoutForm"', $header);
        self::assertStringContainsString('modern-sidebar', $aside);
        self::assertStringContainsString('data-close-alert', $alert);
        self::assertStringNotContainsString('<script', $header);
        self::assertStringNotContainsString('<script', $alert);
    }

    public function test_retired_dashboard_palette_cannot_return(): void
    {
        foreach ([
            dirname(__DIR__, 2).'/public/admin',
            dirname(__DIR__, 2).'/resources/views/admin',
        ] as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['css', 'js', 'php'], true)) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                self::assertNotFalse($source);
                self::assertDoesNotMatchRegularExpression(
                    '/#(?:667eea|764ba2|092783)\b/i',
                    $source,
                    $file->getPathname().' still uses the retired dashboard palette.'
                );
            }
        }
    }

    /** @return array<string, array{string, string, array<int, string>}> */
    public static function extractedScreens(): array
    {
        return [
            'admin account settings' => [
                'settings/admin_data.blade.php',
                'admin/assets/css/settings-admin-data.css',
                ['admin.update_admin_data', "Form::text('email'", 'name="password"'],
            ],
        ];
    }

    private function viewSource(string $view): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);
        self::assertNotFalse($source);

        return $source;
    }
}
