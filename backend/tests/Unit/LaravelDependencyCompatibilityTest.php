<?php

declare(strict_types=1);

namespace Tests\Unit;

use Composer\InstalledVersions;
use Illuminate\Mail\Markdown;
use PHPUnit\Framework\TestCase;

final class LaravelDependencyCompatibilityTest extends TestCase
{
    public function test_installed_laravel_contains_the_official_debug_page_fix(): void
    {
        self::assertTrue(version_compare(
            (string) InstalledVersions::getVersion('laravel/framework'),
            '12.69.0',
            '>='
        ));
    }

    public function test_framework_markdown_mail_still_renders_arabic_content(): void
    {
        $html = (string) Markdown::parse("# رُكن\n\n**تقرير المشروع**\n\nيمكنك متابعة الكورس");

        self::assertStringContainsString('<h1>رُكن</h1>', $html);
        self::assertStringContainsString('<strong>تقرير المشروع</strong>', $html);
        self::assertStringContainsString('<p>يمكنك متابعة الكورس</p>', $html);
    }
}
