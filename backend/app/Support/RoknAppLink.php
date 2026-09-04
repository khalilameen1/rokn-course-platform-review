<?php

declare(strict_types=1);

namespace App\Support;

final class RoknAppLink
{
    private const TRUSTED_WEB_HOSTS = [
        'rokn.app',
        'www.rokn.app',
        // Kept only for links that production already delivered. This host is
        // intentionally absent from Android/iOS association claims.
        'rokn-course-platform-review-production-b7gpy1.laravel.cloud',
    ];

    public static function normalize(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $raw)) {
            return null;
        }

        if (preg_match('#^https?://#i', $raw)) {
            $parts = parse_url($raw);
            if (!is_array($parts)) {
                return null;
            }
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (
                strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || !in_array($host, self::TRUSTED_WEB_HOSTS, true)
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['port'])
            ) {
                return null;
            }
            $path = ltrim((string) ($parts['path'] ?? ''), '/');
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
            if (!str_starts_with(strtolower($raw), 'rokn://')) {
                return null;
            }
            $path = preg_split('/[?#]/', substr($raw, strlen('rokn://')), 2)[0] ?? '';
            $path = ltrim($path, '/');
        } else {
            if (str_starts_with($raw, '//')) {
                return null;
            }
            $path = preg_split('/[?#]/', $raw, 2)[0] ?? '';
            $path = ltrim($path, '/');
        }

        $path = rtrim($path, '/');
        if (preg_match('#\A(home|wallet|profile)\z#i', $path, $match)) {
            return 'rokn://' . strtolower($match[1]);
        }
        if (preg_match('#\Aprofile/(portfolio|certificates|saved)\z#i', $path, $match)) {
            return 'rokn://profile/' . strtolower($match[1]);
        }

        if (preg_match('#\Asupport/([0-9A-HJKMNP-TV-Z]{26})\z#i', $path, $match)) {
            return 'rokn://support/' . strtoupper($match[1]);
        }

        if (!preg_match(
            '#\Acourses?/([1-9][0-9]{0,17})(?:(/watch)(?:/([1-9][0-9]{0,17}))?|/lesson/([1-9][0-9]{0,17})|/project/([1-9][0-9]{0,17}))?\z#i',
            $path,
            $match
        )) {
            return null;
        }

        $courseId = $match[1];
        if (($match[5] ?? '') !== '') {
            return "rokn://course/{$courseId}/project/{$match[5]}";
        }
        if (($match[4] ?? '') !== '') {
            return "rokn://course/{$courseId}/lesson/{$match[4]}";
        }
        if (($match[2] ?? '') !== '') {
            return "rokn://course/{$courseId}/watch"
                . (($match[3] ?? '') !== '' ? "/{$match[3]}" : '');
        }

        return "rokn://course/{$courseId}";
    }

    public static function course(int $courseId, bool $watch = false): ?string
    {
        if ($courseId <= 0) {
            return null;
        }

        return "rokn://course/{$courseId}" . ($watch ? '/watch' : '');
    }

    public static function project(int $courseId, int $projectId): ?string
    {
        return $courseId > 0 && $projectId > 0
            ? "rokn://course/{$courseId}/project/{$projectId}"
            : null;
    }
}
