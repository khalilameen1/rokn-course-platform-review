<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SafeExternalUrl;

/**
 * Learner-owned profile links shown as contact buttons on the public
 * portfolio. WhatsApp uses WhatsApp's documented click-to-chat format
 * (https://wa.me/<number>), so every WhatsApp link is normalized to it.
 */
final class PortfolioContactLink
{
    private const TYPES = [
        'whatsapp' => ['wa.me'],
        'behance' => ['behance.net'],
        'dribbble' => ['dribbble.com'],
        'instagram' => ['instagram.com'],
        'linkedin' => ['linkedin.com'],
        'facebook' => ['facebook.com', 'fb.com'],
        'x' => ['x.com', 'twitter.com'],
        'tiktok' => ['tiktok.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'github' => ['github.com'],
    ];

    public const LABELS = [
        'whatsapp' => 'واتساب',
        'behance' => 'Behance',
        'dribbble' => 'Dribbble',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'facebook' => 'Facebook',
        'x' => 'X',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'github' => 'GitHub',
        'website' => 'الموقع',
    ];

    /** Secure HTTPS URL; WhatsApp links become wa.me or are refused (null). */
    public static function normalize(mixed $url): ?string
    {
        $safe = SafeExternalUrl::sanitize($url);
        if ($safe === null) {
            return null;
        }
        $parts = parse_url($safe);
        $host = self::host($parts);
        if (!self::isWhatsAppHost($host)) {
            return $safe;
        }

        $digits = null;
        if ($host === 'wa.me') {
            $digits = trim((string) ($parts['path'] ?? ''), '/');
        } elseif (in_array($host, ['api.whatsapp.com', 'whatsapp.com', 'web.whatsapp.com'], true)
            && rtrim((string) ($parts['path'] ?? ''), '/') === '/send') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $digits = is_string($query['phone'] ?? null) ? $query['phone'] : null;
        }
        $digits = preg_replace('/[\s+\-()]/', '', (string) $digits);

        return preg_match('/^[1-9][0-9]{7,14}$/', (string) $digits) === 1 ? 'https://wa.me/' . $digits : null;
    }

    public static function isWhatsApp(mixed $url): bool
    {
        $safe = SafeExternalUrl::sanitize($url);

        return $safe !== null && self::isWhatsAppHost(self::host(parse_url($safe)));
    }

    public static function type(string $url): string
    {
        $host = self::host(parse_url($url));
        foreach (self::TYPES as $type => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return $type;
                }
            }
        }

        return 'website';
    }

    private static function isWhatsAppHost(string $host): bool
    {
        return in_array($host, ['wa.me', 'wa.link'], true)
            || $host === 'whatsapp.com' || str_ends_with($host, '.whatsapp.com');
    }

    private static function host(mixed $parts): string
    {
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
