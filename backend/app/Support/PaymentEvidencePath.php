<?php

declare(strict_types=1);

namespace App\Support;

final class PaymentEvidencePath
{
    public static function from(mixed $value): ?string
    {
        $path = trim((string) $value);
        if (
            $path === ''
            || preg_match('#^https?://#i', $path) === 1
            || str_starts_with($path, '//')
        ) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '//')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
            || preg_match('#^[A-Za-z]:/#', $path) === 1
            || ! str_starts_with($path, 'payment-evidence/')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $path) !== 1
        ) {
            return null;
        }

        return $path;
    }

    public static function isLegacyPublicReference(mixed $value): bool
    {
        $value = trim((string) $value);

        return preg_match('#^https?://#i', $value) === 1
            || str_starts_with($value, '//')
            || str_starts_with(ltrim(str_replace('\\', '/', $value), '/'), 'storage/');
    }
}
