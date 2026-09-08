<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SafeExternalUrl;

final class CourseAttachmentExternalUrl
{
    /**
     * Convert known file-sharing links without fetching remote content.
     * Download clients must still distinguish a file from a host's login or confirmation page.
     */
    public static function normalize(string $value): ?string
    {
        $url = SafeExternalUrl::sanitize($value);
        if ($url === null || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        if ($host === 'drive.google.com') {
            parse_str($parts['query'] ?? '', $query);
            $id = null;
            if (preg_match('~^/file/d/([a-zA-Z0-9_-]+)(?:/(?:view|preview|edit))?/?$~D', $path, $match)) {
                $id = $match[1];
            } elseif ($path === '/open' && is_string($query['id'] ?? null)) {
                $id = $query['id'];
            }

            if ($id !== null && preg_match('/^[a-zA-Z0-9_-]+$/D', $id)) {
                $download = ['export' => 'download', 'id' => $id];
                // Some public files require this key even when the uploader can open them without it.
                if (is_string($query['resourcekey'] ?? null) && $query['resourcekey'] !== '') {
                    $download['resourcekey'] = $query['resourcekey'];
                }

                return 'https://drive.google.com/uc?'.http_build_query($download, '', '&', PHP_QUERY_RFC3986);
            }
        }

        if (in_array($host, ['dropbox.com', 'www.dropbox.com'], true)
            && preg_match('~^/(?:s/|scl/fi/)~', $path)) {
            // Preserve access tokens and all unrelated query bytes exactly as supplied.
            $query = array_values(array_filter(
                explode('&', $parts['query'] ?? ''),
                static fn (string $part): bool => $part !== ''
                    && !in_array(rawurldecode(explode('=', $part, 2)[0]), ['dl', 'raw'], true)
            ));
            $query[] = 'dl=1';
            $base = explode('?', explode('#', $url, 2)[0], 2)[0];

            return $base.'?'.implode('&', $query);
        }

        // In particular, do not rebuild signed storage URLs or Google-generated download URLs.
        return $url;
    }
}
