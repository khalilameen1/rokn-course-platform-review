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

// Render the actual Blade page without querying accounts, payment services or
// production settings. The badges deliberately have no prelaunch destinations.
URL::forceRootUrl('http://127.0.0.1:4178');
URL::forceScheme('http');
$directory = storage_path('app/landing-preview');
File::ensureDirectoryExists($directory);

foreach (['ar', 'en'] as $locale) {
    $app->setLocale($locale);
    $html = view('landing.index', [
        'setting' => null,
        'designSetting' => new DesignSetting(['name_ar' => 'ركن', 'name_en' => 'Rokn']),
        'locale' => $locale,
        'downloadChannels' => [],
        'directDiscountPercent' => 0,
        'howPlatformWorksVideoUrl' => null,
    ])->render();

    File::put($directory.'/index-'.$locale.'.html', $html);
}

fwrite(STDOUT, "Rendered Arabic and English landing previews to storage/app/landing-preview.\n");
