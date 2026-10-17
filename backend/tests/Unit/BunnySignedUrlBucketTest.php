<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BunnyDeliveryService;
use Tests\TestCase;

/** Approved public portfolio media reuse one signed URL per time bucket so browsers and the CDN can cache it. */
final class BunnySignedUrlBucketTest extends TestCase
{
    public function test_expiry_is_identical_within_a_bucket_and_valid_past_its_end(): void
    {
        $start = 1_800_000_000 - (1_800_000_000 % 3600);

        self::assertSame($start + 3600 + 600, BunnyDeliveryService::expiry(600, 3600, $start));
        self::assertSame($start + 3600 + 600, BunnyDeliveryService::expiry(600, 3600, $start + 1));
        self::assertSame($start + 3600 + 600, BunnyDeliveryService::expiry(600, 3600, $start + 3599));
        self::assertSame($start + 7200 + 600, BunnyDeliveryService::expiry(600, 3600, $start + 3600));
        self::assertSame($start + 3600, BunnyDeliveryService::bucketEnd(3600, $start + 42));
        // Without a bucket the previous per-request expiry is unchanged.
        self::assertSame($start + 42 + 300, BunnyDeliveryService::expiry(300, 0, $start + 42));
    }

    public function test_storage_url_is_stable_within_the_bucket(): void
    {
        config([
            'bunny.storage_cdn_hostname' => 'assets.example.test',
            'bunny.storage_token_auth_key' => 'asset-key',
        ]);
        $delivery = app(BunnyDeliveryService::class);

        $first = $delivery->storageUrl('portfolio/work.jpg', 600, 3600);
        $second = $delivery->storageUrl('portfolio/work.jpg', 600, 3600);
        self::assertNotNull($first);
        self::assertSame($first, $second);
        parse_str((string) parse_url((string) $first, PHP_URL_QUERY), $query);
        self::assertSame(BunnyDeliveryService::bucketEnd(3600) + 600, (int) $query['expires']);
        self::assertSame(
            BunnyDeliveryService::advancedToken('asset-key', '/portfolio/work.jpg', (int) $query['expires']),
            $query['token']
        );
    }
}
