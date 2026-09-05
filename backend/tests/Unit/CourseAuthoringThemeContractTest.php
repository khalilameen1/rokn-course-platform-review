<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseAuthoringThemeContractTest extends TestCase
{
    #[DataProvider('authoringStylesheets')]
    public function test_authoring_stylesheets_follow_the_shared_admin_theme(string $file): void
    {
        $css = $this->stylesheet($file);

        self::assertStringContainsString('var(--rokn-admin-text)', $css);
        self::assertStringContainsString('var(--rokn-admin-surface)', $css);
        self::assertStringContainsString('var(--rokn-admin-border)', $css);
        self::assertStringNotContainsString('body.dark-mode', $css);
        self::assertSame(substr_count($css, '{'), substr_count($css, '}'), $file);
    }

    public function test_course_studio_keeps_status_meaning_without_owning_a_second_palette(): void
    {
        $css = $this->stylesheet('course-studio.css');

        foreach (['success', 'warning', 'danger'] as $status) {
            self::assertStringContainsString("var(--rokn-admin-{$status})", $css);
        }

        self::assertDoesNotMatchRegularExpression(
            '/#(?:4653dd|4c59d9|5260d0|6871c9|fff0f0|edf8f4)\b/i',
            $css
        );
    }

    /** @return array<string, array{string}> */
    public static function authoringStylesheets(): array
    {
        return [
            'studio' => ['course-studio.css'],
            'editor' => ['course-editor.css'],
            'workspace' => ['course-workspace.css'],
            'student preview' => ['course-student-preview.css'],
        ];
    }

    private function stylesheet(string $file): string
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/css/'.$file);

        self::assertIsString($css, $file);

        return $css;
    }
}
