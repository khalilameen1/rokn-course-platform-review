<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ArabicSearchNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArabicSearchNormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function equivalentArabicSpellings(): iterable
    {
        yield 'hamza and diacritics' => ['إِدارة الأعمال', 'اداره الاعمال'];
        yield 'alef maqsura and taa marbuta' => ['صناعة المحتوى', 'صناعه المحتوي'];
        yield 'Persian yeh and kaf' => ['کورس دیزاین', 'كورس ديزاين'];
        yield 'Arabic and Persian digits' => ['المستوى ٣', 'المستوي 3'];
    }

    #[DataProvider('equivalentArabicSpellings')]
    public function test_normalizes_common_arabic_input_variants(
        string $first,
        string $second
    ): void {
        $normalizer = new ArabicSearchNormalizer;

        self::assertSame(
            $normalizer->normalize($first),
            $normalizer->normalize($second)
        );
    }
}
