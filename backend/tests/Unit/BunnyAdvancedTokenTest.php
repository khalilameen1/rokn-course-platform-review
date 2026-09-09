<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BunnyService;
use Tests\TestCase;

final class BunnyAdvancedTokenTest extends TestCase
{
    public function test_private_assets_use_storage_delivery_credentials_not_stream_credentials(): void
    {
        config([
            'bunny.cdn_hostname' => 'stream.example.test',
            'bunny.token_auth_key' => 'stream-key',
            'bunny.storage_cdn_hostname' => 'assets.example.test',
            'bunny.storage_token_auth_key' => 'asset-key',
        ]);

        $url = app(BunnyService::class)->generateBunnySignedUrl('portfolio/work.jpg', 600);
        self::assertNotNull($url);
        $parts = parse_url($url);
        self::assertSame('assets.example.test', $parts['host'] ?? null);
        parse_str((string) ($parts['query'] ?? ''), $query);
        self::assertSame(
            BunnyService::advancedToken('asset-key', '/portfolio/work.jpg', (int) $query['expires']),
            $query['token'] ?? null
        );
        self::assertNotSame(
            BunnyService::advancedToken('stream-key', '/portfolio/work.jpg', (int) $query['expires']),
            $query['token'] ?? null
        );
    }

    public function test_it_matches_a_fixed_bunny_hmac_sha256_base64url_vector(): void
    {
        self::assertSame(
            'HS256-vH4aaxTlWZY_4-uPpjwhVD6ryUXM1bAM2PuUroQCqQ4',
            BunnyService::advancedToken(
                'test-key',
                '/videos/abc/playlist.m3u8',
                1700000000
            )
        );
    }

    public function test_signing_data_is_part_of_the_authenticated_payload(): void
    {
        self::assertSame(
            'HS256-YhgaSnvsPCzMxXwmeruern6Gl9CbCeuwcvKyLm9Hlnk',
            BunnyService::advancedToken(
                'test-key',
                '/videos/abc/',
                1700000000,
                'token_path=/videos/abc/'
            )
        );
    }
}
