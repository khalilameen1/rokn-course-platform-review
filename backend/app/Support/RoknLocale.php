<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The only locale boundary used by HTTP presentation code.
 *
 * API payload values stay machine-readable. This class only decides which
 * human translation is selected and never lets an arbitrary header value
 * become an application locale or a cache dimension.
 */
final class RoknLocale
{
    public const ARABIC = 'ar';

    public const ENGLISH = 'en';

    /** @return self::ARABIC|self::ENGLISH|null */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $matches = [];
        foreach (explode(',', strtolower(trim($value))) as $index => $candidate) {
            $parts = array_map('trim', explode(';', $candidate));
            $tag = array_shift($parts) ?? '';
            $base = explode('-', str_replace('_', '-', $tag), 2)[0];
            if (! in_array($base, [self::ARABIC, self::ENGLISH], true)) {
                continue;
            }
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (str_starts_with($parameter, 'q')) {
                    $quality = preg_match(
                        '/^q\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)$/',
                        $parameter,
                        $qualityMatch
                    ) ? (float) $qualityMatch[1] : 0.0;
                }
            }
            if ($quality > 0) {
                $matches[] = ['locale' => $base, 'quality' => $quality, 'index' => $index];
            }
        }

        usort($matches, static fn (array $left, array $right): int => ($right['quality'] <=> $left['quality']) ?: ($left['index'] <=> $right['index'])
        );

        return $matches[0]['locale'] ?? null;
    }

    /** @return self::ARABIC|self::ENGLISH */
    public static function fromRequest(Request $request): string
    {
        return self::normalize($request->header('locale'))
            ?? self::normalize($request->header('Accept-Language'))
            ?? self::normalize((string) config('app.locale', self::ARABIC))
            ?? self::ARABIC;
    }

    public static function isArabic(?string $locale = null): bool
    {
        return (self::normalize($locale ?? app()->getLocale()) ?? self::ARABIC) === self::ARABIC;
    }
}
