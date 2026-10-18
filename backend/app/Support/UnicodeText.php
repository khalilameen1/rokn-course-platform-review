<?php

declare(strict_types=1);

namespace App\Support;

final class UnicodeText
{
    private const BIDI_AND_INVISIBLE_PATTERN =
        '/[\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    /** Normalize user-authored display text without changing its language. */
    public static function clean(mixed $value, bool $multiline = true): string
    {
        $text = self::validUtf8((string) $value);
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        $text = preg_replace(self::BIDI_AND_INVISIBLE_PATTERN, '', $text) ?? '';
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace(
            '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u',
            ' ',
            $text
        ) ?? $text;
        if (! $multiline) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        }

        return trim($text);
    }

    /** Normalize human-entered ASCII codes and digits before lookup/uniqueness. */
    public static function identifier(mixed $value): string
    {
        $text = self::validUtf8((string) $value);
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $text = preg_replace(self::BIDI_AND_INVISIBLE_PATTERN, '', $text) ?? '';
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/\s+/u', '', $text) ?? '';

        return mb_strtoupper($text, 'UTF-8');
    }

    public static function graphemeLength(string $value): int
    {
        if (function_exists('grapheme_strlen')) {
            $length = grapheme_strlen($value);
            if ($length !== false) {
                return $length;
            }
        }
        if (preg_match_all('/\X/u', $value, $matches) !== false) {
            return count($matches[0]);
        }

        return mb_strlen($value, 'UTF-8');
    }

    public static function limit(string $value, int $maximum): string
    {
        if ($maximum <= 0) {
            return '';
        }
        if (self::graphemeLength($value) <= $maximum) {
            return $value;
        }
        if (function_exists('grapheme_substr')) {
            $limited = grapheme_substr($value, 0, $maximum);
            if ($limited !== false) {
                return $limited;
            }
        }
        if (preg_match_all('/\X/u', $value, $matches) !== false) {
            return implode('', array_slice($matches[0], 0, $maximum));
        }

        return mb_substr($value, 0, $maximum, 'UTF-8');
    }

    private static function validUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $repaired = function_exists('iconv')
            ? iconv('UTF-8', 'UTF-8//IGNORE', $value)
            : false;

        return is_string($repaired) ? $repaired : '';
    }
}
