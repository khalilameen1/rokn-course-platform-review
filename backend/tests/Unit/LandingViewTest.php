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
            self::assertSame(2, substr_count($html, 'class="store-btn" aria-disabled="true"'));
            self::assertSame(1, substr_count($html, 'data-download-options'));
            self::assertStringNotContainsString('class="download-note"', $html);
            self::assertStringContainsString('images/landing/app-store.svg', $html);
            self::assertStringContainsString('images/landing/google-play.svg', $html);
            self::assertSame(1, substr_count($html, 'class="preview-player"'));
            self::assertSame(1, substr_count($html, 'id="hero-title"'));
            self::assertStringNotContainsString('class="hero-kicker"', $html);
            self::assertStringNotContainsString('class="hero-description"', $html);
            self::assertStringNotContainsString('<span>ROKN</span>', $html);
            self::assertStringNotContainsString('images/landing/photography.webp', $html);
            self::assertSame(1, substr_count($html, 'class="preview-scene motion-lesson"'));
            self::assertStringNotContainsString('images/landing/brand-design-skincare.webp', $html);
            self::assertStringNotContainsString('images/landing/brand-design-photoshop.webp', $html);
            self::assertStringNotContainsString('editor-workspace', $html);
            self::assertStringContainsString(__('landing.reel_likes_vs_sales'), $html);
            foreach (['lesson_likes_lead', 'lesson_likes_bridge', 'lesson_likes_result'] as $copy) {
                self::assertStringContainsString(e(__('landing.'.$copy)), $html);
            }
            self::assertSame(3, substr_count($html, 'class="player-action-label"'));
            self::assertStringNotContainsString('images/landing/design.webp', $html);
            self::assertStringNotContainsString('images/landing/drawing.webp', $html);
            self::assertStringNotContainsString('rokn-home.webp', $html);
            self::assertStringNotContainsString('rokn-lesson.webp', $html);
            self::assertStringNotContainsString('data-preview-scenes', $html);
            self::assertStringNotContainsString('معاينة واجهة المشغّل', $html);
            self::assertStringNotContainsString('عبر كاشير', $html);
            self::assertStringNotContainsString('نفس الحساب', $html);
            self::assertStringNotContainsString(route('web-wallet.index'), $html);
            self::assertStringNotContainsString('class="account-nav"', $html);
            self::assertStringNotContainsString('class="recharge-section"', $html);
            self::assertStringNotContainsString('class="recharge-nav"', $html);
            self::assertStringNotContainsString('class="welcome-gift"', $html);
            self::assertStringNotContainsString('<details class="download-alternatives"', $html);
            self::assertStringNotContainsString('href="#"', $html);
            self::assertStringNotContainsString('data-download-dock', $html);
            self::assertStringNotContainsString('<iframe', $html);
            self::assertStringContainsString(route('privacy'), $html);
            self::assertStringNotContainsString(route('account-deletion.show'), $html);
            self::assertSame(1, substr_count($html, 'class="footer-nav"'));
            self::assertSame(1, substr_count($html, 'images/rokn-wordmark.png'));
            self::assertSame(1, substr_count($html, 'images/rokn-symbol.png'));
        }
    }

    public function test_configured_stores_keep_exact_urls_and_missing_store_stays_visible(): void
    {
        $urls = [
            'play' => 'https://play.google.com/store/apps/details?id=com.rokn.app',
            'appstore' => 'https://apps.apple.com/app/id123456789',
            'direct' => 'https://example.com/downloads/rokn.apk?release=54&channel=direct',
        ];

        foreach (['play', 'appstore'] as $channel) {
            $html = view('landing.partials.download-buttons', [
                'downloadChannels' => [$channel => $urls[$channel]],
                'directDiscountPercent' => 10,
            ])->render();

            self::assertSame(1, substr_count($html, '<a '));
            self::assertStringContainsString('href="'.e($urls[$channel]).'"', $html);
            self::assertStringContainsString('data-channel="'.$channel.'"', $html);
            self::assertSame(1, substr_count($html, 'aria-disabled="true"'));
            self::assertStringContainsString('class="store-availability"', $html);
            self::assertStringNotContainsString('class="direct-saving"', $html);
        }

        $html = view('landing.partials.download-buttons', [
            'downloadChannels' => $urls,
            'directDiscountPercent' => 0,
        ])->render();

        self::assertSame(2, substr_count($html, '<a '));
        self::assertStringNotContainsString('aria-disabled', $html);
        self::assertStringNotContainsString('class="direct-saving"', $html);
        self::assertStringNotContainsString($urls['direct'], $html);
        self::assertStringNotContainsString('APK', $html);
    }

    public function test_direct_only_release_does_not_create_download_or_sticky_action(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $data = $this->pageData($locale);
            $data['downloadChannels'] = ['direct' => 'https://example.com/rokn.apk'];
            $data['directDownloadIsPreview'] = true;
            $data['directDiscountPercent'] = 10;
            $html = view('landing.index', $data)->render();

            self::assertStringNotContainsString('APK', $html);
            self::assertStringNotContainsString('example.com/rokn.apk', $html);
            self::assertStringNotContainsString('data-channel="direct"', $html);
            self::assertStringNotContainsString('data-download-dock', $html);
            self::assertStringNotContainsString('class="direct-saving"', $html);
            self::assertStringContainsString(__('landing.release_unavailable'), $html);
        }
    }

    public function test_welcome_amount_and_learning_details_are_localized_without_apk_promotions(): void
    {
        app()->setLocale('ar');
        $data = $this->pageData('ar');
        $data['welcomeCoins'] = 37;
        $data['directDiscountPercent'] = 12.5;
        $html = view('landing.index', $data)->render();

        self::assertStringContainsString('<bdi dir="ltr">٣٧</bdi>', $html);
        self::assertStringContainsString('هدية لو لسه جديد', $html);
        self::assertStringNotContainsString('تشتري بيها أول كورس', $html);
        self::assertSame(1, substr_count($html, 'class="welcome-gift"'));
        self::assertGreaterThan(strpos($html, 'images/landing/google-play.svg'), strpos($html, 'class="welcome-gift"'));
        self::assertStringContainsString('مفيش دقيقة حشو', $html);
        self::assertCount(4, __('landing.learning_benefits'));
        self::assertStringContainsString('اسأل لحد ما تفهم', $html);
        self::assertStringContainsString('طبّق عشان تعدّي', $html);
        self::assertStringContainsString('اعرف غلطك وصلّحه', $html);
        self::assertStringContainsString('بورتفوليو مش مجرد شهادة', $html);
        self::assertStringNotContainsString('خصم 12.5%', $html);
        self::assertStringContainsString('شهادة فيها QR لملف أعمالك', $html);
        self::assertStringContainsString('المشاريع في الكورسات العملية', $html);
        self::assertStringNotContainsString('على الشحن', $html);
        self::assertStringNotContainsString('اشحن بخصم', $html);
        self::assertStringContainsString('سكرول', $html);
        self::assertStringContainsString('واتعلّم', $html);

        app()->setLocale('en');
        $data['locale'] = 'en';
        $data['welcomeCoins'] = 7890;
        $html = view('landing.index', $data)->render();
        self::assertStringContainsString('<bdi dir="ltr">7,890</bdi>', $html);
        self::assertStringContainsString('A gift for new learners', $html);

        $data['welcomeCoins'] = 0;
        $data['directDiscountPercent'] = 0;
        $html = view('landing.index', $data)->render();
        self::assertStringNotContainsString('class="welcome-gift"', $html);
        self::assertStringNotContainsString('اشحن بخصم', $html);
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
        self::assertStringContainsString('<meta name="description" content="وصف محفوظ">', $html);
        self::assertStringNotContainsString('class="hero-description"', $html);
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
                self::assertSame(1, substr_count($html, 'images/rokn-wordmark.png'));
                self::assertSame(1, substr_count($html, 'images/rokn-symbol.png'));
                self::assertStringContainsString(route('landing'), $html);
                if ($page === 'contact') {
                    self::assertStringContainsString('href="rokn://contact"', $html);
                }
                if ($page === 'privacy') {
                    self::assertStringContainsString(route('account-deletion.show'), $html);
                }
            }
        }
    }

    public function test_chat_demo_has_a_localized_fixed_exchange_and_a_download_gate(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = view('landing.index', $this->pageData($locale))->render();
            self::assertStringContainsString('aria-controls="course-chat-demo" hidden', $html);
            self::assertStringContainsString('<dialog id="course-chat-demo"', $html);
            self::assertStringContainsString('aria-labelledby="chat-demo-title"', $html);
            self::assertStringContainsString(e(__('landing.chat_question')), $html);
            self::assertStringContainsString(e(__('landing.chat_answer')), $html);
            self::assertStringContainsString('data-chat-gate hidden', $html);
            self::assertStringContainsString('href="#download" data-chat-download', $html);
            self::assertSame(2, substr_count($html, 'data-chat-use'));
            self::assertStringNotContainsString('<textarea', $html);
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
