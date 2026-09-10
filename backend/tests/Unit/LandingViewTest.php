<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DesignSetting;
use App\Models\Setting;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class LandingViewTest extends TestCase
{
    public function test_prelaunch_renders_official_badges_without_fake_download_links(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $html = view('landing.index', $this->pageData($locale))->render();

            self::assertStringContainsString('lang="'.$locale.'" dir="'.$direction.'"', $html);
            self::assertSame(4, substr_count($html, 'class="store-btn" aria-disabled="true"'));
            self::assertStringContainsString('images/landing/app-store.svg', $html);
            self::assertStringContainsString('images/landing/google-play.svg', $html);
            foreach (['photography', 'design', 'drawing'] as $subject) {
                self::assertStringContainsString('images/landing/'.$subject.'.webp', $html);
            }
            self::assertStringNotContainsString('rokn-home.webp', $html);
            self::assertStringNotContainsString('rokn-lesson.webp', $html);
            self::assertStringNotContainsString('href="#"', $html);
            self::assertStringNotContainsString('data-download-dock', $html);
            self::assertStringNotContainsString('<iframe', $html);
            self::assertStringContainsString(route('privacy'), $html);
            self::assertStringContainsString(route('account-deletion.show'), $html);
        }
    }

    public function test_each_configured_channel_keeps_its_exact_url_and_other_badges_stay_visible(): void
    {
        $urls = [
            'play' => 'https://play.google.com/store/apps/details?id=com.rokn.app',
            'appstore' => 'https://apps.apple.com/app/id123456789',
            'direct' => 'https://example.com/downloads/rokn.apk?release=54&channel=direct',
        ];

        foreach (array_keys($urls) as $channel) {
            $html = view('landing.partials.download-buttons', [
                'downloadChannels' => [$channel => $urls[$channel]],
                'directDiscountPercent' => 10,
            ])->render();

            self::assertSame(1, substr_count($html, '<a '));
            self::assertStringContainsString('href="'.e($urls[$channel]).'"', $html);
            self::assertStringContainsString('data-channel="'.$channel.'"', $html);
            self::assertSame($channel === 'direct' ? 2 : 1, substr_count($html, 'aria-disabled="true"'));
            self::assertSame($channel === 'direct' ? 1 : 0, substr_count($html, 'class="direct-saving"'));
        }

        $html = view('landing.partials.download-buttons', [
            'downloadChannels' => $urls,
            'directDiscountPercent' => 0,
        ])->render();

        self::assertSame(3, substr_count($html, '<a '));
        self::assertStringNotContainsString('aria-disabled', $html);
        self::assertStringNotContainsString('class="direct-saving"', $html);
    }

    public function test_dashboard_copy_seo_and_optional_video_remain_connected(): void
    {
        $data = $this->pageData('ar');
        $data['designSetting']->forceFill([
            'slogan_1_ar' => 'عنوان محفوظ',
            'slogan_2_ar' => 'وصف محفوظ',
            'slogan_3_ar' => 'نص تحميل محفوظ',
            'show_how_platform_works' => true,
        ]);
        $data['designSetting']->exists = true;
        $data['setting'] = new Setting(['seo_meta_title_ar' => 'عنوان البحث']);
        $data['howPlatformWorksVideoUrl'] = 'https://www.youtube.com/embed/test-video';
        $data['downloadChannels'] = ['play' => 'https://play.google.com/store/apps/details?id=com.rokn.app'];

        app()->setLocale('ar');
        $html = view('landing.index', $data)->render();

        self::assertStringContainsString('<title>عنوان البحث</title>', $html);
        self::assertStringContainsString('<span>عنوان</span>', $html);
        self::assertStringContainsString('وصف محفوظ', $html);
        self::assertStringContainsString('نص تحميل محفوظ', $html);
        self::assertStringContainsString('src="'.$data['howPlatformWorksVideoUrl'].'"', $html);
        self::assertStringContainsString('data-download-dock', $html);
    }

    public function test_shared_public_pages_render_without_download_data_and_keep_one_main_landmark(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $data = $this->pageData($locale);
            unset($data['downloadChannels'], $data['directDiscountPercent'], $data['howPlatformWorksVideoUrl']);
            $data['errors'] = new ViewErrorBag();

            foreach (['about', 'contact', 'privacy', 'terms', 'returns', 'account-deletion'] as $page) {
                $html = view('static.'.$page, $data)->render();
                self::assertSame(1, substr_count($html, '<main '), $page.' / '.$locale);
                self::assertStringContainsString('images/rokn-wordmark.png', $html);
                self::assertStringContainsString(route('landing'), $html);
            }
        }
    }

    private function pageData(string $locale): array
    {
        return [
            'setting' => null,
            'designSetting' => new DesignSetting(['name_ar' => 'ركن', 'name_en' => 'Rokn']),
            'locale' => $locale,
            'downloadChannels' => [],
            'directDiscountPercent' => 0,
            'howPlatformWorksVideoUrl' => null,
        ];
    }
}
