<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PreviewAppDownloadTest extends TestCase
{
    public function test_preview_download_is_disabled_without_an_explicit_file(): void
    {
        config(['app_downloads.android_preview_path' => null]);

        $this->get('/downloads/rokn-preview.apk')->assertNotFound();
    }

    public function test_missing_preview_file_does_not_redirect_to_a_broken_download(): void
    {
        Storage::fake('public');
        config(['app_downloads.android_preview_path' => 'downloads/internal/rokn-1.0.54.apk']);

        $this->get('/downloads/rokn-preview.apk')->assertNotFound();
    }

    public function test_preview_redirects_to_the_configured_object_and_ignores_request_paths(): void
    {
        $disk = Storage::fake('public');
        $path = 'downloads/internal/rokn-1.0.54.apk';
        $disk->put($path, 'apk-fixture');
        config(['app_downloads.android_preview_path' => $path]);

        $response = $this->get('/downloads/rokn-preview.apk?path=private.txt');

        $response->assertRedirect($disk->url($path));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_download_button_identifies_the_test_build_without_changing_store_badges(): void
    {
        app()->setLocale('ar');
        $html = view('landing.partials.download-buttons', [
            'downloadChannels' => ['direct' => route('app-download.preview')],
            'directDownloadIsPreview' => true,
        ])->render();

        self::assertStringContainsString('نسخة تجريبية', $html);
        self::assertStringContainsString(route('app-download.preview'), $html);
        self::assertSame(2, substr_count($html, 'class="store-btn" aria-disabled="true"'));
    }
}
