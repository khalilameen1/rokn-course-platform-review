<?php

declare(strict_types=1);

namespace App\Support;

/** Keep authored words and digits intact while isolating ASCII phrases in RTL. */
final class AuthoredDisplayText
{
    public static function format(mixed $value): string
    {
        $text = UnicodeText::clean($value);

        return preg_replace_callback(
            '/[\x21-\x7E](?:[\x20-\x7E\t]*[\x21-\x7E])?/u',
            static fn (array $match): string => preg_match('/[A-Za-z0-9]/', $match[0])
                ? "\u{2068}".$match[0]."\u{2069}"
                : $match[0],
            $text
        ) ?? $text;
    }
}
