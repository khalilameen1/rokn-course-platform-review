<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DesignSetting;
use App\Services\ManagedPublicContentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PublicDocumentsTest extends TestCase
{
    public function test_arabic_app_snapshot_and_rendered_articles_match_the_authoritative_documents(): void
    {
        $snapshot = json_decode(file_get_contents(base_path('../mobile/src/content/publicPages.ar.json')), true, 512, JSON_THROW_ON_ERROR);
        app()->setLocale('ar');

        foreach (['about', 'privacy', 'terms', 'returns'] as $page) {
            $document = require resource_path('lang/ar/'.$page.'.php');
            self::assertSame($document, $snapshot[$page], 'Regenerate the mobile public-content snapshot');
            $html = view('static.'.$page, [
                'setting' => null,
                'designSetting' => new DesignSetting(['name_ar' => 'ركن']),
                'locale' => 'ar',
            ])->render();
            self::assertStringContainsString(e($document['intro_text']), $html);
            foreach ($document['sections'] as $section) {
                self::assertStringContainsString('<h2>'.e($section['title']).'</h2>', $html);
                foreach ($section['body'] as $paragraph) {
                    self::assertStringContainsString('<p>'.e($paragraph).'</p>', $html);
                }
            }
            preg_match('/<article[^>]*>(.*?)<\/article>/s', $html, $matches);
            $article = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertSame(1, substr_count($article, '.'), $page);
            self::assertDoesNotMatchRegularExpression('/[،,;؛:—–]/u', $article, $page);
            self::assertStringEndsWith('.', trim($article));
        }
    }

    public function test_seed_prompts_do_not_hide_documents_but_dashboard_edits_are_preserved(): void
    {
        Schema::create('abouts', function (Blueprint $table): void {
            $table->id();
            foreach (['about', 'privacy', 'policy'] as $field) {
                $table->text($field.'_ar')->nullable();
                $table->text($field.'_en')->nullable();
            }
        });
        try {
            DB::table('abouts')->insert([
                'privacy_ar' => 'راجع سياسة الخصوصية المنشورة داخل التطبيق.',
                'policy_en' => 'See the terms of use published in the application.',
                'about_ar' => 'نص حفظه الأدمن',
            ]);
            $service = app(ManagedPublicContentService::class);
            self::assertNull($service->body('privacy', 'ar'));
            self::assertNull($service->body('terms', 'en'));
            self::assertSame('نص حفظه الأدمن', $service->body('about', 'ar'));
            $html = view('static.about', [
                'setting' => null,
                'designSetting' => new DesignSetting(['name_ar' => 'ركن']),
                'locale' => 'ar',
                'managedBody' => "نص حفظه الأدمن\n<script>unsafe</script>",
            ])->render();
            self::assertStringContainsString('نص حفظه الأدمن', $html);
            self::assertStringNotContainsString('<script>unsafe</script>', $html);
            self::assertStringNotContainsString(e(__('about.intro_text')), $html);
        } finally {
            Schema::dropIfExists('abouts');
        }
    }
}
