<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class LegalCommercePolicyTest extends TestCase
{
    public function test_privacy_policies_explain_ai_consent_withdrawal_without_erasing_saved_results(): void
    {
        $arabic = require resource_path('lang/ar/privacy.php');
        $english = require resource_path('lang/en/privacy.php');
        $arabicText = json_encode($arabic, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $englishText = json_encode($english, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-12', $arabic['version']);
        self::assertSame($arabic['version'], $english['version']);
        self::assertStringContainsString('الإعدادات ← الخصوصية والتواصل ← مشاركة البيانات للذكاء الاصطناعي ← إيقاف المشاركة', $arabicText);
        self::assertStringContainsString('تبقى ردودك المحفوظة وقد يكتمل طلب بدأ قبل الإيقاف', $arabicText);
        self::assertStringContainsString('Settings → Privacy and contact → AI data sharing → Stop sharing', $englishText);
        self::assertStringContainsString('A request started before you stop sharing may still finish', $englishText);
    }

    public function test_arabic_terms_and_refund_policy_define_closed_loop_final_coin_purchases(): void
    {
        $terms = json_encode(
            require resource_path('lang/ar/terms.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $returns = json_encode(
            require resource_path('lang/ar/returns.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        self::assertStringContainsString('شراء العملات نهائي بعد تأكيد الدفع', $terms);
        self::assertStringContainsString('لا يمكن بيعها أو تحويلها بين الحسابات أو استبدالها نقدًا', $returns);
        self::assertStringContainsString('حقوقك القانونية وسياسة المتجر', $terms);
        self::assertStringContainsString('وسيلة الدفع الأصلية فقط', $returns);
    }

    public function test_english_policy_keeps_mandatory_rights_and_provider_reversals_operational(): void
    {
        $terms = json_encode(
            require resource_path('lang/en/terms.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $returns = json_encode(
            require resource_path('lang/en/returns.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        self::assertStringContainsString('A coin purchase is final once payment is confirmed', $terms);
        self::assertStringContainsString('Mandatory consumer rights remain', $terms);
        self::assertStringContainsString('coins or access tied to a refunded transaction are adjusted', $returns);
    }
}
