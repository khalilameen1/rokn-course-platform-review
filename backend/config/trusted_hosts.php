<?php

declare(strict_types=1);

$configuredHosts = trim((string) env('APP_TRUSTED_HOSTS', ''));
if ($configuredHosts === '') {
    // The shipped mobile client currently talks to the Laravel Cloud origin
    // directly while branded web/app links use rokn.app. Both are first-party
    // production entry points during the domain cut-over.
    $configuredHosts = 'rokn.app,www.rokn.app,rokn-course-platform-review-production-b7gpy1.laravel.cloud';
}

return [
    // Exact public hosts only, comma separated. Wildcards and URLs are not
    // accepted; the middleware escapes every value before building a pattern.
    'hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', $configuredHosts)
    ))),
];
