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

// Proposed 600-coin welcome offer requested on 2026-10-15; this fixture does
// not change the production reward. The live page reads the actual grant rule.
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
        'downloadChannels' => ['play' => 'https://play.google.com/store/apps/details?id=com.rokn'],
        'welcomeCoins' => 600,
        'howPlatformWorksVideoUrl' => null,
    ];

    File::put($directory.'/index-'.$locale.'.html', view('landing.index', $data)->render());
    File::put($directory.'/contact-'.$locale.'.html', view('static.contact', $data + [
        'publicSettings' => ['support_contacts' => ['email' => 'support@rokn.app']],
    ])->render());
    foreach (['about', 'privacy', 'terms', 'returns'] as $page) {
        File::put($directory.'/'.$page.'-'.$locale.'.html', view('static.'.$page, $data)->render());
    }
}

fwrite(STDOUT, "Rendered Arabic and English landing previews to storage/app/landing-preview.\n");
