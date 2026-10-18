<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PublicDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'about' => '/about',
        'privacy' => '/privacy-policy',
        'terms' => '/terms',
        'returns' => '/returns-policy',
    ];

    public function test_arabic_app_snapshot_and_public_pages_match_the_authoritative_documents(): void
    {
        // The mobile app ships an offline copy of the same documents.
        $snapshot = json_decode(
            (string) file_get_contents(base_path('../mobile/src/content/publicPages.ar.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach (self::ROUTES as $page => $url) {
            $document = trans($page, [], 'ar');
            self::assertIsArray($document, $page);
            self::assertSame($document, $snapshot[$page], 'Regenerate the mobile public-content snapshot');

            $html = $this->page($url)->getContent();
            self::assertStringContainsString(e($document['intro_text']), $html);
            foreach ($document['sections'] as $section) {
                self::assertStringContainsString('<h2>'.e($section['title']).'</h2>', $html);
                foreach ($section['body'] as $paragraph) {
                    self::assertStringContainsString('<p>'.e($paragraph).'</p>', $html);
                }
            }
            $article = $this->articleText($html);
            self::assertSame(1, substr_count($article, '.'), $page);
            self::assertDoesNotMatchRegularExpression('/[،,;؛:—–]/u', $article, $page);
            self::assertStringEndsWith('.', trim($article));
        }
    }

    public function test_seed_prompts_do_not_hide_documents_but_dashboard_edits_are_preserved(): void
    {
        // The migration seeds one row of prompts; the dashboard edits that row.
        self::assertSame(1, DB::table('abouts')->count());
        DB::table('abouts')->update([
            'privacy_ar' => 'راجع سياسة الخصوصية المنشورة داخل التطبيق.',
            'policy_ar' => 'راجع شروط الاستخدام المنشورة داخل التطبيق.',
            'about_ar' => "نص حفظه الأدمن\n<script>unsafe</script>",
        ]);

        // Seed prompts are ignored, so the authoritative documents render.
        foreach (['privacy', 'terms'] as $page) {
            $html = $this->page(self::ROUTES[$page])->getContent();
            self::assertStringContainsString(e(trans($page.'.intro_text', [], 'ar')), $html, $page);
            self::assertStringNotContainsString('المنشورة داخل التطبيق', $html, $page);
        }

        // A genuine dashboard edit replaces the default about text, escaped.
        $about = $this->page(self::ROUTES['about'])->getContent();
        self::assertStringContainsString('نص حفظه الأدمن', $about);
        self::assertStringNotContainsString('<script>unsafe</script>', $about);
        self::assertStringNotContainsString(e(trans('about.intro_text', [], 'ar')), $about);
    }

    private function page(string $url): TestResponse
    {
        $this->flushSession();

        return $this->get($url.'?lang=ar')->assertOk();
    }

    private function articleText(string $html): string
    {
        self::assertSame(1, preg_match('/<article[^>]*>(.*?)<\/article>/s', $html, $matches));

        return html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
