<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class PublicDiskUrl
{
    public static function from(?string $storedPath): ?string
    {
        $storedPath = trim((string) $storedPath);
        if ($storedPath === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $storedPath) === 1 || str_starts_with($storedPath, '//')) {
            return str_starts_with(strtolower($storedPath), 'https://')
                ? $storedPath
                : null;
        }

        $path = ltrim(str_replace('\\', '/', $storedPath), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Storage::disk('public')->url(ltrim($path, '/'));
    }

    /** Resolve only URLs owned by the configured public disk back to a key. */
    public static function pathFrom(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) !== 1 && ! str_starts_with($value, '//')) {
            return self::normalisedPath(self::urlPath($value));
        }

        $probe = '__rokn_public_path_probe__';
        $probeUrl = Storage::disk('public')->url($probe);
        $probeParts = self::urlParts($probeUrl);
        $valueParts = self::urlParts($value, (string) ($probeParts['scheme'] ?? 'https'));
        if ($probeParts === null || $valueParts === null) {
            return null;
        }
        if (! self::sameOrigin($probeParts, $valueParts)) {
            return null;
        }

        $probePath = self::decodedPath((string) ($probeParts['path'] ?? ''));
        $valuePath = self::decodedPath((string) ($valueParts['path'] ?? ''));
        if ($probePath === null || $valuePath === null) {
            return null;
        }

        $probeSuffix = '/'.$probe;
        if (! str_ends_with($probePath, $probeSuffix)) {
            return null;
        }
        $ownedPrefix = substr($probePath, 0, -strlen($probe));
        if (! str_starts_with($valuePath, $ownedPrefix)) {
            return null;
        }

        return self::normalisedDecodedPath(substr($valuePath, strlen($ownedPrefix)));
    }

    /** @return array<string,mixed>|null */
    private static function urlParts(string $url, string $fallbackScheme = ''): ?array
    {
        if (str_starts_with($url, '//')) {
            $url = ($fallbackScheme !== '' ? $fallbackScheme : 'https').':'.$url;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && filled($parts['host'] ?? null)
                ? $parts
                : null;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameOrigin(array $left, array $right): bool
    {
        $scheme = static fn (array $parts): string => strtolower((string) ($parts['scheme'] ?? ''));
        $port = static function (array $parts): int {
            if (isset($parts['port'])) {
                return (int) $parts['port'];
            }

            return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
        };

        return $scheme($left) === $scheme($right)
            && strtolower((string) $left['host']) === strtolower((string) $right['host'])
            && $port($left) === $port($right);
    }

    private static function urlPath(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    private static function decodedPath(string $value): ?string
    {
        if (preg_match('/%(?![0-9a-f]{2})/i', $value)) {
            return null;
        }
        $decoded = rawurldecode($value);

        return preg_match('/[\x00-\x1F\x7F]/', $decoded) ? null : $decoded;
    }

    private static function normalisedPath(string $value): ?string
    {
        $decoded = self::decodedPath($value);
        if ($decoded === null) {
            return null;
        }

        return self::normalisedDecodedPath($decoded);
    }

    private static function normalisedDecodedPath(string $decoded): ?string
    {
        $path = ltrim(str_replace('\\', '/', $decoded), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if ($path === '' || preg_match('/^[A-Za-z]:\//', $path)) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }
}
