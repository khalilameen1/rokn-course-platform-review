<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private ?string $isolatedStoragePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Never let a test depend on, or write into, the checkout's runtime
        // storage tree. Besides keeping the suite hermetic, this makes tests
        // behave identically in read-only CI workspaces and local machines.
        $this->isolatedStoragePath = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'rokn-phpunit-'
            .bin2hex(random_bytes(8));

        $this->app->useStoragePath($this->isolatedStoragePath);
        config([
            'view.compiled' => $this->isolatedStoragePath.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'session.files' => $this->isolatedStoragePath.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
            'cache.stores.file.path' => $this->isolatedStoragePath.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache',
            'filesystems.disks.local.root' => $this->isolatedStoragePath.DIRECTORY_SEPARATOR.'app',
            'filesystems.disks.public.root' => $this->isolatedStoragePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public',
        ]);

        File::ensureDirectoryExists((string) config('view.compiled'));
    }

    protected function tearDown(): void
    {
        $storagePath = $this->isolatedStoragePath;

        parent::tearDown();

        if ($storagePath !== null && is_dir($storagePath)) {
            File::deleteDirectory($storagePath);
        }
    }
}
