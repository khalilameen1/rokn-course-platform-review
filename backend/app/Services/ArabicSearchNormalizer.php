<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UnicodeText;

final class ArabicSearchNormalizer
{
    public function normalize(?string $value): string
    {
        $value = UnicodeText::clean($value, false);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ٲ' => 'ا', 'ٳ' => 'ا', 'ٵ' => 'ا',
            'ى' => 'ي', 'ی' => 'ي', 'ې' => 'ي', 'ۍ' => 'ي', 'ے' => 'ي',
            'ؤ' => 'و', 'ئ' => 'ي',
            'ة' => 'ه', 'ۀ' => 'ه', 'ہ' => 'ه', 'ھ' => 'ه',
            'ک' => 'ك', 'ـ' => '',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $value = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public function courseTitle(?string $arabic, ?string $english): string
    {
        return $this->normalize(trim((string) $arabic.' '.(string) $english));
    }

    public function courseTerms(
        ?string $arabicTitle,
        ?string $englishTitle,
        ?string $arabicDescription,
        ?string $englishDescription
    ): string {
        return $this->normalize(implode(' ', array_filter([
            $arabicTitle,
            $englishTitle,
            $arabicDescription,
            $englishDescription,
        ], fn ($value) => trim((string) $value) !== '')));
    }

    /**
     * Small bounded alternatives for related names that predate normalized
     * search columns. This keeps «احمد» matching «أحمد» without a database-
     * specific REGEXP or an unbounded Cartesian expansion.
     *
     * @return list<string>
     */
    public function relatedNameVariants(?string $value, int $limit = 24): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return [];
        }

        $alternatives = [
            'ا' => ['ا', 'أ', 'إ', 'آ', 'ٱ'],
            'ي' => ['ي', 'ى', 'ئ', 'ی', 'ې', 'ۍ', 'ے'],
            'و' => ['و', 'ؤ'],
            'ه' => ['ه', 'ة', 'ۀ', 'ہ', 'ھ'],
            'ك' => ['ك', 'ک'],
        ];
        $variants = [''];
        foreach (mb_str_split($normalized) as $character) {
            $next = [];
            foreach ($variants as $prefix) {
                foreach ($alternatives[$character] ?? [$character] as $alternative) {
                    $next[] = $prefix.$alternative;
                    if (count($next) >= $limit) {
                        break 2;
                    }
                }
            }
            $variants = $next;
        }

        return array_values(array_unique(array_filter(
            array_merge([trim((string) $value)], $variants),
            fn (string $variant): bool => $variant !== ''
        )));
    }
}
