<?php

namespace Tests\Feature;

use Tests\TestCase;

class VersionedAssetTest extends TestCase
{
    public function test_deployed_asset_changes_invalidate_its_url(): void
    {
        $file = tempnam(public_path(), 'asset-test-');
        $this->assertNotFalse($file);
        $name = basename($file);

        try {
            touch($file, 1700000000);
            clearstatcache(true, $file);
            $first = versioned_asset($name);
            $this->assertSame(asset($name).'?v=1700000000', $first);

            touch($file, 1700000001);
            clearstatcache(true, $file);
            $this->assertNotSame($first, versioned_asset($name));
        } finally {
            unlink($file);
        }
    }

    public function test_a_missing_asset_does_not_turn_a_page_into_a_server_error(): void
    {
        $path = 'images/missing-'.bin2hex(random_bytes(8)).'.png';
        $this->assertSame(asset($path), versioned_asset($path));
    }
}
