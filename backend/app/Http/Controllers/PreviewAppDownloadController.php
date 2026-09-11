<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

final class PreviewAppDownloadController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $path = trim((string) config('app_downloads.android_preview_path'));
        abort_unless($path !== '' && str_ends_with($path, '.apk'), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        // Object storage serves the file and byte ranges, not a PHP worker.
        return redirect()->away($disk->url($path), 302, ['Cache-Control' => 'no-store']);
    }
}
