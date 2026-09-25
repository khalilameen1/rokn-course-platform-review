<?php

declare(strict_types=1);

namespace App\Support;

/** One object-path policy for uploads, cleanup and private asset delivery. */
final class BunnyStoragePath
{
    public static function normalize(string $value, ?string $expectedHost): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\')) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);
            if (
                ($parts['scheme'] ?? null) !== 'https'
                || !$expectedHost
                || strtolower((string) ($parts['host'] ?? '')) !== $expectedHost
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
            ) {
                return null;
            }
            $value = (string) ($parts['path'] ?? '');
        }

        $path = ltrim(rawurldecode($value), '/');

        return preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9][A-Za-z0-9._-]*$#', $path) === 1
            ? $path
            : null;
    }
}
