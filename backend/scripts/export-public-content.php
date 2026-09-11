<?php

declare(strict_types=1);

// The app's offline copy is generated from the same documents served by the API.
$root = dirname(__DIR__, 2);
$pages = [];
foreach (['about', 'privacy', 'terms', 'returns'] as $page) {
    $pages[$page] = require $root.'/backend/resources/lang/ar/'.$page.'.php';
}
$json = json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$destination = $root.'/mobile/src/content/publicPages.ar.json';

if (in_array('--check', $argv, true)) {
    if (!is_file($destination) || file_get_contents($destination) !== $json) {
        fwrite(STDERR, "Refresh the app's public documents with php backend/scripts/export-public-content.php\n");
        exit(1);
    }
    fwrite(STDOUT, "Website and bundled app documents match.\n");
    exit(0);
}

if (!is_dir(dirname($destination))) {
    mkdir(dirname($destination), 0775, true);
}
file_put_contents($destination, $json);
fwrite(STDOUT, "Exported the shared Arabic public documents.\n");
