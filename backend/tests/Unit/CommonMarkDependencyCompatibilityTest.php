<?php

declare(strict_types=1);

namespace Tests\Unit;

use Composer\InstalledVersions;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use PHPUnit\Framework\TestCase;

final class CommonMarkDependencyCompatibilityTest extends TestCase
{
    public function test_installed_commonmark_contains_the_upstream_security_fixes(): void
    {
        self::assertTrue(version_compare(
            (string) InstalledVersions::getVersion('league/commonmark'),
            '2.10.2',
            '>='
        ));
    }

    public function test_arabic_text_headings_and_emphasis_still_render(): void
    {
        $converter = new GithubFlavoredMarkdownConverter();
        $html = (string) $converter->convert("# رُكن\n\n**تقرير المشروع**\n\nخطوة جديدة في الكورس");

        self::assertStringContainsString('<h1>رُكن</h1>', $html);
        self::assertStringContainsString('<strong>تقرير المشروع</strong>', $html);
        self::assertStringContainsString('<p>خطوة جديدة في الكورس</p>', $html);
    }

    public function test_gfm_tables_and_normal_multiline_paragraphs_still_render(): void
    {
        $converter = new GithubFlavoredMarkdownConverter();
        $html = (string) $converter->convert("المشروع | النتيجة\n--- | ---\nالأول | مكتمل\n\nسطر أول\nسطر ثان");

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>المشروع</th>', $html);
        self::assertStringContainsString('<td>مكتمل</td>', $html);
        self::assertStringContainsString("<p>سطر أول\nسطر ثان</p>", $html);
    }

    public function test_bare_disallowed_html_tag_names_are_escaped_by_the_upstream_renderer(): void
    {
        // Upstream regression shape: GHSA-97jj-33gv-5xf9; no custom sanitizer.
        $converter = new GithubFlavoredMarkdownConverter();

        foreach (['script', 'iframe'] as $tag) {
            $html = (string) $converter->convert("<div>\n<{$tag}\n\n<span src=\"/example.js\">\n");

            self::assertStringContainsString('&lt;'.$tag, $html);
            self::assertStringNotContainsString('<'.$tag, $html);
        }
    }
}
