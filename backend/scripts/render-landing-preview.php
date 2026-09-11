<?php

declare(strict_types=1);

use App\Models\DesignSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment(['local', 'testing'])) {
    fwrite(STDERR, "Landing preview rendering is only available in local/testing environments.\n");
    exit(1);
}

// Public offer snapshot checked against /api/v1/auth-methods on 2026-09-11.
// Production resolves this value through the same rule as actual crediting.
// No accounts or payment services are contacted by this preview renderer.
URL::forceRootUrl('http://127.0.0.1:4178');
URL::forceScheme('http');
$directory = storage_path('app/landing-preview');
File::ensureDirectoryExists($directory);

foreach (['ar', 'en'] as $locale) {
    $app->setLocale($locale);
    $data = [
        'setting' => null,
        'designSetting' => new DesignSetting(['name_ar' => 'ركن', 'name_en' => 'Rokn']),
        'locale' => $locale,
        // Loopback-only download of the owner's existing internal test build.
        'downloadChannels' => ['direct' => 'http://127.0.0.1:4178/downloads/rokn-internal-test.apk'],
        'welcomeCoins' => 20,
        'directDiscountPercent' => 10,
        'howPlatformWorksVideoUrl' => null,
    ];

    File::put($directory.'/index-'.$locale.'.html', view('landing.index', $data)->render());
    File::put($directory.'/contact-'.$locale.'.html', view('static.contact', $data + [
        'publicSettings' => ['support_contacts' => ['email' => 'support@rokn.app']],
    ])->render());
}

fwrite(STDOUT, "Rendered Arabic and English landing previews to storage/app/landing-preview.\n");
