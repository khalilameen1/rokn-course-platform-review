<?php

declare(strict_types=1);

namespace App\Support;

final class FacebookGraphVersion
{
    private const MIN_SUPPORTED_MAJOR = 21;

    private const CURRENT_MAJOR = 26;

    public static function normalize(mixed $value): ?string
    {
        $version = trim((string) $value);
        if (preg_match('/\Av(\d+)\.0\z/', $version, $matches) !== 1) {
            return null;
        }

        $major = (int) $matches[1];

        return $major >= self::MIN_SUPPORTED_MAJOR && $major <= self::CURRENT_MAJOR
            ? $version
            : null;
    }
}
