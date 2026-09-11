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
        // Keep the stable public link while changing the object URL whenever a
        // replacement APK is uploaded at the same path. This prevents a phone
        // or CDN from receiving an older package after a test build is updated.
        $version = hash('sha256', implode(':', [
            $path,
            (string) $disk->size($path),
            (string) $disk->lastModified($path),
        ]));
        $url = $disk->url($path);
        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->away("{$url}{$separator}v={$version}", 302, ['Cache-Control' => 'no-store']);
    }
}
