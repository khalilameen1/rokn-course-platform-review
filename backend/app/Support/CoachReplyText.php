<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Last-mile shaping of learner-facing AI replies into a coach's chat message.
 *
 * The prompt owns the voice. This only removes the formatting a model still
 * leaks: Arabic prose punctuation, markdown markers, list bullets, a lone
 * greeting line and a closing help offer. Code, links and non-Arabic prose
 * keep their punctuation. The result is deterministic, so a replayed
 * provider result settles to the same stored text.
 */
final class CoachReplyText
{
    private const ARABIC = '[\x{0621}-\x{064A}\x{066E}-\x{06D3}\x{06FA}-\x{06FF}\x{0660}-\x{0669}]';
    private const TOKEN = "\u{E000}%d\u{E001}";
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function format(string $text): string
    {
        $original = trim($text);
        if ($original === '') {
            return '';
        }

        $protected = [];
        $text = self::protect($original, $protected);
        $a = self::ARABIC;

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // House style writes digits 0-9 in Arabic prose.
        $text = strtr($text, self::DIGITS);
        // Markdown is never rendered by the app, so markers show as noise.
        $text = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*|__(.+?)__/u', '$1$2', $text);
        $text = (string) preg_replace('/(?<![\*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\*\w])/u', '$1', $text);
        $text = (string) preg_replace(
            '/^[ \t]*(?:[-*•▪◦·]|\d{1,2}[.)]|[٠-٩]{1,2}[.)])[ \t]+/mu',
            '',
            $text
        );
        // Arabic prose punctuation. A sentence end becomes a line break, the
        // way a coach sends one short sentence per line.
        $text = (string) preg_replace('/[ \t]*[،؛][ \t]*/u', ' ', $text);
        $text = (string) preg_replace('/(?<=' . $a . ')[ \t]*[,;][ \t]*/u', ' ', $text);
        $text = (string) preg_replace('/(?<=' . $a . $a . ')(?:\.{1,3}|…)[ \t]+(?=\S)/u', "\n", $text);
        $text = (string) preg_replace('/(?<=' . $a . ')(?:\.{1,3}|…)[ \t]*$/mu', '', $text);
        $text = (string) preg_replace('/(?<=' . $a . ')[ \t]*:[ \t]*$/mu', '', $text);
        $text = (string) preg_replace('/(?<=' . $a . ')[ \t]*:[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/(?<=' . $a . ')[ \t]+[—–-][ \t]+(?=' . $a . ')/u', ' ', $text);

        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t]{2,}/u', ' ', $line)),
            explode("\n", $text)
        );
        $lines = self::withoutGreeting($lines);
        $lines = self::withoutHelpOffer($lines);
        $text = trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $lines)));

        $text = self::restore($text, $protected);

        return $text === '' ? $original : $text;
    }

    /** @param array<int, string> $protected */
    private static function protect(string $text, array &$protected): string
    {
        // Unclosed fences happen mid-stream; protect them to the end.
        return (string) preg_replace_callback(
            '/```.*?(?:```|\z)|`[^`\n]+`|https?:\/\/\S+|www\.\S+|[\w.+-]+@[\w-]+\.[\w.-]+|\b[\w-]+\.[A-Za-z0-9]{1,5}\b/su',
            static function (array $match) use (&$protected): string {
                $protected[] = $match[0];

                return sprintf(self::TOKEN, count($protected) - 1);
            },
            $text
        );
    }

    /** @param array<int, string> $protected */
    private static function restore(string $text, array $protected): string
    {
        return (string) preg_replace_callback(
            '/\x{E000}(\d+)\x{E001}/u',
            static fn (array $match): string => $protected[(int) $match[1]] ?? '',
            $text
        );
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function withoutGreeting(array $lines): array
    {
        $first = self::firstContentIndex($lines);
        if ($first === null || self::contentLines($lines) < 2) {
            return $lines;
        }
        if (preg_match(
            '/^(?:أهلا|اهلا|أهلاً|اهلاً|مرحبا|مرحباً|سؤال (?:حلو|ممتاز|جميل|رائع|مهم|جامد|كويس)(?: جدا| جدًا| جداً)?|بالتأكيد|بالتاكيد)(?: بيك| يا [^\n]{1,15})?[\s!?؟]*$/u',
            $lines[$first]
        )) {
            unset($lines[$first]);
        }

        return array_values($lines);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function withoutHelpOffer(array $lines): array
    {
        $last = self::lastContentIndex($lines);
        if ($last === null || self::contentLines($lines) < 2 || mb_strlen($lines[$last]) > 70) {
            return $lines;
        }
        if (preg_match(
            '/(?:أساعدك|اساعدك|تحتاج مساعدة|محتاج مساعدة|عايز مساعدة|حاجة تانية|حاجه تانيه|سؤال تاني|سؤال تانى|استفسار تاني)[^\n]{0,20}[؟?!]?\s*$/u',
            $lines[$last]
        )) {
            unset($lines[$last]);
        }

        return array_values($lines);
    }

    /** @param list<string> $lines */
    private static function contentLines(array $lines): int
    {
        return count(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /** @param list<string> $lines */
    private static function firstContentIndex(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if ($line !== '') {
                return $index;
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private static function lastContentIndex(array $lines): ?int
    {
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            if ($lines[$index] !== '') {
                return $index;
            }
        }

        return null;
    }
}
