<?php

declare(strict_types=1);

namespace App\Support;

final class RoknPublicUrl
{
    public static function certificate(string $publicId): string
    {
        return self::base() . '/c/' . rawurlencode($publicId);
    }

    public static function certificateArtifact(string $publicId): string
    {
        return self::certificate($publicId) . '/artifact';
    }

    public static function certificatePdf(string $publicId): string
    {
        return self::certificate($publicId) . '/download';
    }

    public static function portfolio(string $slug): string
    {
        return self::base() . '/@' . rawurlencode($slug);
    }

    public static function portfolioMedia(string $slug, string $mediaPublicId): string
    {
        return self::portfolio($slug) . '/media/' . rawurlencode($mediaPublicId);
    }

    public static function course(int $courseId): string
    {
        return self::base() . '/course/' . $courseId;
    }

    private static function base(): string
    {
        $configured = rtrim(trim((string) config('public_links.base_url')), '/');
        $parts = parse_url($configured);
        if (
            !is_array($parts)
            ||
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || trim((string) ($parts['path'] ?? ''), '/') !== ''
        ) {
            return 'https://rokn.app';
        }

        return $configured;
    }
}
