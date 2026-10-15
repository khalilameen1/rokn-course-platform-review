<?php

declare(strict_types=1);

// Serve the actual offline Blade renders and existing public assets only.
// Run after render-landing-preview.php with:
// php -S 127.0.0.1:4178 -t public scripts/serve-landing-preview.php
if (PHP_SAPI !== 'cli-server') {
    fwrite(STDERR, "Use this router with the PHP development server.\n");
    exit(1);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pages = [
    '/' => 'index', '/about' => 'about', '/contact' => 'contact',
    '/privacy-policy' => 'privacy', '/terms' => 'terms', '/returns-policy' => 'returns',
];

if (isset($pages[$path])) {
    $locale = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
    $render = __DIR__.'/../storage/app/landing-preview/'.$pages[$path].'-'.$locale.'.html';
    if (is_file($render)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($render);
        return true;
    }
}

$public = realpath(__DIR__.'/../public');
$asset = realpath($public.'/'.ltrim((string) $path, '/'));
if ($asset && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset)
    && strtolower(pathinfo($asset, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}

http_response_code(404);
echo 'Preview not found';
return true;
