<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\CoinEarningMethodResource;
use App\Models\CoinEarningMethod;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CoinTaskPresentationTest extends TestCase
{
    #[DataProvider('socialTitles')]
    public function test_social_task_presents_the_action_not_the_visit_mechanism(
        string $key, string $arabic, string $english, string $expectedArabic, string $expectedEnglish
    ): void {
        $method = new CoinEarningMethod([
            'action_key' => $key,
            'title_ar' => $arabic,
            'title_en' => $english,
            'coins_amount' => 15,
            'action_url' => 'https://instagram.com/rokn.app',
            'requires_external_visit' => true,
        ]);
        $method->setAttribute('task_state', 'available');
        $resource = (new CoinEarningMethodResource($method))->toArray(Request::create('/'));

        self::assertSame($expectedArabic, $resource['title_ar']);
        self::assertSame($expectedEnglish, $resource['title_en']);
        self::assertSame('available', $resource['task_state']);
        self::assertFalse($resource['is_consumed']);
        self::assertTrue($resource['requires_external_visit']);
        self::assertSame(15, $resource['coins_amount']);
        self::assertSame($arabic, $method->title_ar);
        self::assertSame($english, $method->title_en);
    }

    public static function socialTitles(): array
    {
        return [
            ['follow_instagram', 'افتح حساب ركن على Instagram', 'Open our Instagram account', 'تابعنا على Instagram', 'Follow us on Instagram'],
            ['follow_tiktok', 'زر صفحة ركن على TikTok', 'Visit our TikTok page', 'تابعنا على TikTok', 'Follow us on TikTok'],
            ['follow_facebook', 'تصفح حساب Facebook', 'Browse our Facebook account', 'تابعنا على Facebook', 'Follow us on Facebook'],
            ['follow_youtube', 'افتح YouTube وارجع لاستلام العملات', 'Open YouTube then return and claim', 'تابعنا على YouTube', 'Follow us on YouTube'],
            ['link_whatsapp', 'افتح حساب واتساب', 'Open WhatsApp', 'اربط واتسابك بركن', 'Link WhatsApp to Rokn'],
        ];
    }

    public function test_authored_campaign_titles_and_non_social_tasks_keep_their_words(): void
    {
        foreach ([
            ['follow_instagram', 'تابعنا على Instagram', 'Follow us on Instagram'],
            ['follow_tiktok', 'تابع سلسلة التصميم الجديدة', 'Follow our new design series'],
            ['coin_guide', 'افتح دليل العملات', 'Open the coin guide'],
            ['custom_task', 'افتح مشروعك الأول', 'Open your first project'],
        ] as [$key, $arabic, $english]) {
            $method = new CoinEarningMethod([
                'action_key' => $key, 'title_ar' => $arabic, 'title_en' => $english,
            ]);
            self::assertSame($arabic, $method->learnerTitleAr());
            self::assertSame($english, $method->learnerTitleEn());
        }
    }
}
