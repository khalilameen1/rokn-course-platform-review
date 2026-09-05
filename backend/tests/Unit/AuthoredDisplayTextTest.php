<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AuthoredDisplayText;
use PHPUnit\Framework\TestCase;

final class AuthoredDisplayTextTest extends TestCase
{
    public function test_only_direction_boundaries_are_added_to_authored_copy(): void
    {
        foreach ([
            'ريلز 2026',
            'const limit=3',
            'اسم الكورس: Grease Pencil',
            'دورة 2026/3',
            "وسم <html>\nالمقطع الرابع",
        ] as $source) {
            $formatted = AuthoredDisplayText::format($source);
            self::assertSame($source, str_replace(["\u{2068}", "\u{2069}"], '', $formatted));
            self::assertSame($formatted, AuthoredDisplayText::format($formatted));
        }
    }

    public function test_latin_phrases_and_code_are_isolated_as_whole_runs(): void
    {
        self::assertSame(
            "تعلّم \u{2068}Grease Pencil\u{2069} الآن",
            AuthoredDisplayText::format('تعلّم Grease Pencil الآن')
        );
        self::assertSame(
            "جرّب \u{2068}const limit=3\u{2069} هنا",
            AuthoredDisplayText::format('جرّب const limit=3 هنا')
        );
    }

    public function test_incoming_direction_overrides_are_not_carried_to_the_notification(): void
    {
        $formatted = AuthoredDisplayText::format("\u{202E}ريلز 2026\u{202C}");
        self::assertSame('ريلز 2026', str_replace(["\u{2068}", "\u{2069}"], '', $formatted));
    }
}
